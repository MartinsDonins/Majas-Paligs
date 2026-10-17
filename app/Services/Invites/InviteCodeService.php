<?php

declare(strict_types=1);

namespace App\Services\Invites;

use App\Support\Keys;
use InvalidArgumentException;

/**
 * Apartment invite codes (MVP-SPEC A.5): 10 chars from random_bytes over the unambiguous alphabet
 * 23456789ABCDEFGHJKMNPQRSTUVWXYZ (no 0/O/1/I/L, ≈49 bits), displayed XXXXX-XXXXX, stored as HMAC-SHA256
 * with INVITE_KEY only. Typed codes are normalised (case, separators, Cyrillic look-alikes); a typed O/0 or
 * I/1/L is rejected with a hint — never silently mapped.
 */
final class InviteCodeService
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const LENGTH = 10;

    /** Cyrillic look-alikes → Latin (after uppercasing). */
    public const CYRILLIC_MAP = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M',
        'Н' => 'H', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X',
    ];

    /**
     * Generate a new plaintext code (kept in memory only — never persisted or logged).
     */
    public function generate(): string
    {
        return self::randomString(self::ALPHABET, self::LENGTH);
    }

    /**
     * Uniform random string over an alphabet (rejection sampling, no modulo bias).
     */
    public static function randomString(string $alphabet, int $length): string
    {
        $n = strlen($alphabet);
        $limit = intdiv(256, $n) * $n;
        $out = '';

        while (strlen($out) < $length) {
            foreach (str_split(random_bytes($length * 2)) as $byte) {
                $v = ord($byte);
                if ($v < $limit) {
                    $out .= $alphabet[$v % $n];
                    if (strlen($out) === $length) {
                        break;
                    }
                }
            }
        }

        return $out;
    }

    public function format(string $code): string
    {
        $code = $this->normalize($code);

        return substr($code, 0, 5).'-'.substr($code, 5);
    }

    /**
     * Normalise a typed code. Throws InvalidInviteCode with a reason the UI can explain.
     */
    public function normalize(string $typed): string
    {
        $value = mb_strtoupper(trim($typed), 'UTF-8');
        $value = (string) preg_replace('/[\s\-_.·–—]+/u', '', $value);
        $value = strtr($value, self::CYRILLIC_MAP);

        if (preg_match('/[0O1IL]/', $value) === 1) {
            throw InvalidInviteCode::ambiguous();
        }

        if (preg_match('/^['.self::ALPHABET.']+$/', $value) !== 1) {
            throw InvalidInviteCode::charset();
        }

        if (strlen($value) !== self::LENGTH) {
            throw InvalidInviteCode::length();
        }

        return $value;
    }

    /**
     * Normalise without throwing (null when the input can never be a valid code).
     */
    public function tryNormalize(string $typed): ?string
    {
        try {
            return $this->normalize($typed);
        } catch (InvalidInviteCode) {
            return null;
        }
    }

    public function hmac(string $normalizedCode): string
    {
        if (strlen($normalizedCode) !== self::LENGTH) {
            throw new InvalidArgumentException('hmac() expects a normalised code.');
        }

        return hash_hmac('sha256', 'invite:v1:'.$normalizedCode, Keys::invite());
    }
}
