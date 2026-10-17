<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditLogger;
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
    protected $signature = 'majas:platform-admin {email} {--no-totp : Keep an existing TOTP secret}';

    protected $description = 'Create or promote a platform admin (prints a one-time TOTP secret).';

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

        if (! $this->option('no-totp') || ! $user->hasTotp()) {
            $secret = $google2fa->generateSecretKey(32);
            $user->forceFill(['app_authentication_secret' => $secret])->save();
            $this->warn('TOTP secret (add to your authenticator app now, it is not shown again):');
            $this->line(trim(chunk_split($secret, 4, ' ')));
            $this->line($google2fa->getQRCodeUrl((string) config('app.name'), $email, $secret));
        }

        AuditLogger::record('platform.role_change', $user, [(int) $user->id], data: ['admin' => true], platform: true, actorRole: 'console');
        $this->info('Platform admin ready.');

        return self::SUCCESS;
    }
}
