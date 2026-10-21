<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Enums\ContactRelation;
use App\Enums\ContactSource;
use App\Gdpr\RosterGate;
use App\Models\Apartment;
use App\Models\ImportRun;
use App\Models\ResidentContact;
use App\Models\User;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportField;
use App\Services\Import\ImportHandler;
use App\Services\Import\ImportRow;
use App\Services\Import\RowResult;
use App\Support\Keys;
use App\Support\PhoneNormalizer;
use App\Tenancy\CurrentBuilding;

/**
 * Roster import (ADMIN-007 on DB-003, ROUND2-SPEC §4.4/§4.5): the house's old contact sheet → resident_contacts.
 * building_admin only, gate L-R (D6: also for the upload). Rows are matched to existing contacts of the SAME
 * apartment by e-mail / phone blind index, or by name when the row has neither; create_only skips matches
 * (`exists_skip`), create_or_update fills the matched contact (`exists_update`). Writes go through RosterService.
 */
final class RosterImportHandler implements ImportHandler
{
    /** Words that suggest health, debt or conflict information in a comment (LV / RU, unaccented stems). */
    private const SENSITIVE = [
        'slim', 'veselib', 'diagnoz', 'invalid', 'parads', 'paradu', 'paradnie', 'alkohol', 'narkot', 'strid', 'policij',
        'cietum', 'depresij', 'demenc', 'psihi', 'vezis', 'onkolog', 'болен', 'больн', 'болезн', 'диагноз', 'инвалид',
        'долги', 'долга', 'должни', 'задолж', 'алкогол', 'наркот', 'ссор', 'полици', 'тюрьм', 'депресс', 'деменц',
        'психи', 'онколог',
    ];

    private const RELATIONS = [
        'owner' => ['ipasnieks', 'ipasniece', 'ipasnieki', 'ipasn', 'owner', 'собственник', 'собственница', 'владелец', 'владелица', 'хозяин'],
        'tenant' => ['irnieks', 'irniece', 'irnieki', 'irn', 'tenant', 'renter', 'арендатор', 'квартирант', 'квартирантка', 'съемщик'],
        'contact' => ['kontaktpersona', 'kontakts', 'kontakt', 'contact', 'контакт', 'контактное лицо', 'cits', 'другое', 'other'],
    ];

    private const YES = ['ja', 'yes', 'y', '1', 'x', '+', '✓', '✔', 'да', 'ir', 'jah'];

    private const NO = ['ne', 'no', 'n', '0', 'нет', 'не', 'nav'];

    public function __construct(
        private readonly RosterService $roster,
        private readonly RosterViewer $viewer,
        private readonly ContactVault $vault,
    ) {}

    public function kind(): string
    {
        return 'roster';
    }

    public function fields(): array
    {
        return [
            'apartment_number' => new ImportField('apartment_number', required: true, maxLength: 16, synonyms: [
                'dzivoklis', 'dz', 'dz nr', 'dzivokla nr', 'dzivokla numurs', 'dzivokla', 'kvartira', 'kv', 'nomer kvartiry',
                'квартира', 'кв', 'номер квартиры', 'apartment', 'apt', 'flat', 'apartment number',
            ]),
            'name' => new ImportField('name', maxLength: (int) config('majas.roster.name_max'), synonyms: [
                'vards', 'vards uzvards', 'uzvards', 'kontaktpersona', 'imya', 'fio', 'имя', 'фио', 'имя фамилия', 'name', 'contact', 'full name',
            ]),
            'phone' => new ImportField('phone', multi: true, maxLength: 120, synonyms: [
                'telefons', 'talrunis', 'talr', 'tel', 'mob', 'mobilais', 'mobilais telefons', 'telefon', 'телефон', 'тел', 'phone', 'mobile', 'phone number',
            ]),
            'email' => new ImportField('email', maxLength: 254, synonyms: [
                'epasts', 'e pasts', 'pasts', 'pochta', 'почта', 'эл почта', 'e mail', 'email', 'mail',
            ]),
            'relation' => new ImportField('relation', maxLength: 40, synonyms: [
                'statuss', 'loma', 'ipasnieks irnieks', 'status', 'статус', 'собственник', 'relation', 'owner tenant',
            ]),
            'bis_registered' => new ImportField('bis_registered', maxLength: 20, synonyms: ['bis', 'bis registrets', 'бис', 'bis registered']),
            'whatsapp_member' => new ImportField('whatsapp_member', maxLength: 20, synonyms: ['whatsapp', 'wa', 'whatsapp grupa', 'ватсап', 'whatsapp group']),
            'comment' => new ImportField('comment', maxLength: (int) config('majas.roster.comment_max'), synonyms: [
                'komentars', 'piezimes', 'piezime', 'kommentarij', 'комментарий', 'примечание', 'comment', 'notes', 'note',
            ]),
        ];
    }

