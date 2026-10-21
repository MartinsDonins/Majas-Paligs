<?php

declare(strict_types=1);

use App\Auth\Passkeys\StrictCeremonyFactory;
use App\Models\Passkey;
use App\Models\User;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;

/*
 * spatie/laravel-passkeys (ADR 0006). We use the package's model, serializer and verification actions only: the
 * package's routes, Blade components and Livewire component are NOT registered (inline scripts would break the strict
 * nonce CSP, and its login controller bypasses LoginCompleter). Ceremonies: App\Auth\Passkeys\PasskeyService.
 */
return [
    'redirect_to_after_login' => '/home',

    'actions' => [
        'generate_passkey_register_options' => GeneratePasskeyRegisterOptionsAction::class,
        'store_passkey' => StorePasskeyAction::class,
        'generate_passkey_authentication_options' => GeneratePasskeyAuthenticationOptionsAction::class,
        'find_passkey' => FindPasskeyToAuthenticateAction::class,
        // Exact-origin check (APP_URL scheme + host + port) instead of the library's "rpId or any subdomain".
        'configure_ceremony_step_manager_factory' => StrictCeremonyFactory::class,
    ],

    'relying_party' => [
        'name' => env('APP_NAME', 'Mājas Palīgs'),
        // The RP ID is bound into every passkey: moving to the own domain means re-registering passkeys (ADR 0006).
        'id' => parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        'icon' => null,
    ],

    'models' => [
        'passkey' => Passkey::class,
        'authenticatable' => User::class,
    ],
];
