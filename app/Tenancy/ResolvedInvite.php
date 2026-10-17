<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Result of a successful invite resolution (kept in the server-side session during /join).
 * Contains no secret: the plaintext code is never stored.
 */
final readonly class ResolvedInvite
{
    public function __construct(
        public string $inviteId,
        public int $buildingId,
        public int $apartmentId,
        public int $apartmentNumber,
        public int $sectionNumber,
        public int $floor,
        public int $buildingNoticeId,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'invite_id' => $this->inviteId,
            'building_id' => $this->buildingId,
            'apartment_id' => $this->apartmentId,
            'apartment_number' => $this->apartmentNumber,
            'section_number' => $this->sectionNumber,
            'floor' => $this->floor,
            'building_notice_id' => $this->buildingNoticeId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            inviteId: (string) $data['invite_id'],
            buildingId: (int) $data['building_id'],
            apartmentId: (int) $data['apartment_id'],
            apartmentNumber: (int) $data['apartment_number'],
            sectionNumber: (int) $data['section_number'],
            floor: (int) $data['floor'],
            buildingNoticeId: (int) $data['building_notice_id'],
        );
    }
}