    public function closedGate(): ?array
    {
        $status = RosterGate::status(CurrentBuilding::get());

        return $status->open ? null : $status->missingLabels();
    }

    public function mayImport(User $user): bool
    {
        return ! $user->isTombstone() && $user->isBuildingAdmin(CurrentBuilding::get());
    }

    public function mayDownloadTemplate(User $user): bool
    {
        $building = CurrentBuilding::get();

        return ! $user->isTombstone() && ($user->isBuildingAdmin($building) || $user->elderSectionIds($building) !== []);
    }

    public function vaultKey(): string
    {
        return Keys::contactEncryption();
    }

    public function confirmations(): array
    {
        return ['art14'];
    }

    public function codes(): array
    {
        return [
            'contact_empty' => 'error',
            'phone_invalid' => 'error',
            'phone_foreign' => 'warning',
            'phone_too_many' => 'warning',
            'email_invalid' => 'error',
            'relation_unknown' => 'warning',
            'flag_unparsed' => 'warning',
            'comment_sensitive_hint' => 'warning',
        ];
    }

    public function template(string $locale): array
    {
        return [
            'headers' => array_slice(RosterExporter::headers($locale), 0, 8),
            'example' => [
                '1',
                __('roster.template.example_name', [], $locale),
                __('roster.template.example_phone', [], $locale),
                'paraugs@example.test',
                ContactRelation::Owner->label($locale),
                __('roster.csv.yes', [], $locale),
                __('roster.csv.no', [], $locale),
                __('roster.template.example_comment', [], $locale),
            ],
        ];
    }

    public function dedupeKeys(ImportRow $row, RowResult $result): array
    {
        $prefix = 'a'.$result->apartmentId.':';
        $keys = [];

        $email = mb_strtolower(trim($row->get('email')));
        if ($email !== '') {
            $keys[] = $prefix.'e:'.$email;
        }
        foreach ($this->phones($row)['valid'] as $phone) {
            $keys[] = $prefix.'p:'.$phone;
        }
        if ($keys === [] && $row->has('name')) {
            $keys[] = $prefix.'n:'.mb_strtolower($row->get('name'));
        }

        return $keys;
    }

