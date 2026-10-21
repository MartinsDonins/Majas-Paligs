<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Audit\AuditLogger;
use App\Casts\Bytea;
use App\Enums\ImportKind;
use App\Enums\ImportStatus;
use App\Jobs\CommitImport;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Parking\PermitImportHandler;
use App\Tenancy\CurrentBuilding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The import pipeline (DB-003, ROUND2-SPEC §4.1/§4.6): Upload → Kartēšana → Pārbaude → Izmēģinājums → Imports, one
 * import_runs row per run, resumable for 24 h.
 *
 * - upload(): the kind's gate first (D6 — a staged CSV is already personal data), quota 10/day/building + kind, caps
 *   (CsvReader), sha256, header detection, preset / suggested mapping, payload sealed with ImportVault. Audit
 *   `import.upload` {kind, rows} — counts only, never a file name.
 * - map(), preview(): server-side from the sealed payload, masked cells, 50 rows per page.
 * - dryRun(): re-validates against the database, writes counts only (import_runs.summary).
 * - commit(): status `validated`, a dry-run younger than 30 min, the caller's confirmation; flips to `committing`
 *   (partial UNIQUE = one commit per building + kind) and dispatches CommitImport.
 * - runCommit() (job body): chunks of 100 under pg_advisory_xact_lock('import:'||building||':'||kind), every row
 *   re-validated inside its own savepoint and written through the handler's domain services; progress in
 *   committed_counts, so a second dispatch never re-processes a row. Payload nulled at the end. Audit
 *   `import.commit` {kind, rows, created, updated, skipped}.
 */
final class ImportRunService
{
    public const DAILY_UPLOADS = 10;

    public const TTL_HOURS = 24;

    public const DRY_RUN_VALID_MINUTES = 30;

    public const CHUNK = 100;

    public const PAGE_SIZE = 50;

    public const REPORT_MAX = 500;

    public function __construct(
        private readonly CsvReader $reader,
        private readonly ColumnMapper $mapper,
        private readonly ImportVault $vault,
    ) {}

    public function handler(ImportKind $kind): ImportHandler
    {
        return match ($kind) {
            ImportKind::Permits => app(PermitImportHandler::class),
            // RosterImportHandler arrives with ADMIN-007.
            ImportKind::Roster => class_exists('App\\Services\\Roster\\RosterImportHandler')
                ? app('App\\Services\\Roster\\RosterImportHandler')
                : throw new ImportFailed('kind_unavailable'),
        };
    }

