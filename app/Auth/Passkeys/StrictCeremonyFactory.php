<?php

declare(strict_types=1);

namespace App\Auth\Passkeys;

use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * WebAuthn ceremonies accept exactly one origin: APP_URL (scheme + host + port). The library default accepts the
 * RP ID and every subdomain of it; on a shared parent domain (*.coredigify.com hosts client sites) that is one
 * assumption too many (ADR 0006).
 */
class StrictCeremonyFactory extends ConfigureCeremonyStepManagerFactoryAction
{
    public function execute(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([self::origin()], allowSubdomains: false);

        return $factory;
    }

    public static function origin(): string
    {
        $url = parse_url((string) config('app.url'));
        $scheme = is_array($url) ? ($url['scheme'] ?? 'https') : 'https';
        $host = is_array($url) ? ($url['host'] ?? 'localhost') : 'localhost';
        $port = is_array($url) && isset($url['port']) ? ':'.$url['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
