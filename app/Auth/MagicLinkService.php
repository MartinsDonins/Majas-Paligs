<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Support\Identifiers;
use App\Support\Keys;
use Illuminate\Support\Facades\DB;

/**
 * E-mail login (PLANNING §4.5, AUTH-001): a magic link `/login/confirm#t=TOKEN` (fragment — never reaches the
 * server log; GET shows a confirm page, POST logs in so mail scanners cannot burn it) plus a 6-digit code in
 * the same mail (iOS PWA cookie jar). Tokens are hashed, single-use and expire in 15 min. The response for an
 * unknown e-mail is identical (nothing is sent).
 */
class MagicLinkService
{
    public static function tokenHash(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', 'login-token:'.$token, Keys::identifier());
    }

    public static function codeHash(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', 'login-code:'.$code, Keys::identifier());
    }

    /**
     * Issue a login link + code for an existing account. Returns nothing the caller could use to tell whether
     * the account exists.
     */
    public function request(string $email, ?string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $user = User::query()->where('email', $email)->whereNull('anonymised_at')->first();

        if ($user === null) {
            return;
        }

        $this->issueFor($user, $ip);
    }

    /**
     * Issue a link + code for a known user (also used to finish an e-mail /join).
     */
    public function issueFor(User $user, ?string $ip): void
    {
        if ($user->email === null) {
            return;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $token, $code, $ip): void {
            // Only the newest link stays usable.
            LoginToken::query()
                ->where('user_id', $user->id)
                ->where('purpose', LoginTokenPurpose::Login->value)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            LoginToken::query()->create([
                'user_id' => $user->id,
                'email_hmac' => Identifiers::emailHmac((string) $user->email),
                'token_hash' => self::tokenHash($token),
                'code_hash' => self::codeHash($code),
                'purpose' => LoginTokenPurpose::Login->value,
                'expires_at' => now()->addMinutes((int) config('majas.magic_link.ttl_minutes', 15)),
                'ip_hmac' => $ip !== null ? Identifiers::ipHmac($ip) : null,
            ]);
        });

        $user->notify(new MagicLoginLink($token, $code));
    }

    /**
     * Consume a link token (POST /login/confirm). Atomic single use.
     */
    public function consumeToken(#[\SensitiveParameter] string $token): ?User
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $row = LoginToken::query()
            ->where('token_hash', self::tokenHash($token))
            ->where('purpose', LoginTokenPurpose::Login->value)
            ->first();

        if ($row === null || ! $row->isUsable()) {
            return null;
        }

        $claimed = LoginToken::query()->whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]);

        return $claimed === 1 ? $this->activeUser($row->user_id) : null;
    }

    /**
     * Consume the 6-digit code for an e-mail (POST /login/code). 5 attempts per token.
     */
    public function consumeCode(string $email, #[\SensitiveParameter] string $code): ?User
    {
        $code = (string) preg_replace('/\D/', '', $code);

        if (strlen($code) !== 6) {
            return null;
        }

        $row = LoginToken::query()
            ->where('email_hmac', Identifiers::emailHmac($email))
            ->where('purpose', LoginTokenPurpose::Login->value)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($row === null) {
            return null;
        }

        DB::table('login_tokens')->where('id', $row->id)->increment('attempts');
        $row->refresh();

        if ($row->attempts > (int) config('majas.magic_link.code_attempts', 5)) {
            LoginToken::query()->whereKey($row->id)->update(['used_at' => now()]);

            return null;
        }

        if (! hash_equals((string) $row->code_hash, self::codeHash($code))) {
            return null;
        }

        $claimed = LoginToken::query()->whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]);

        return $claimed === 1 ? $this->activeUser($row->user_id) : null;
    }

    private function activeUser(?int $id): ?User
    {
        if ($id === null) {
            return null;
        }

        $user = User::query()->find($id);

        return $user !== null && ! $user->isTombstone() ? $user : null;
    }
}