    /**
     * Stage 1: seal the upload (file bytes or pasted text).
     *
     * @throws ImportFailed gate_closed | forbidden | import_quota | file caps
     */
    public function upload(User $user, ImportKind $kind, string $bytes): ImportRun
    {
        $handler = $this->handler($kind);
        $handler->assertGateOpen();
        if (! $handler->canImport($user)) {
            throw new ImportFailed('forbidden');
        }

        // "Today" is the Riga day; the bound value must be in the app timezone (a Riga midnight sent without its
        // offset would be read as UTC and miss the first hours of the day).
        $midnight = now('Europe/Riga')->startOfDay()->setTimezone((string) config('app.timezone'));
        $today = ImportRun::query()->where('kind', $kind->value)->where('created_at', '>=', $midnight)->count();
        if ($today >= self::DAILY_UPLOADS) {
            throw new ImportFailed('import_quota');
        }

        $table = $this->reader->read($bytes);
        $hasHeader = $this->mapper->looksLikeHeader($table->firstRow(), $handler);
        $rows = count($table->dataRows($hasHeader));
        if ($rows === 0) {
            throw new ImportFailed('empty');
        }
        if ($rows > CsvReader::MAX_ROWS) {
            throw new ImportFailed('too_many_rows');
        }

        $header = $table->header($hasHeader);
        $run = new ImportRun([
            'id' => strtolower((string) Str::ulid()),
            'building_id' => CurrentBuilding::id(),
            'kind' => $kind,
            'status' => ImportStatus::Uploaded,
            'created_by' => $user->id,
            'file_sha256' => hash('sha256', $bytes, true),
            'encoding' => $table->encoding,
            'delimiter' => $table->delimiter,
            'has_header' => $hasHeader,
            'row_count' => $rows,
            'mapping' => $this->mapper->preset($kind, $header) ?? $this->mapper->suggest($header, $handler),
            'options' => ['mode' => 'create_only', 'encoding_guessed' => $table->encodingGuessed],
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);
        $run->payload_ciphertext = $this->vault->seal($run, $bytes);
        $run->save();

        AuditLogger::record('import.upload', $run, data: ['kind' => $kind->value, 'rows' => $rows]);

        return $run;
    }

    /**
     * Change the detected encoding / delimiter / header flag (back to stage 2).
     */
    public function configure(ImportRun $run, string $encoding, string $delimiter, bool $hasHeader): ImportRun
    {
        $this->assertOpen($run, [ImportStatus::Uploaded, ImportStatus::Mapped, ImportStatus::Validated]);

        $table = $this->reader->read($this->vault->open($run), $encoding, $delimiter);
        $rows = count($table->dataRows($hasHeader));
        if ($rows === 0) {
            throw new ImportFailed('empty');
        }
        if ($rows > CsvReader::MAX_ROWS) {
            throw new ImportFailed('too_many_rows');
        }

        $handler = $this->handler($run->kind);
        $header = $table->header($hasHeader);
        $headerChanged = $hasHeader !== $run->has_header || $table->delimiter !== $run->delimiter;

        $run->forceFill([
            'encoding' => $table->encoding,
            'delimiter' => $table->delimiter,
            'has_header' => $hasHeader,
            'row_count' => $rows,
            'mapping' => $headerChanged ? ($this->mapper->preset($run->kind, $header) ?? $this->mapper->suggest($header, $handler)) : $run->mapping,
            'options' => ['encoding_guessed' => false] + ($run->options ?? []),
            'status' => ImportStatus::Uploaded,
            'summary' => null,
        ])->save();

        return $run;
    }

    /**
     * Stage 2: save the mapping (+ mode) and remember it for this header layout.
     *
     * @param  array<int|string, string|null>  $mapping  column index => target ('' / null = ignore)
     *
     * @throws ImportFailed mapping
     */
    public function map(User $user, ImportRun $run, array $mapping, string $mode = 'create_only'): ImportRun
    {
        $this->assertOpen($run, [ImportStatus::Uploaded, ImportStatus::Mapped, ImportStatus::Validated]);
        $handler = $this->handler($run->kind);
        $columns = $this->table($run)->columnCount();

        $clean = [];
        foreach ($mapping as $index => $target) {
            if (is_string($target) && $target !== '' && (int) $index >= 0 && (int) $index < $columns) {
                $clean[(int) $index] = $target;
            }
        }
        ksort($clean);

        if ($this->mapper->problems($clean, $handler) !== []) {
            throw new ImportFailed('mapping');
        }

        $run->forceFill([
            'mapping' => $clean,
            'options' => ['mode' => $mode === 'create_or_update' ? 'create_or_update' : 'create_only'] + ($run->options ?? []),
            'status' => ImportStatus::Mapped,
            'summary' => null,
        ])->save();

        if ($run->has_header) {
            $this->mapper->remember($run->kind, $this->header($run), $clean, $user);
        }

        return $run;
    }

    public function table(ImportRun $run): CsvTable
    {
        return $this->reader->read($this->vault->open($run), $run->encoding, $run->delimiter);
    }

    /**
     * Column labels for the mapping step: the header row (not personal data) or "1. kolonna" … without one.
     *
     * @return list<string>
     */
    public function header(ImportRun $run): array
    {
        $table = $this->table($run);

        if ($run->has_header) {
            return array_pad($table->firstRow(), $table->columnCount(), '');
        }

        return array_map(static fn (int $i): string => __('admin.import.column_n', ['n' => $i + 1]), range(0, max(0, $table->columnCount() - 1)));
    }

    /**
     * Records keyed by line with cells keyed by target (mapping applied).
     *
     * @return array<int, array<string, string>>
     */
    public function mappedRows(ImportRun $run): array
    {
        $mapping = $run->mapping ?? [];
        $out = [];

        foreach ($this->table($run)->dataRows($run->has_header) as $line => $cells) {
            $row = [];
            foreach ($mapping as $index => $target) {
                $row[(string) $target] = $cells[(int) $index] ?? '';
            }
            $out[$line] = $row;
        }

        return $out;
    }

    /**
     * Validate every record (preview and dry-run).
     *
     * @return array<int, RowResult>
     */
    public function results(ImportRun $run): array
    {
        $validator = $this->handler($run->kind)->validator($run);
        $out = [];

        foreach ($this->mappedRows($run) as $line => $cells) {
            $out[$line] = $validator->validate($line, $cells);
        }

        return $out;
    }

    /**
     * Stage 3: one page of the masked preview + counts.
     *
     * @return array{rows: list<array{line: int, status: string, codes: list<string>, cells: array<string, string>}>, page: int, pages: int, counts: array<string, mixed>}
     */
    public function preview(ImportRun $run, int $page = 1, bool $onlyErrors = false): array
    {
        $this->assertOpen($run, [ImportStatus::Mapped, ImportStatus::Validated]);
        $handler = $this->handler($run->kind);
        $results = $this->results($run);
        $counts = $this->counts($results);

        if ($onlyErrors) {
            $results = array_filter($results, static fn (RowResult $r): bool => $r->status() !== RowResult::OK);
        }

        $pages = max(1, (int) ceil(count($results) / self::PAGE_SIZE));
        $page = max(1, min($pages, $page));
        $rows = [];

        $mapped = $this->mappedRows($run);
        foreach (array_slice($results, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, true) as $line => $result) {
            $cells = [];
            foreach ($mapped[$line] ?? [] as $target => $value) {
                $cells[$target] = $value === '' ? '' : $handler->mask($target, $value);
            }
            $rows[] = ['line' => (int) $line, 'status' => $result->status(), 'codes' => $result->uniqueCodes(), 'cells' => $cells];
        }

        return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'counts' => $counts];
    }

