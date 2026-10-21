<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Audit\AuditLogger;
use App\Gdpr\CsvWriter;
use App\Models\Apartment;
use App\Models\AuditEntry;
use App\Models\ResidentContact;
use App\Models\Section;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use ZipArchive;

/**
 * Roster files (ADMIN-007, ROUND2-SPEC §5.3), generated on demand and never stored:
 *
 * - csv(): the whole roster — building_admin + strong step-up (policy `export`), 5 per building per day, audited
 *   `roster.export` {rows}; UTF-8 BOM, `;`, formula-safe (CsvWriter), same column names as the import template so an
 *   export can be imported again.
 * - extract(): the Art. 15 answer for ONE contact (JSON + CSV of the contact + the roster audit rows about it),
 *   audited `roster.subject_export`.
 */
final class RosterExporter
{
    public const EXPORT_ACTION = 'roster.export';

    public function __construct(private readonly ContactVault $vault) {}

    /**
     * @return array{content: string, rows: int}
     *
     * @throws RosterActionFailed step_up | export_cap
     */
    public function csv(User $actor, string $locale): array
    {
        if (! Gate::forUser($actor)->allows('export', ResidentContact::class)) {
            throw new RosterActionFailed('step_up');
        }
        if ($this->exportsToday() >= (int) config('majas.roster.export_per_day')) {
            throw new RosterActionFailed('export_cap');
        }

        $apartments = Apartment::query()->get(['id', 'number', 'floor', 'section_id'])->keyBy('id');
        $sections = Section::query()->pluck('number', 'id');
        $contacts = ResidentContact::query()->orderBy('apartment_id')->orderBy('created_at')->get()
            ->sortBy(fn (ResidentContact $c): int => $apartments->has($c->apartment_id) ? (int) $apartments->get($c->apartment_id)->number : 0)->values();

        $rows = [self::headers($locale)];
        foreach ($contacts as $c) {
            $a = $apartments->get($c->apartment_id);
            $rows[] = [
                $a?->number,
                $this->vault->openField($c, 'name'),
                implode(', ', $this->phones($c)),
                $this->vault->openField($c, 'email'),
                $c->relation->label($locale),
                self::flag($c->bis_registered, $locale),
                self::flag($c->whatsapp_member, $locale),
                $this->vault->openField($c, 'comment'),
                $a !== null ? $sections->get($a->section_id) : null,
                $a?->floor,
                $c->reviewed_at?->timezone('Europe/Riga')->format('d.m.Y'),
                $c->moved_out_at?->timezone('Europe/Riga')->format('d.m.Y'),
            ];
        }

        AuditLogger::record(self::EXPORT_ACTION, null, $contacts->pluck('user_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all(),
            data: ['rows' => $contacts->count()], actorRole: RosterService::actorRole($actor), actorUserId: (int) $actor->id);

        return ['content' => CsvWriter::toString($rows, ';'), 'rows' => $contacts->count()];
    }

    /**
     * Art. 15 extract of one contact: a ZIP with contact.json + contact.csv.
     *
     * @throws RosterActionFailed step_up
     */
    public function extract(User $actor, ResidentContact $contact, string $locale): string
    {
        if (! Gate::forUser($actor)->allows('reveal', $contact)) {
            throw new RosterActionFailed('step_up');
        }

        $apartment = Apartment::query()->find($contact->apartment_id);
        $data = [
            'generated_at' => now()->toIso8601String(),
            'apartment' => $apartment?->number,
            'stairwell' => $apartment !== null ? Section::query()->whereKey($apartment->section_id)->value('number') : null,
            'relation' => $contact->relation->value,
            'name' => $this->vault->openField($contact, 'name'),
            'phones' => $this->phones($contact),
            'email' => $this->vault->openField($contact, 'email'),
            'bis_registered' => $contact->bis_registered,
            'whatsapp_member' => $contact->whatsapp_member,
            'comment' => $this->vault->openField($contact, 'comment'),
            'source' => $contact->source->value,
            'linked_account' => $contact->user_id !== null,
            'reviewed_at' => $contact->reviewed_at?->toIso8601String(),
            'moved_out_at' => $contact->moved_out_at?->toIso8601String(),
            'created_at' => $contact->created_at->toIso8601String(),
            'updated_at' => $contact->updated_at->toIso8601String(),
            'audit' => $this->auditAbout($contact),
        ];

        AuditLogger::record('roster.subject_export', $contact, $contact->user_id !== null ? [(int) $contact->user_id] : [],
            data: ['apartment_id' => (int) $contact->apartment_id], actorRole: RosterService::actorRole($actor), actorUserId: (int) $actor->id);

        $csv = CsvWriter::toString([
            [...self::headers($locale)],
            [
                $data['apartment'], $data['name'], implode(', ', $data['phones']), $data['email'], $contact->relation->label($locale),
                self::flag($contact->bis_registered, $locale), self::flag($contact->whatsapp_member, $locale), $data['comment'],
                $data['stairwell'], $apartment?->floor, $contact->reviewed_at?->format('d.m.Y'), $contact->moved_out_at?->format('d.m.Y'),
            ],
        ], ';');

        $tmp = tempnam(sys_get_temp_dir(), 'mpr');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('contact.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('contact.csv', $csv);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    public function exportsToday(): int
    {
        return AuditEntry::query()->forBuilding(CurrentBuilding::id())
            ->where('action', self::EXPORT_ACTION)
            ->where('created_at', '>', now()->subDay())
            ->count();
    }

    /**
     * Column names — identical to the import template, then the export-only columns.
     *
     * @return list<string>
     */
    public static function headers(string $locale): array
    {
        return array_map(static fn (string $k): string => __('roster.csv.'.$k, [], $locale), [
            'apartment', 'name', 'phone', 'email', 'relation', 'bis', 'whatsapp', 'comment', 'stairwell', 'floor', 'reviewed', 'moved_out',
        ]);
    }

    private static function flag(?bool $value, string $locale): string
    {
        return $value === null ? '' : __($value ? 'roster.csv.yes' : 'roster.csv.no', [], $locale);
    }

    /**
     * @return list<array{date: string, action: string, actor_role: ?string}>
     */
    private function auditAbout(ResidentContact $contact): array
    {
        return AuditEntry::query()->forBuilding((int) $contact->building_id)
            ->where('action', 'like', 'roster.%')
            ->where(fn ($q) => $q->where(fn ($s) => $s->where('subject_type', $contact->getMorphClass())->where('subject_id', (string) $contact->id))
                ->orWhereRaw("jsonb_exists(data->'contact_ids', ?)", [(string) $contact->id]))
            ->orderBy('id')
            ->get(['created_at', 'action', 'actor_role'])
            ->map(static fn (AuditEntry $e): array => ['date' => $e->created_at->toIso8601String(), 'action' => $e->action, 'actor_role' => $e->actor_role])
            ->values()->all();
    }

    /**
     * @return list<string>
     */
    private function phones(ResidentContact $contact): array
    {
        $json = $this->vault->openField($contact, 'phones');
        $list = $json !== null ? json_decode($json, true) : [];

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }
}
