<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pseudonymous identifiers: HMACs of e-mails and IPs (never stored in plaintext in tokens/audit).
 */
final class Identifiers
{
    public static function emailHmac(string $email): string
    {
        return hash_hmac('sha256', 'email:'.mb_strtolower(trim($email)), Keys::identifier());
    }

    public static function ipHmac(string $ip): string
    {
        return substr(hash_hmac('sha256', 'ip:'.$ip, Keys::identifier()), 0, 32);
    }

    /**
     * Rate-limit bucket for an IP: IPv4 as is, IPv6 truncated to its /64 prefix (PLANNING §4.5).
     */
    public static function ipBucket(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                return 'v6:'.bin2hex(substr($packed, 0, 8));
            }
        }

        return 'v4:'.$ip;
    }
}
