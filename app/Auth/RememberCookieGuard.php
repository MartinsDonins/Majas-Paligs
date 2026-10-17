<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\SessionGuard;

/**
 * Session guard with a fixed `__Host-` prefixed remember cookie: sibling *.coredigify.com sites cannot toss it
 * (PLANNING §4.1). Laravel's default name embeds the guard name + class hash.
 */
final class RememberCookieGuard extends SessionGuard
{
    public const COOKIE = '__Host-mp_remember';

    public function getRecallerName(): string
    {
        return self::COOKIE;
    }
}