    /**
     * Stage 4: exact counts against the current database state; writes import_runs.summary only.
     *
     * @return array<string, mixed>
     */
    public function dryRun(ImportRun $run): array
    {
        $this->assertOpen($run, [ImportStatus::Mapped, ImportStatus::Validated]);
        $counts = $this->counts($this->results($run));

        $run->forceFill(['summary' => $counts + ['at' => now()->toIso8601String()], 'status' => ImportStatus::Validated])->save();

        return $counts;
    }

    /**
     * Stage 5: start the commit.
     *
     * @throws ImportFailed confirm_required | dry_run_stale | commit_running | gate_closed | forbidden
     */
    public function commit(User $user, ImportRun $run, bool $confirmed): void
    {
        $this->assertOpen($run, [ImportStatus::Validated]);
        $handler = $this->handler($run->kind);
        $handler->assertGateOpen();

        if (! $handler->canImport($user)) {
            throw new ImportFailed('forbidden');
        }
        if (! $confirmed) {
            throw new ImportFailed('confirm_required');
        }

        $at = $run->summary['at'] ?? null;
        if (! is_string($at) || now()->subMinutes(self::DRY_RUN_VALID_MINUTES)->greaterThan($at)) {
            throw new ImportFailed('dry_run_stale');
        }

        try {
            $claimed = DB::transaction(fn (): int => ImportRun::query()->whereKey($run->id)->where('status', ImportStatus::Validated->value)->update([
                'status' => ImportStatus::Committing->value,
                'options' => json_encode(['committed_by' => $user->id, 'endorsed' => true] + ($run->options ?? []), JSON_THROW_ON_ERROR),
                'committed_counts' => json_encode(['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'report' => []], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]));
        } catch (QueryException $e) {
            if ((string) ($e->errorInfo[0] ?? '') === '23505') {
                throw new ImportFailed('commit_running');
            }

            throw $e;
        }

        if ($claimed !== 1) {
            throw new ImportFailed('commit_running');
        }

        CommitImport::dispatch((int) $run->building_id, (string) $run->id);
    }

    /**
     * CommitImport body: process the run chunk by chunk until done. Safe to call twice.
     */
    public function runCommit(string $runId): void
    {
        try {
            while (! $this->commitChunk($runId)) {
                // next chunk
            }
        } catch (Throwable $e) {
            ImportRun::query()->whereKey($runId)->where('status', ImportStatus::Committing->value)->update([
                'status' => ImportStatus::Failed->value,
                'error_code' => 'commit_failed',
                'payload_ciphertext' => null,
                'updated_at' => now(),
            ]);
            // Never re-throw the original: a database exception message can carry bound cell values.
            report(new RuntimeException('Import commit failed ('.class_basename($e).').'));
        }
    }

    public function discard(User $user, ImportRun $run): void
    {
        if ($run->status === ImportStatus::Committing) {
            throw new ImportFailed('commit_running');
        }

        $run->forceFill(['status' => ImportStatus::Discarded, 'payload_ciphertext' => null])->save();
        AuditLogger::record('import.discard', $run, data: ['kind' => $run->kind->value]);
    }

    /**
     * The same file (sha256) committed in the last 24 h, if any ("Šis fails jau importēts dd.mm. hh:mm").
     */
    public function sameFileCommitted(ImportRun $run): ?ImportRun
    {
        return ImportRun::query()->where('kind', $run->kind->value)
            ->where('file_sha256', Bytea::literal($run->file_sha256))
            ->where('status', ImportStatus::Committed->value)
            ->where('created_at', '>=', now()->subHours(self::TTL_HOURS))
            ->whereKeyNot($run->id)
            ->latest()->first();
    }

    /**
     * The newest open run of this kind the user can resume (24 h).
     */
    public function resumable(User $user, ImportKind $kind): ?ImportRun
    {
        return ImportRun::query()->where('kind', $kind->value)->where('created_by', $user->id)
            ->whereIn('status', [ImportStatus::Uploaded->value, ImportStatus::Mapped->value, ImportStatus::Validated->value, ImportStatus::Committing->value])
            ->where('expires_at', '>', now())
            ->latest()->first();
    }

    /**
     * Counts per action / status / code — never values.
     *
     * @param  array<int, RowResult>  $results
     * @return array{rows: int, create: int, update: int, skip: int, ok: int, warning: int, error: int, codes: array<string, int>}
     */
    public function counts(array $results): array
    {
        $c = ['rows' => count($results), 'create' => 0, 'update' => 0, 'skip' => 0, 'ok' => 0, 'warning' => 0, 'error' => 0, 'codes' => []];

        foreach ($results as $r) {
            $c[$r->effectiveAction()]++;
            $c[$r->status()]++;
            foreach ($r->uniqueCodes() as $code) {
                $c['codes'][$code] = ($c['codes'][$code] ?? 0) + 1;
            }
        }
        ksort($c['codes']);

        return $c;
    }

    /**
     * One chunk in one transaction; true when the run is finished (or not committing any more).
     */
    private function commitChunk(string $runId): bool
    {
        return DB::transaction(function () use ($runId): bool {
            $run = ImportRun::query()->find($runId);
            if ($run === null || $run->status !== ImportStatus::Committing) {
                return true;
            }

            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['import:'.$run->building_id.':'.$run->kind->value]);
            $run = ImportRun::query()->lockForUpdate()->findOrFail($runId);
            if ($run->status !== ImportStatus::Committing) {
                return true;
            }

            $handler = $this->handler($run->kind);
            $counts = $run->committed_counts ?? ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'report' => []];

            if (! $handler->gateOpen()) {
                $run->forceFill(['status' => ImportStatus::Failed, 'error_code' => 'gate_closed', 'payload_ciphertext' => null])->save();

                return true;
            }

            $rows = $this->mappedRows($run);
            $processed = (int) $counts['processed'];
            $chunk = array_slice($rows, $processed, self::CHUNK, true);

            if ($chunk === []) {
                $run->forceFill(['status' => ImportStatus::Committed, 'payload_ciphertext' => null, 'committed_counts' => $counts])->save();
                AuditLogger::record('import.commit', $run, data: [
                    'kind' => $run->kind->value, 'rows' => $processed,
                    'created' => (int) $counts['created'], 'updated' => (int) $counts['updated'], 'skipped' => (int) $counts['skipped'],
                ], actorUserId: (int) ($run->options['committed_by'] ?? $run->created_by));

                return true;
            }

            $actor = User::query()->findOrFail((int) ($run->options['committed_by'] ?? $run->created_by));
            $validator = $handler->validator($run);
            $validator->prime(array_slice($rows, 0, $processed, true));

            foreach ($chunk as $line => $cells) {
                [$outcome, $codes] = $this->commitRow($handler, $validator, $actor, $run, (int) $line, $cells);
                $counts[$outcome]++;
                if ($codes !== [] && count($counts['report']) < self::REPORT_MAX) {
                    $counts['report'][] = ['row' => (int) $line, 'codes' => $codes, 'skipped' => $outcome === 'skipped'];
                }
                $processed++;
            }

            $counts['processed'] = $processed;
            $run->forceFill(['committed_counts' => $counts])->save();

            return false;
        });
    }

    /**
     * Re-validate + write one record inside its own savepoint.
     *
     * @param  array<string, string>  $cells
     * @return array{0: 'created'|'updated'|'skipped', 1: list<string>}
     */
    private function commitRow(ImportHandler $handler, RowValidator $validator, User $actor, ImportRun $run, int $line, array $cells): array
    {
        $result = $validator->validate($line, $cells);

        if ($result->effectiveAction() === 'skip') {
            return ['skipped', $result->uniqueCodes()];
        }

        try {
            $outcome = DB::transaction(fn (): string => $handler->commitRow($actor, $run, $result));
        } catch (ImportFailed $e) {
            return ['skipped', [...$result->uniqueCodes(), $e->key]];
        } catch (QueryException) {
            return ['skipped', [...$result->uniqueCodes(), 'commit_error']];
        }

        return [in_array($outcome, ['created', 'updated'], true) ? $outcome : 'skipped', $result->uniqueCodes()];
    }

    /**
     * @param  list<ImportStatus>  $allowed
     */
    private function assertOpen(ImportRun $run, array $allowed): void
    {
        if ($run->isExpired() && ! $run->status->isTerminal()) {
            $run->forceFill(['status' => ImportStatus::Expired, 'payload_ciphertext' => null])->save();

            throw new ImportFailed('expired');
        }

        if (! in_array($run->status, $allowed, true)) {
            throw new ImportFailed('wrong_stage');
        }
    }
}
