<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Auth\SessionEpoch;
use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Console\Command;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * majas:platform-admin {email} — bootstrap a platform admin (console only). Prints a TOTP secret ONCE for the
 * authenticator app; the platform panel requires a fresh TOTP step-up.
 */
#[AsCommand(name: 'majas:platform-admin')]
final class MakePlatformAdmin extends Command
{
    protected $signature = 'majas:platform-admin {email}
        {--reset-totp : Replace an existing authenticator secret (the old phone entry stops working)}
        {--no-totp : Deprecated, kept for scripts: an existing secret is now always kept unless --reset-totp}';

    protected $description = 'Create or promote a platform admin (prints a one-time TOTP secret when none exists).';

    public function handle(Google2FA $google2fa): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Invalid e-mail.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->whereNull('anonymised_at')->first()
            ?? User::query()->create(['email' => $email]);

        $user->forceFill(['platform_role' => PlatformRole::PlatformAdmin->value])->save();

        // Re-running the command used to replace the secret silently, so the authenticator entry the owner had just
        // added stopped working (QA 2026-10-20). Now an existing secret is kept unless --reset-totp is given.
        if ($user->hasTotp() && ! $this->option('reset-totp')) {
            $this->info('The account already has an authenticator; it is kept. Use --reset-totp to create a new one.');
        } else {
            $replacing = $user->hasTotp();
            $secret = $google2fa->generateSecretKey(32);
            $user->forceFill(['app_authentication_secret' => $secret])->save();
            if ($replacing) {
                // A TOTP change ends the account's other sessions and remember cookies (SEC-008).
                SessionEpoch::bump($user);
                AuditLogger::record('account.totp_enabled', $user, [(int) $user->id], platform: true, actorRole: 'console');
            }
            $this->warn('TOTP secret (add to your authenticator app now, it is not shown again):');
            $this->line(trim(chunk_split($secret, 4, ' ')));
            $this->line($google2fa->getQRCodeUrl((string) config('app.name'), $email, $secret));
        }

        AuditLogger::record('platform.role_change', $user, [(int) $user->id], data: ['admin' => true], platform: true, actorRole: 'console');
        $this->info('Platform admin ready.');
        // ADR 0006: the simple way in. The authenticator stays the fallback.
        $this->line('Tip: sign in once with the e-mail code, then create a passkey (fingerprint / Face ID) when offered,');
        $this->line('or later under '.route('me.security').' — from then on one tap replaces the e-mail and the code.');

        return self::SUCCESS;
    }
}
