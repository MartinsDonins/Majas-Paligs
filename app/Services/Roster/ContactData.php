<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Enums\ContactRelation;
use App\Support\PhoneNormalizer;

/**
 * Plaintext of one roster contact on its way into RosterService (form, import) — in memory only.
 */
final readonly class ContactData
{
    /**
     * @param  list<string>  $phones  E.164
     */
    public function __construct(
        public ContactRelation $relation = ContactRelation::Contact,
        public ?string $name = null,
        public array $phones = [],
        public ?string $email = null,
        public ?bool $bisRegistered = null,
        public ?bool $whatsappMember = null,
        public ?string $comment = null,
    ) {}

    /**
     * From an admin form (phones as typed; normalised here).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $phones = [];
        foreach ((array) ($data['phones'] ?? []) as $item) {
            $value = is_array($item) ? (string) ($item['number'] ?? '') : (string) $item;
            $e164 = PhoneNormalizer::normalize($value);
            if ($value !== '' && $e164 === null) {
                throw new RosterActionFailed('phone_invalid');
            }
            if ($e164 !== null && ! in_array($e164, $phones, true)) {
                $phones[] = $e164;
            }
        }

        return new self(
            relation: ContactRelation::tryFrom((string) ($data['relation'] ?? '')) ?? ContactRelation::Contact,
            name: self::text($data['name'] ?? null),
            phones: $phones,
            email: self::text($data['email'] ?? null),
            bisRegistered: self::flag($data['bis_registered'] ?? null),
            whatsappMember: self::flag($data['whatsapp_member'] ?? null),
            comment: self::text($data['comment'] ?? null),
        );
    }

    public function isEmpty(): bool
    {
        return $this->name === null && $this->phones === [] && $this->email === null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * Form tri-state: '1' / true = yes, '0' / false = no, '' / null = unknown.
     */
    private static function flag(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'yes' => true,
            $value === false, $value === 0, $value === '0', $value === 'no' => false,
            default => null,
        };
    }
}