    public function prepare(ImportContext $context, array $rows): void
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (RowResult $r): ?int => $r->apartmentId, $rows))));
        $context->cache['existing'] = $ids === [] ? [] : $this->viewer->matchKeys($ids);
    }

    public function validate(ImportRow $row, RowResult $result, ImportContext $context): void
    {
        $name = $row->has('name') ? $row->get('name') : null;

        $phones = $this->phones($row);
        if ($phones['invalid']) {
            $result->add('phone_invalid');
        }
        if ($phones['foreign']) {
            $result->add('phone_foreign');
        }
        $list = $phones['valid'];
        if (count($list) > (int) config('majas.roster.max_phones')) {
            $result->add('phone_too_many');
            $list = array_slice($list, 0, (int) config('majas.roster.max_phones'));
        }

        $email = $row->has('email') ? mb_strtolower(trim($row->get('email'))) : null;
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $result->add('email_invalid');
        }

        $relation = $this->relation($row->get('relation'));
        if ($relation === null) {
            $result->add('relation_unknown');
            $relation = ContactRelation::Contact;
        }

        $bis = $this->flag($row->get('bis_registered'), $result);
        $whatsapp = $this->flag($row->get('whatsapp_member'), $result);

        $comment = $row->has('comment') ? $row->get('comment') : null;
        if ($comment !== null && $this->sensitive($comment)) {
            $result->add('comment_sensitive_hint');
        }

        if ($name === null && $list === [] && ($email === null || $result->has('email_invalid'))) {
            $result->add('contact_empty');
        }

        $result->values = [
            'relation' => $relation->value,
            'name' => $name,
            'phones' => $list,
            'email' => $email,
            'bis' => $bis,
            'whatsapp' => $whatsapp,
            'comment' => $comment,
            'has_relation' => $row->has('relation'),
        ];

        $match = $this->existing($context, $result, $email, $list, $name);
        if ($match !== null) {
            $result->targetId = $match;
            $result->add($context->updates() ? 'exists_update' : 'exists_skip');
            $result->action = $context->updates() ? 'update' : 'skip';
        } else {
            $result->action = 'create';
        }

        $result->preview = [
            'apartment_number' => (string) $result->apartmentNumber,
            'name' => (string) $name,
            'phone' => implode(', ', array_map(PhoneNormalizer::mask(...), $list)),
            'email' => $email !== null ? RosterViewer::maskEmail($email) : '',
            'relation' => $relation->label(),
            'bis_registered' => self::flagLabel($bis),
            'whatsapp_member' => self::flagLabel($whatsapp),
            'comment' => $comment !== null ? __('roster.list.has_comment') : '',
        ];
    }

    public function commit(RowResult $result, ImportRun $run, User $actor): string
    {
        $v = $result->values;
        $incoming = new ContactData(
            relation: ContactRelation::from((string) $v['relation']),
            name: $v['name'],
            phones: $v['phones'],
            email: $v['email'],
            bisRegistered: $v['bis'],
            whatsappMember: $v['whatsapp'],
            comment: $v['comment'],
        );

        if ($result->action === 'update' && $result->targetId !== null) {
            $contact = ResidentContact::query()->whereKey($result->targetId)->firstOrFail();
            $old = $this->viewer->plain($contact);
            $merged = new ContactData(
                relation: $v['has_relation'] ? $incoming->relation : $old->relation,
                name: $incoming->name ?? $old->name,
                phones: $incoming->phones !== [] ? $incoming->phones : $old->phones,
                email: $incoming->email ?? $old->email,
                bisRegistered: $incoming->bisRegistered ?? $old->bisRegistered,
                whatsappMember: $incoming->whatsappMember ?? $old->whatsappMember,
                comment: $incoming->comment ?? $old->comment,
            );
            $this->roster->update($actor, $contact, $merged, audit: false);

            return 'updated';
        }

        $apartment = Apartment::query()->findOrFail((int) $result->apartmentId);
        $this->roster->create($actor, $apartment, $incoming, ContactSource::Import, (string) $run->id, audit: false);

        return 'created';
    }

    /**
     * @return array{valid: list<string>, invalid: bool, foreign: bool}
     */
    private function phones(ImportRow $row): array
    {
        $valid = [];
        $invalid = false;
        $foreign = false;

        foreach ($row->all('phone') as $cell) {
            foreach (PhoneNormalizer::split($cell) as $part) {
                $c = PhoneNormalizer::classify($part);
                if ($c['e164'] === null) {
                    $invalid = true;

                    continue;
                }
                $foreign = $foreign || $c['kind'] === PhoneNormalizer::FOREIGN;
                if (! in_array($c['e164'], $valid, true)) {
                    $valid[] = $c['e164'];
                }
            }
        }

        return ['valid' => $valid, 'invalid' => $invalid, 'foreign' => $foreign];
    }

    private function relation(string $value): ?ContactRelation
    {
        $normalized = ColumnMapper::normalize($value);
        if ($normalized === '') {
            return ContactRelation::Contact;
        }

        foreach (self::RELATIONS as $relation => $words) {
            foreach ($words as $word) {
                if ($normalized === ColumnMapper::normalize($word) || str_starts_with($normalized, ColumnMapper::normalize($word).' ')) {
                    return ContactRelation::from($relation);
                }
            }
        }

        return null;
    }

    private function flag(string $value, RowResult $result): ?bool
    {
        $raw = mb_strtolower(trim($value));
        if ($raw === '') {
            return null;
        }
        $normalized = ColumnMapper::normalize($raw);

        if (in_array($raw, self::YES, true) || in_array($normalized, self::YES, true)) {
            return true;
        }
        if (in_array($raw, self::NO, true) || in_array($normalized, self::NO, true)) {
            return false;
        }

        $result->add('flag_unparsed');

        return null;
    }

    private function sensitive(string $comment): bool
    {
        $normalized = ColumnMapper::normalize($comment);

        foreach (self::SENSITIVE as $stem) {
            // Word start only: "slims" / "parāds" yes, "atslimot"… no.
            if (preg_match('/(^| )'.preg_quote(ColumnMapper::normalize($stem), '/').'/u', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Id of an existing contact of the same apartment that this row describes, or null.
     *
     * @param  list<string>  $phones
     */
    private function existing(ImportContext $context, RowResult $result, ?string $email, array $phones, ?string $name): ?string
    {
        $candidates = $context->cache['existing'][(int) $result->apartmentId] ?? [];
        if ($candidates === []) {
            return null;
        }

        $buildingId = $context->buildingId;
        $emailBidx = $email !== null && ! $result->has('email_invalid') ? $this->vault->bidx($buildingId, 'email', $email) : null;
        $phoneBidx = array_map(fn (string $p): string => $this->vault->bidx($buildingId, 'phone', $p), $phones);

        foreach ($candidates as $c) {
            if ($emailBidx !== null && $c['email_bidx'] !== null && hash_equals($c['email_bidx'], $emailBidx)) {
                return $c['id'];
            }
            if (array_intersect($phoneBidx, $c['phone_bidx']) !== []) {
                return $c['id'];
            }
        }

        if ($emailBidx === null && $phoneBidx === [] && $name !== null) {
            foreach ($candidates as $c) {
                if ($c['name'] !== null && $c['name'] === mb_strtolower($name)) {
                    return $c['id'];
                }
            }
        }

        return null;
    }

    private static function flagLabel(?bool $value): string
    {
        return match ($value) {
            true => __('roster.flag.yes'),
            false => __('roster.flag.no'),
            null => __('roster.flag.unknown'),
        };
    }
}
