<?php

declare(strict_types=1);

use App\Models\User;

/*
 * Passwordless authentication (PLANNING §4.5): invite code → e-mail magic link + code, or handle + PIN.
 * No password brokers. The `mp-session` driver is Laravel's session guard with the `__Host-mp_remember`
 * remember cookie (App\Auth\RememberCookieGuard).
 */
return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],

    'guards' => [
        'web' => [
            'driver' => 'mp-session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [],

    /* "Atcerēties šo telefonu": remember token lifetime (minutes), revocable in "Mans dzīvoklis". */
    'remember_minutes' => (int) env('AUTH_REMEMBER_MINUTES', 60 * 24 * 180),

    'password_timeout' => 10800,
];
