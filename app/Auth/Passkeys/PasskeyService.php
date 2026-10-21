<?php

declare(strict_types=1);

namespace App\Auth\Passkeys;

use App\Audit\AuditLogger;
use App\Auth\SessionEpoch;
use App\Models\Passkey;
use App\Models\User;
use App\Notifications\SecurityChangedNotice;
use Illuminate\Database\UniqueConstraintViolationException;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Throwable;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkey ceremonies (ADR 0006). Options live in the server session only, are single-use (pulled on verify) and
 * expire after 5 minutes; the challenge is 32 random bytes. User verification is REQUIRED (fingerprint, face or the
 * phone's screen lock), so a passkey proves possession + a local check — that is why a passkey login or tap counts
 * as MFA (StepUpService 'passkey'). Exact-origin check: StrictCeremonyFactory. The signature counter is enforced by
 * the library (a cloned authenticator is refused).
 *
 * Who may ADD a passkey is decided by the caller (StepUpService::mayAddFactor): an account with a second factor
 * needs a fresh MFA, an account without one needs a fresh first-factor proof — exactly like the authenticator app.
 */
final class PasskeyService
{
    public const REGISTER_KEY = 'mp.passkey.register';

    public const ASSERT_KEY = 'mp.passkey.assert';

    public const MAX_PER_USER = 10;

    private const OPTIONS_TTL_SECONDS = 300;

    /**
     * Creation options for the browser (PublicKeyCredentialCreationOptionsJSON).
     */
    public function registrationOptions(User $user): string
    {
        $rp = new PublicKeyCredentialRpEntity(name: '', id: Config::getRelyingPartyId());
        $rp->name = Config::getRelyingPartyName();

        $options = new PublicKeyCredentialCreationOptions(
            rp: $rp,
            user: new PublicKeyCredentialUserEntity(
                name: $user->getPassKeyName(),
                id: $user->getPassKeyId(),
                displayName: $user->getPassKeyDisplayName(),
            ),
            challenge: random_bytes(32),
            pubKeyCredParams: [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            authenticatorSelection: new AuthenticatorSelectionCriteria(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $this->descriptors($user),
            timeout: 120_000,
        );

        $json = Serializer::make()->toJson($options);
        session()->put(self::REGISTER_KEY, ['user' => (int) $user->id, 'at' => now()->getTimestamp(), 'options' => $json]);

        return $json;
    }

    /**
     * Verify the browser's attestation and store the passkey. Null when the response does not match the options
     * this session issued (wrong origin, no user verification, replayed or expired challenge, duplicate key).
     */
    public function register(User $user, string $responseJson, ?string $userAgent): ?Passkey
    {
        $pending = session()->pull(self::REGISTER_KEY);

        if (! is_array($pending) || (int) ($pending['user'] ?? 0) !== (int) $user->id
            || (int) ($pending['at'] ?? 0) < now()->subSeconds(self::OPTIONS_TTL_SECONDS)->getTimestamp()
            || $user->isTombstone() || $user->passkeys()->count() >= self::MAX_PER_USER) {
            return null;
        }

        try {
            /** @var Passkey $passkey */
            $passkey = (new StorePasskeyAction)->execute(
                $user,
                $responseJson,
                (string) $pending['options'],
                Config::getRelyingPartyId(),
                ['name' => self::deviceLabel($userAgent)],
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        AuditLogger::record('account.passkey_added', $passkey, [(int) $user->id], data: ['passkey_id' => (int) $passkey->id], platform: true, actorRole: 'self');
        $user->notify(new SecurityChangedNotice('passkey_added'));

        return $passkey;
    }

    /**
     * Request options. With a user (step-up) only that account's passkeys are allowed; without one (login page,
     * conditional UI) the phone offers its discoverable passkeys for this site.
     */
    public function assertionOptions(?User $user = null): string
    {
        $options = new PublicKeyCredentialRequestOptions(
            challenge: random_bytes(32),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: $user === null ? [] : $this->descriptors($user),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: 120_000,
        );

        $json = Serializer::make()->toJson($options);
        session()->put(self::ASSERT_KEY, ['user' => $user?->id, 'at' => now()->getTimestamp(), 'options' => $json]);

        return $json;
    }

    /**
     * The verified passkey (counter and last_used_at updated), or null. With $expected the passkey must belong to
     * that account (step-up); the account must be live.
     */
    public function verifyAssertion(string $responseJson, ?User $expected = null): ?Passkey
    {
        $pending = session()->pull(self::ASSERT_KEY);

        if (! is_array($pending) || (int) ($pending['at'] ?? 0) < now()->subSeconds(self::OPTIONS_TTL_SECONDS)->getTimestamp()) {
            return null;
        }

        if ($expected !== null && (int) ($pending['user'] ?? 0) !== (int) $expected->id) {
            return null;
        }

        if (! json_validate($responseJson)) {
            return null;
        }

        try {
            /** @var Passkey|null $passkey */
            $passkey = (new FindPasskeyToAuthenticateAction)->execute($responseJson, (string) $pending['options']);
        } catch (Throwable) {
            return null;
        }

        if ($passkey === null) {
            return null;
        }

        $owner = User::query()->find($passkey->authenticatable_id);

        if ($owner === null || $owner->isTombstone() || ($expected !== null && (int) $owner->id !== (int) $expected->id)) {
            return null;
        }

        return $passkey;
    }

    /**
     * Remove one passkey. Ends every other session and remember cookie and every trusted device of the account
     * (session epoch), mails the account and writes the platform audit chain. The caller checks the fresh MFA.
     */
    public function remove(User $user, Passkey $passkey): void
    {
        if ((int) $passkey->authenticatable_id !== (int) $user->id) {
            return;
        }

        $id = (int) $passkey->id;
        $passkey->delete();
        AuditLogger::record('account.passkey_removed', ['passkey', $id], [(int) $user->id], data: ['passkey_id' => $id], platform: true, actorRole: 'self');
        SessionEpoch::bump($user);
        $user->notify(new SecurityChangedNotice('passkey_removed'));
    }

    /**
     * A short, non-identifying device description ("Chrome · Android") for the lists in "Ieeja un drošība".
     */
    public static function deviceLabel(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        $label = implode(' · ', array_filter([$browser, $os]));

        return $label !== '' ? $label : __('me.security.device_unknown');
    }

    /**
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptors(User $user): array
    {
        return $user->passkeys()->get()
            ->map(static fn (Passkey $p): PublicKeyCredentialDescriptor => new PublicKeyCredentialDescriptor(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $p->data->publicKeyCredentialId,
                $p->data->transports,
            ))
            ->values()
            ->all();
    }
}
