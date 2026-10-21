<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Audit\AuditLogger;
use App\Casts\Bytea;
use App\Jobs\CommitImport;
use App\Models\ImportMappingPreset;
use App\Models\ImportRun;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The stages of the CSV import pipeline (DB-003, ROUND2-SPEC §4.1/§4.6) for any kind with an ImportHandler:
 *
 *   upload (sealed at once) → map (+ preset) → preview / dry-run (counts only) → commit (CommitImport job) | discard
 *
 * Every stage re-checks the person's role and the kind's gate (decision D6 — even the upload is refused below the
 * gate, a staged CSV already is personal data). Runs are resumable for 24 h. Audit rows carry counts only — never cell
 * values and never the file name (a file name can contain a person's name). Must run in the building's context.
 */
final class ImportRunService
{
    /** @var array<string, array{0: string, 1: CsvTable}> run id => [updated_at, table] (per request) */
    private array $tables = [];

    public function __construct(
        private readonly CsvReader $reader,
        private readonly ColumnMapper $mapper,
        private readonly RowValidator $validator,
        private readonly ImportVault $vault,
    ) {}

    /**
     * @throws ImportRefused unknown_kind
     */
    public function handler(string $kind): ImportHandler
    {
        $class = config('majas.import.handlers.'.$kind);

        if (! is_string($class) || ! class_exists($class)) {
            throw new ImportRefused('unknown_kind');
        }

        $handler = app($class);
        if (! $handler instanceof ImportHandler || $handler->kind() !== $kind) {
            throw new ImportRefused('unknown_kind');
        }

        return $handler;
    }

    /**
     * Role + gate check for every stage.
     *
     * @throws ImportRefused forbidden | gate_closed | unknown_kind
     */
    public function assertAllowed(User $user, string $kind): ImportHandler
    {
        $handler = $this->handler($kind);

        if ($user->isTombstone() || ! $handler->mayImport($user)) {
            throw new ImportRefused('forbidden');
        }

        $missing = $handler->closedGate();
        if ($missing !== null) {
            throw new ImportRefused('gate_closed', $missing);
        }

        return $handler;
    }

    /**
     * Stage 1 from an uploaded file (Livewire temporary upload): type / size checks, then upload(). The caller deletes
     * the temporary file in the same request.
     *
     * @throws ImportRefused
     */
    public function uploadFile(User $user, string $kind, string $path, string $originalName): ImportRun
    {
        $this->assertAllowed($user, $kind);
        $this->reader->assertUpload($path, $originalName);

        return $this->upload($user, $kind, (string) file_get_contents($path));
    }

    /**
     * Stage 1: parse (caps, encoding, delimiter), seal the bytes, detect the header row and suggest a mapping.
     *
     * @throws ImportRefused
     */
    public function upload(User $user, string $kind, string $bytes): ImportRun
    {
        $handler = $this->assertAllowed($user, $kind);

        $today = ImportRun::query()->ofKind($kind)->where('created_at', '>', now()->subDay())->count();
        if ($today >= (int) config('majas.import.per_day')) {
            throw new ImportRefused('daily_cap');
        }

        $table = $this->reader->parse($bytes);
        $hasHeader = $this->mapper->looksLikeHeader($handler, $table->firstRow());
        $id = strtolower((string) Str::ulid());
        $buildingId = CurrentBuilding::id();

        $run = ImportRun::query()->create([
            'id' => $id,
            'kind' => $kind,
            'status' => ImportRun::UPLOADED,
            'created_by' => $user->id,
            'file_sha256' => hash('sha256', $bytes, true),
            'encoding' => $table->encoding,
            'delimiter' => $table->delimiter,
            'has_header' => $hasHeader,
            'row_count' => count($table->dataRows($hasHeader)),
            'mapping' => $this->suggestedMapping($handler, $table, $hasHeader),
            'options' => ['mode' => ImportContext::CREATE_ONLY],
            'payload_ciphertext' => $this->vault->seal($bytes, $handler->vaultKey(), $buildingId, $id, $kind),
            'summary' => [],
            'expires_at' => now()->addHours((int) config('majas.import.resume_hours')),
        ]);

        AuditLogger::record('import.upload', $run, data: ['kind' => $kind, 'rows' => $run->row_count], actorUserId: (int) $user->id);

        return $run;
    }

    /**
     * An open run of this kind in the current building (404 otherwise), after the role + gate check.
     *
     * @throws ImportRefused
     */
    public function find(User $user, string $kind, string $id): ImportRun
    {
        $this->assertAllowed($user, $kind);

        $run = ImportRun::query()->ofKind($kind)->whereKey($id)->first();
        if ($run === null) {
            throw new ImportRefused('run_expired');
        }

        return $run;
    }

    /**
     * Change encoding / delimiter / header toggle (stage 2). Re-parses the sealed bytes and re-suggests the mapping.
     *
     * @throws ImportRefused
     */
    public function reformat(User $user, ImportRun $run, ?string $encoding, ?string $delimiter, ?bool $hasHeader): ImportRun
    {
        $handler = $this->assertOpen($user, $run);

        $table = $this->reader->parse($this->payload($run, $handler), $encoding ?? $run->encoding, $delimiter ?? $run->delimiter);
        $hasHeader ??= $run->has_header;

        $run->forceFill([
            'status' => ImportRun::UPLOADED,
            'encoding' => $table->encoding,
            'delimiter' => $table->delimiter,
            'has_header' => $hasHeader,
            'row_count' => count($table->dataRows($hasHeader)),
            'mapping' => $this->suggestedMapping($handler, $table, $hasHeader),
            'summary' => [],
        ])->save();
        unset($this->tables[$run->id]);

        return $run;
    }

    /**
     * Stage 2: confirm the mapping (+ mode); remembered per header layout.
     *
     * @param  array<int|string, string|null>  $mapping  column index => field key ('' / null = not imported)
     *
     * @throws ImportRefused mapping_required | mapping_duplicate | mapping_invalid
     */
    public function map(User $user, ImportRun $run, array $mapping, string $mode): ImportRun
    {
        $handler = $this->assertOpen($user, $run);
        $table = $this->table($run);

        $clean = [];
        foreach ($mapping as $index => $field) {
            if (is_string($field) && $field !== '') {
                $clean[(string) $index] = $field;
            }
        }
        ksort($clean, SORT_NUMERIC);

        $problems = $this->mapper->problems($handler, $clean, $table->columnCount());
        if ($problems !== []) {
            throw new ImportRefused($problems[0]);
        }

        $mode = in_array($mode, [ImportContext::CREATE_ONLY, ImportContext::CREATE_OR_UPDATE], true) ? $mode : ImportContext::CREATE_ONLY;
        $run->forceFill([
            'status' => ImportRun::MAPPED,
            'mapping' => $clean,
            'options' => ['mode' => $mode],
            'summary' => [],
        ])->save();

        if ($run->has_header) {
            $signature = ColumnMapper::signature($table->firstRow());
            ImportMappingPreset::query()->where('kind', $run->kind)->where('header_signature', Bytea::literal($signature))->delete();
            ImportMappingPreset::query()->create([
                'kind' => $run->kind,
                'header_signature' => $signature,
                'mapping' => $clean,
                'updated_by' => $user->id,
                'updated_at' => now(),
            ]);
        }

        return $run;
    }

    /**
     * Stage 3: one page of validated rows (masked preview cells, codes). Re-validates against the database.
     *
     * @return array{rows: list<RowResult>, total: int, pages: int, page: int}
     *
     * @throws ImportRefused
     */
    public function preview(User $user, ImportRun $run, int $page = 1, bool $onlyErrors = false): array
    {
        $handler = $this->assertOpen($user, $run, [ImportRun::MAPPED, ImportRun::VALIDATED]);
        $results = $this->validateAll($handler, $run);
        $severity = RowValidator::severity($handler);

        if ($onlyErrors) {
            $results = array_values(array_filter($results, static fn (RowResult $r): bool => $r->status($severity) !== RowResult::OK));
        }

        $per = (int) config('majas.import.preview_page');
        $pages = max(1, (int) ceil(count($results) / $per));
        $page = min(max(1, $page), $pages);

        return ['rows' => array_slice($results, ($page - 1) * $per, $per), 'total' => count($results), 'pages' => $pages, 'page' => $page];
    }

    /**
     * Stage 4: dry-run — exact counts against the current database state; writes import_runs.summary only.
     *
     * @throws ImportRefused
     */
    public function dryRun(User $user, ImportRun $run): ImportRun
    {
        $handler = $this->assertOpen($user, $run, [ImportRun::MAPPED, ImportRun::VALIDATED]);
        $results = $this->validateAll($handler, $run);

        $run->forceFill(['status' => ImportRun::VALIDATED, 'summary' => $this->summarize($handler, $results)])->save();

        return $run;
    }

    /**
     * Stage 5: start the commit — validated + a dry-run younger than 30 min + every confirmation ticked. The caller
     * (page) has already demanded the mfa step-up. One commit per building + kind at a time.
     *
     * @param  list<string>  $confirmed  confirmation keys the person ticked
     *
     * @throws ImportRefused wrong_status | dry_run_stale | confirm_missing | commit_running
     */
    public function commit(User $user, ImportRun $run, array $confirmed): ImportRun
    {
        $handler = $this->assertOpen($user, $run, [ImportRun::VALIDATED]);

        $dryRun = $run->dryRunAt();
        if ($dryRun === null || $dryRun->lt(now()->subMinutes((int) config('majas.import.dry_run_valid_minutes')))) {
            throw new ImportRefused('dry_run_stale');
        }

        if (array_diff($handler->confirmations(), $confirmed) !== []) {
            throw new ImportRefused('confirm_missing');
        }

        try {
            $updated = DB::transaction(fn (): int => ImportRun::query()->whereKey($run->id)->where('status', ImportRun::VALIDATED)->update([
                'status' => ImportRun::COMMITTING,
                'options' => json_encode(['mode' => $run->options['mode'] ?? ImportContext::CREATE_ONLY, 'committed_by' => (int) $user->id, 'confirmed' => array_values($confirmed)], JSON_THROW_ON_ERROR),
                'committed_counts' => json_encode(['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new ImportRefused('commit_running');
        }

        if ($updated !== 1) {
            throw new ImportRefused('wrong_status');
        }

        CommitImport::dispatch($run->id, CurrentBuilding::id());

        return $run->refresh();
    }

    /**
     * The commit job body (CommitImport, inside the building context). Idempotent: a run not in `committing` exits.
     * Per chunk of 100 rows: advisory transaction lock on import:{building}:{kind}, the handler's database checks run
     * again, then every row is written in its own savepoint through the domain services.
     */
    public function runCommit(string $runId): void
    {
        $run = ImportRun::query()->whereKey($runId)->first();
        if ($run === null || $run->status !== ImportRun::COMMITTING) {
            return;
        }

        $counts = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        try {
            $handler = $this->handler($run->kind);
            if ($handler->closedGate() !== null) {
                throw new ImportRefused('gate_closed');
            }
            $actor = User::query()->findOrFail((int) ($run->options['committed_by'] ?? 0));

            $context = $this->validator->context((string) ($run->options['mode'] ?? ImportContext::CREATE_ONLY));
            $pairs = $this->validator->check($handler, $this->table($run)->dataRows($run->has_header), $run->mapping, $context);
            $lockKey = 'import:'.$run->building_id.':'.$run->kind;

            foreach (array_chunk($pairs, max(1, (int) config('majas.import.chunk'))) as $chunk) {
                DB::transaction(function () use ($handler, $context, $chunk, $run, $actor, $lockKey, &$counts): void {
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$lockKey]);
                    $this->validator->validateBatch($handler, $context, $chunk);

                    foreach ($chunk as [, $result]) {
                        $counts['processed']++;
                        if ($result->action === 'skip') {
                            $counts['skipped']++;

                            continue;
                        }
                        try {
                            $outcome = DB::transaction(fn (): string => $handler->commit($result, $run, $actor));
                        } catch (Throwable) {
                            $outcome = 'skipped';
                        }
                        $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                    }

                    ImportRun::query()->whereKey($run->id)->update(['committed_counts' => json_encode($counts, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                });
            }

            $run->forceFill(['status' => ImportRun::COMMITTED, 'payload_ciphertext' => null, 'committed_counts' => $counts])->save();
            AuditLogger::record('import.commit', $run, data: [
                'kind' => $run->kind, 'rows' => $run->row_count,
                'created' => $counts['created'], 'updated' => $counts['updated'], 'skipped' => $counts['skipped'],
            ], actorUserId: (int) $actor->id);
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => ImportRun::FAILED,
                'payload_ciphertext' => null,
                'committed_counts' => $counts,
                'error_code' => $e instanceof ImportRefused ? $e->key : 'commit_failed',
            ])->save();
            // Never the original message: a database error can quote a value.
            report(new RuntimeException('Import commit failed ('.class_basename($e).').'));
        }
    }

    /**
     * @throws ImportRefused
     */
    public function discard(User $user, ImportRun $run): void
    {
        $this->assertAllowed($user, $run->kind);

        if (! in_array($run->status, ImportRun::OPEN, true)) {
            throw new ImportRefused('wrong_status');
        }

        $run->forceFill(['status' => ImportRun::DISCARDED, 'payload_ciphertext' => null])->save();
        unset($this->tables[$run->id]);
        AuditLogger::record('import.discard', $run, data: ['kind' => $run->kind, 'rows' => $run->row_count], actorUserId: (int) $user->id);
    }

    /**
     * The same file committed in this building within 24 h ("Šis fails jau importēts … — turpināt?").
     */
    public function previousImport(ImportRun $run): ?ImportRun
    {
        return ImportRun::query()->ofKind($run->kind)
            ->where('file_sha256', Bytea::literal($run->file_sha256))
            ->where('status', ImportRun::COMMITTED)
            ->where('updated_at', '>', now()->subDay())
            ->whereKeyNot($run->id)
            ->latest('updated_at')
            ->first();
    }

    /**
     * Column headers for the mapping step (the first row when it is a header, otherwise "Kolonna N").
     *
     * @return list<string>
     */
    public function headers(ImportRun $run): array
    {
        $table = $this->table($run);
        $count = $table->columnCount();
        $first = $table->firstRow();

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $run->has_header && trim((string) ($first[$i] ?? '')) !== '' ? (string) $first[$i] : __('import.mapping.column', ['n' => $i + 1]);
        }

        return $out;
    }

    /**
     * Masked samples of a column (first two data rows) to recognise a column without a header row.
     *
     * @return array<int, list<string>>
     */
    public function samples(ImportRun $run): array
    {
        $rows = array_slice($this->table($run)->dataRows($run->has_header), 0, 2);
        $out = [];
        foreach ($rows as $cells) {
            foreach ($cells as $i => $cell) {
                $out[$i][] = self::maskSample($cell);
            }
        }

        return $out;
    }

    /**
     * Generic mask for a sample cell: first character, bullets, last two characters of longer digits.
     */
    public static function maskSample(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '—';
        }
        if (mb_strlen($value) <= 3 && preg_match('/^\d+$/', $value) === 1) {
            return $value; // apartment numbers, stairwell numbers
        }

        return mb_substr($value, 0, 1).'•••'.(preg_match('/\d$/', $value) === 1 ? mb_substr($value, -2) : '');
    }

    public function table(ImportRun $run): CsvTable
    {
        $stamp = (string) $run->updated_at?->getTimestamp().$run->encoding.$run->delimiter;
        if (isset($this->tables[$run->id]) && $this->tables[$run->id][0] === $stamp) {
            return $this->tables[$run->id][1];
        }

        $table = $this->reader->parse($this->payload($run, $this->handler($run->kind)), $run->encoding, $run->delimiter);
        $this->tables[$run->id] = [$stamp, $table];

        return $table;
    }

    /**
     * @return list<RowResult>
     */
    private function validateAll(ImportHandler $handler, ImportRun $run): array
    {
        $context = $this->validator->context((string) ($run->options['mode'] ?? ImportContext::CREATE_ONLY));

        return $this->validator->validate($handler, $this->table($run)->dataRows($run->has_header), $run->mapping, $context);
    }

    /**
     * @param  list<RowResult>  $results
     * @return array<string, mixed>
     */
    private function summarize(ImportHandler $handler, array $results): array
    {
        $severity = RowValidator::severity($handler);
        $status = [RowResult::OK => 0, RowResult::WARNING => 0, RowResult::ERROR => 0];
        $actions = ['create' => 0, 'update' => 0, 'skip' => 0];
        $codes = [];

        foreach ($results as $r) {
            $status[$r->status($severity)]++;
            $actions[$r->action]++;
            foreach ($r->codes as $code) {
                $codes[$code] = ($codes[$code] ?? 0) + 1;
            }
        }
        ksort($codes);

        return [
            'dry_run_at' => now()->toIso8601String(),
            'rows' => count($results),
            'status' => $status,
            'actions' => $actions,
            'codes' => $codes,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function suggestedMapping(ImportHandler $handler, CsvTable $table, bool $hasHeader): array
    {
        if (! $hasHeader) {
            return [];
        }

        $preset = ImportMappingPreset::query()->where('kind', $handler->kind())
            ->where('header_signature', Bytea::literal(ColumnMapper::signature($table->firstRow())))
            ->first();

        if ($preset !== null && $this->mapper->problems($handler, $preset->mapping, $table->columnCount()) === []) {
            return $preset->mapping;
        }

        return $this->mapper->suggest($handler, $table->firstRow());
    }

    /**
     * @param  list<string>  $statuses
     *
     * @throws ImportRefused
     */
    private function assertOpen(User $user, ImportRun $run, array $statuses = ImportRun::OPEN): ImportHandler
    {
        $handler = $this->assertAllowed($user, $run->kind);

        if (! $run->isOpen()) {
            throw new ImportRefused('run_expired');
        }
        if (! in_array($run->status, $statuses, true)) {
            throw new ImportRefused('wrong_status');
        }

        return $handler;
    }

    private function payload(ImportRun $run, ImportHandler $handler): string
    {
        if ($run->payload_ciphertext === null) {
            throw new ImportRefused('run_expired');
        }

        return $this->vault->open($run->payload_ciphertext, $handler->vaultKey(), (int) $run->building_id, (string) $run->id, $run->kind);
    }
}
