<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Auth\SessionEpoch;
use App\Models\Passkey;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\SecurityChangedNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * majas:reset-mfa {email} — recovery when an administrator lost every device with a passkey and has no working
 * authenticator app (ADR 0006). Console only (shell access to the production container = CoreDigify ops, after
 * verifying the person out of band). Removes all passkeys, the TOTP secret and all trusted devices, bumps the session
 * epoch (every session and remember cookie ends), writes the platform audit chain and mails the account. The person
 * then signs in with the e-mail code and creates a new passkey within 15 minutes (StepUpService::mayAddFactor).
 */
#[AsCommand(name: 'majas:reset-mfa')]
final class ResetSignInFactors extends Command
{
    protected $signature = 'majas:reset-mfa {email} {--force : Do not ask for confirmation}';

    protected $description = 'Remove all passkeys, the authenticator app and trusted devices of one account (lost devices).';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->whereNull('anonymised_at')->first();

        if ($user === null) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $passkeys = $user->passkeys()->count();
        $devices = TrustedDevice::query()->where('user_id', $user->id)->count();
        $totp = $user->hasTotp();

        $this->line("Account #{$user->id}: {$passkeys} passkey(s), ".($totp ? 'an' : 'no').' authenticator app, '."{$devices} trusted device(s).");

        if (! $this->option('force') && ! $this->confirm('Remove all of them and sign the account out everywhere?')) {
            return self::FAILURE;
        }

        DB::transaction(static function () use ($user): void {
            Passkey::query()->where('authenticatable_id', $user->id)->delete();
            TrustedDevice::query()->where('user_id', $user->id)->delete();
            $user->forceFill(['app_authentication_secret' => null])->save();
            SessionEpoch::bump($user);
        });

        AuditLogger::record('account.factors_reset', $user, [(int) $user->id], data: [
            'passkeys' => $passkeys,
            'totp' => $totp,
            'trusted_devices' => $devices,
        ], platform: true, actorRole: 'console');
        $user->notify(new SecurityChangedNotice('factors_reset'));

        $this->info('Done. The person signs in with the e-mail code and creates a new passkey right after.');

        return self::SUCCESS;
    }
}
