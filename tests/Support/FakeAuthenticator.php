<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A software WebAuthn authenticator for feature tests (ADR 0006): ES256 key pair, "none" attestation, real CBOR,
 * real signatures — so PasskeyService is exercised end-to-end through web-auth/webauthn-lib, exactly as a phone would
 * answer. Flags can be switched off (no user verification) and the counter rewound (cloned key) to test refusals.
 */
final class FakeAuthenticator
{
    private OpenSSLAsymmetricKey $key;

    public string $credentialId;

    public int $counter = 0;

    public function __construct(public string $userHandle = '')
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('openssl EC key generation failed');
        }
        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /**
     * RegistrationResponseJSON for the creation options the server issued.
     */
    public function register(string $optionsJson, string $origin = 'http://localhost', bool $userVerified = true): string
    {
        /** @var array{challenge: string, rp: array{id: string}, user: array{id: string}} $options */
        $options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
        $this->userHandle = self::b64d($options['user']['id']);

        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $flags = 0x01 | 0x40 | ($userVerified ? 0x04 : 0); // UP | AT | UV
        $authData = hash('sha256', $options['rp']['id'], true).chr($flags).pack('N', $this->counter)
            .str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$this->coseKey();

        $attestation = self::map([
            [self::text('fmt'), self::text('none')],
            [self::text('attStmt'), self::map([])],
            [self::text('authData'), self::bytes($authData)],
        ]);

        return json_encode([
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'attestationObject' => self::b64($attestation),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => (object) [],
            'authenticatorAttachment' => 'platform',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * AuthenticationResponseJSON for the request options the server issued.
     */
    public function assert(string $optionsJson, string $origin = 'http://localhost', bool $userVerified = true, ?int $counter = null): string
    {
        /** @var array{challenge: string, rpId: string} $options */
        $options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
        $this->counter = $counter ?? $this->counter + 1;

        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $authData = hash('sha256', $options['rpId'], true).chr(0x01 | ($userVerified ? 0x04 : 0)).pack('N', $this->counter);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return json_encode([
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64((string) $signature),
                'userHandle' => self::b64($this->userHandle),
            ],
            'clientExtensionResults' => (object) [],
            'authenticatorAttachment' => 'platform',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('no EC details');
        }

        return self::map([
            [self::int(1), self::int(2)],      // kty: EC2
            [self::int(3), self::int(-7)],     // alg: ES256
            [self::int(-1), self::int(1)],     // crv: P-256
            [self::int(-2), self::bytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT))],
            [self::int(-3), self::bytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))],
        ]);
    }

    // ---- minimal CBOR (RFC 8949) ----

    private static function head(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => chr(($major << 5) | $length),
            $length < 256 => chr(($major << 5) | 24).chr($length),
            $length < 65536 => chr(($major << 5) | 25).pack('n', $length),
            default => chr(($major << 5) | 26).pack('N', $length),
        };
    }

    private static function int(int $v): string
    {
        return $v >= 0 ? self::head(0, $v) : self::head(1, -1 - $v);
    }

    private static function bytes(string $v): string
    {
        return self::head(2, strlen($v)).$v;
    }

    private static function text(string $v): string
    {
        return self::head(3, strlen($v)).$v;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs  encoded key / value pairs
     */
    private static function map(array $pairs): string
    {
        return self::head(5, count($pairs)).implode('', array_map(static fn (array $p): string => $p[0].$p[1], $pairs));
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64d(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
