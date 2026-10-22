<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Audit\AuditLogger;
use App\Auth\MagicLinkService;
use App\Auth\StepUpService;
use App\Casts\Bytea;
use App\Enums\BuildingRoleType;
use App\Enums\Locale;
use App\Enums\LoginTokenPurpose;
use App\Enums\PrivacyNoticeKind;
use App\Models\BuildingRole;
use App\Models\LoginToken;
use App\Models\PrivacyNoticeVersion;
use App\Models\RoleInvitation;
use App\Models\Section;
use App\Models\User;
use App\Notifications\TeamInvitation;
use App\Notifications\TeamInviteCode;
use App\Notifications\TeamMemberJoined;
use App\Services\Memberships\BuildingRoleService;
use App\Support\Identifiers;
use App\Support\Keys;
use App\Tenancy\CurrentBuilding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Komanda — inviting people to a building role by e-mail and handing administration over (ROUND2-SPEC §2.2,
 * ADMIN-006, D4/D5). Runs in the building context (manage panel, the accept page's `tenant` middleware, or the
 * platform bootstrap inside PlatformBuildingContext).
 *
 * - Issue / resend need building_admin (RoleInvitationPolicy) AND a strong step-up (passkey / TOTP of this session,
 *   ADR 0006) — the same bar as granting a role to someone else; the UI asks first, this is the backstop. Revoke: mfa.
 * - The token (32 random bytes) exists only in the mail fragment; the row keeps SHA-256(token). 7 days, single use,
 *   e-mail bound (HMAC compare), max 3 resends (each a new token).
 * - Accepting creates the account only after a 6-digit code sent to the invitation's address
 *   (LoginTokenPurpose::TeamInvite, bound to the invitation, shared wrong-code budget with login codes).
 * - Audit data carries ids, role and stairwells only — never the e-mail.
 */
final class TeamInvitationService
{
    public const TTL_DAYS = 7;

    public const MAX_RESENDS = 3;

    public const DAILY_QUOTA = 20;

    public function __construct(
        private readonly BuildingRoleService $roles,
        private readonly StepUpService $stepUp,
    ) {}

    public static function emailHmac(string $email): string
    {
        return hash_hmac('sha256', 'role-invite:'.mb_strtolower(trim($email)), Keys::identifier(), true);
    }

    public static function tokenHash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token, true);
    }

    /**
     * Issue an invitation and mail it. $platformBootstrap: a platform_admin inviting the FIRST building_admin from the
     * platform panel (PlatformBuildingContext, PlatformPurpose::RoleBootstrap) — building_admin role only.
     *
     * @param  list<int>  $sectionIds  stairwells (section_elder only)
     *
     * @throws TeamInvitationRefused|AuthorizationException
     */
    public function invite(User $actor, string $email, BuildingRoleType $role, array $sectionIds = [], ?string $mandateRef = null, Locale $locale = Locale::Lv, bool $platformBootstrap = false): RoleInvitation
    {
        $this->authorise($actor, 'create', $platformBootstrap, $role);

        $email = mb_strtolower(trim($email));
        if ($role === BuildingRoleType::ManagerViewer) {
            throw new AuthorizationException('manager_viewer is never offered by invitation.');
        }
        if ($actor->email !== null && hash_equals(mb_strtolower($actor->email), $email)) {
            throw new TeamInvitationRefused('self_invite');
        }

        $sectionIds = $this->normaliseSections($role, $sectionIds);
        $hmac = self::emailHmac($email);

        $existing = User::query()->where('email', $email)->whereNull('anonymised_at')->first();
        if ($existing !== null && $this->holdsRole($existing, $role, $sectionIds)) {
            throw new TeamInvitationRefused('role_already_held');
        }

        return DB::transaction(function () use ($actor, $email, $hmac, $role, $sectionIds, $mandateRef, $locale): RoleInvitation {
            // One issuer at a time per building (quota + duplicate check are read-then-write).
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['role-invite:'.CurrentBuilding::id()]);

            $open = RoleInvitation::query()->open()
                ->where('email_hmac', Bytea::literal($hmac))
                ->where('role', $role->value)
                ->get()
                ->filter(fn (RoleInvitation $i): bool => $role !== BuildingRoleType::SectionElder || array_intersect($i->section_ids, $sectionIds) !== []);

            foreach ($open as $previous) {
                if ($previous->expires_at->isFuture()) {
                    throw new TeamInvitationRefused('invite_pending');
                }
                // An expired, never-closed invitation must not block a new one (partial unique index).
                $previous->forceFill(['revoked_at' => now()])->save();
            }

            // Midnight in Riga, sent as UTC: Eloquent writes timestamps without an offset and the DB session is UTC.
            $today = RoleInvitation::query()->where('created_at', '>=', now()->setTimezone('Europe/Riga')->startOfDay()->utc())->count();
            if ($today >= self::DAILY_QUOTA) {
                throw new TeamInvitationRefused('team_invite_quota');
            }

            $token = self::newToken();
            $invitation = RoleInvitation::query()->create([
                'email_ciphertext' => $email,
                'email_hmac' => $hmac,
                'email_hint' => RoleInvitation::hint($email),
                'role' => $role,
                'section_ids' => $sectionIds,
                'mandate_ref' => $mandateRef !== null && trim($mandateRef) !== '' ? mb_substr(trim($mandateRef), 0, 200) : null,
                'locale' => $locale,
                'token_hash' => self::tokenHash($token),
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);

            AuditLogger::record('team.invite.create', $invitation, data: [
                'role' => $role->value,
                'section_ids' => $sectionIds,
                'invitation_id' => $invitation->id,
            ], actorUserId: (int) $actor->id);

            $this->mail($invitation, $email, $token);

            return $invitation;
        });
    }

    /**
     * New token + 7 more days (the old link stops working at once). Max 3 times. $platformBootstrap: a platform_admin
     * re-sending a building_admin invitation (platform panel / MCP, inside PlatformBuildingContext) — building_admin
     * invitations only, like invite().
     *
     * @throws TeamInvitationRefused|AuthorizationException
     */
    public function resend(User $actor, RoleInvitation $invitation, bool $platformBootstrap = false): void
    {
        $this->authorise($actor, 'resend', $platformBootstrap, $invitation->role, $invitation);

        DB::transaction(function () use ($actor, $invitation): void {
            /** @var RoleInvitation $row */
            $row = RoleInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if ($row->accepted_at !== null || $row->revoked_at !== null) {
                throw new TeamInvitationRefused('not_open');
            }
            if ($row->resend_count >= self::MAX_RESENDS) {
                throw new TeamInvitationRefused('resend_limit');
            }

            $token = self::newToken();
            $row->forceFill([
                'token_hash' => self::tokenHash($token),
                'expires_at' => now()->addDays(self::TTL_DAYS),
                'resent_at' => now(),
                'resend_count' => $row->resend_count + 1,
            ])->save();

            // Codes issued for the old link die with it.
            LoginToken::query()->where('role_invitation_id', $row->id)->whereNull('used_at')->update(['used_at' => now()]);

            AuditLogger::record('team.invite.resend', $row, data: ['invitation_id' => $row->id, 'resend_count' => $row->resend_count], actorUserId: (int) $actor->id);

            $this->mail($row, $row->email(), $token);
        });

        $invitation->refresh();
    }

    /**
     * @throws TeamInvitationRefused|AuthorizationException
     */
    public function revoke(User $actor, RoleInvitation $invitation): void
    {
        $this->authorise($actor, 'revoke', false, $invitation->role, $invitation);

        $claimed = RoleInvitation::query()->whereKey($invitation->id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw new TeamInvitationRefused('not_open');
        }

        LoginToken::query()->where('role_invitation_id', $invitation->id)->whereNull('used_at')->update(['used_at' => now()]);
        $invitation->refresh();
        AuditLogger::record('team.invite.revoke', $invitation, data: ['invitation_id' => $invitation->id], actorUserId: (int) $actor->id);
    }

    // ---- Accepting (GET /team-invite/{slug}#t=TOKEN, Livewire Team\AcceptInvite) ----

    /**
     * The usable invitation for a token in the CURRENT building (another building's token is simply not found).
     */
    public function resolve(#[\SensitiveParameter] string $token): ?RoleInvitation
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $row = RoleInvitation::query()->where('token_hash', Bytea::literal(self::tokenHash($token)))->first();

        return $row !== null && $row->isUsable() ? $row : null;
    }

    /**
     * Re-read an invitation the accept page remembered (by id + token hash): null once used, revoked, resent or expired.
     */
    public function reload(string $id, string $tokenHash): ?RoleInvitation
    {
        $row = RoleInvitation::query()->whereKey($id)->first();

        return $row !== null && $row->isUsable() && hash_equals((string) $row->token_hash, $tokenHash) ? $row : null;
    }

    public function emailMatches(RoleInvitation $invitation, User $user): bool
    {
        return $user->email !== null && ! $user->isTombstone()
            && hash_equals((string) $invitation->email_hmac, self::emailHmac($user->email));
    }

    /**
     * The account that already uses the invitation's address, if any.
     */
    public function existingAccount(RoleInvitation $invitation): ?User
    {
        return User::query()->where('email', $invitation->email())->whereNull('anonymised_at')->first();
    }

    /**
     * Mail a 6-digit code (15 min) to the invitation's address. False when the address used up its wrong-code budget.
     */
    public function sendCode(RoleInvitation $invitation, ?string $ip): bool
    {
        $email = $invitation->email();
        $emailHmac = Identifiers::emailHmac($email);

        if (MagicLinkService::codeLoginLocked($emailHmac)) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($invitation, $emailHmac, $code, $ip): void {
            LoginToken::query()->where('role_invitation_id', $invitation->id)->whereNull('used_at')->update(['used_at' => now()]);

            LoginToken::query()->create([
                'user_id' => $this->existingAccount($invitation)?->id,
                'email_hmac' => $emailHmac,
                'code_hash' => MagicLinkService::codeHash($code),
                'purpose' => LoginTokenPurpose::TeamInvite->value,
                'role_invitation_id' => $invitation->id,
                'expires_at' => now()->addMinutes((int) config('majas.magic_link.ttl_minutes', 15)),
                'ip_hmac' => $ip !== null ? Identifiers::ipHmac($ip) : null,
            ]);
        });

        Notification::route('mail', $email)->notify((new TeamInviteCode($code))->locale($invitation->locale->value));

        return true;
    }

    /**
     * Check the code for exactly this invitation (5 attempts per code, shared 10/24 h budget per address).
     */
    public function verifyCode(RoleInvitation $invitation, #[\SensitiveParameter] string $code): bool
    {
        $code = (string) preg_replace('/\D/', '', $code);
        $emailHmac = Identifiers::emailHmac($invitation->email());

        if (strlen($code) !== 6 || MagicLinkService::codeLoginLocked($emailHmac)) {
            return false;
        }

        $row = LoginToken::query()
            ->where('role_invitation_id', $invitation->id)
            ->where('purpose', LoginTokenPurpose::TeamInvite->value)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($row === null) {
            return false;
        }

        DB::table('login_tokens')->where('id', $row->id)->increment('attempts');
        $row->refresh();

        if ($row->attempts > (int) config('majas.magic_link.code_attempts', 5)) {
            LoginToken::query()->whereKey($row->id)->update(['used_at' => now()]);

            return false;
        }

        if (! hash_equals((string) $row->code_hash, MagicLinkService::codeHash($code))) {
            MagicLinkService::registerCodeFailure($emailHmac);

            return false;
        }

        return LoginToken::query()->whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) === 1;
    }

    /**
     * New platform account for a verified invitation address (only after verifyCode, 18+ and the account notice).
     */
    public function createAccount(RoleInvitation $invitation): User
    {
        $existing = $this->existingAccount($invitation);
        if ($existing !== null) {
            return $existing;
        }

        $user = User::query()->create([
            'email' => $invitation->email(),
            'locale' => $invitation->locale->value,
            'is_adult_confirmed_at' => now(),
            'notice_version_accepted' => PrivacyNoticeVersion::current(PrivacyNoticeKind::PlatformAccount)?->version,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        AuditLogger::record('account.created', $user, [(int) $user->id], data: ['via' => 'team_invite'], platform: true, actorRole: 'self', actorUserId: (int) $user->id);

        return $user;
    }

    /**
     * Grant the invited role(s) to $user (whose e-mail must match) and close the invitation.
     *
     * @return list<BuildingRole>
     *
     * @throws TeamInvitationRefused
     */
    public function accept(User $user, RoleInvitation $invitation): array
    {
        if (! $this->emailMatches($invitation, $user)) {
            throw new TeamInvitationRefused('wrong_email');
        }

        $granted = DB::transaction(function () use ($user, $invitation): array {
            /** @var RoleInvitation|null $row */
            $row = RoleInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();
            if ($row === null || ! $row->isUsable() || ! hash_equals((string) $row->token_hash, (string) $invitation->token_hash)) {
                throw new TeamInvitationRefused('not_open');
            }

            $inviter = $row->invited_by !== null ? User::query()->find($row->invited_by) : null;
            $inviter = $inviter !== null && ! $inviter->isTombstone() ? $inviter : null;

            $grants = [];
            foreach ($row->role === BuildingRoleType::SectionElder ? $row->section_ids : [null] as $sectionId) {
                $grants[] = $this->roles->grant($inviter, $user, $row->role, $sectionId, $row->mandate_ref, via: $row);
            }

            $row->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            LoginToken::query()->where('role_invitation_id', $row->id)->whereNull('used_at')->update(['used_at' => now()]);

            AuditLogger::record('team.invite.accept', $row, [(int) $user->id], data: [
                'invitation_id' => $row->id,
                'role' => $row->role->value,
                'section_ids' => $row->section_ids,
            ], actorUserId: (int) $user->id);

            return $grants;
        });

        $this->notifyTeam($user, $invitation);

        return $granted;
    }

    // ---- internals ----

    /**
     * @throws AuthorizationException
     */
    private function authorise(User $actor, string $ability, bool $platformBootstrap, BuildingRoleType $role, ?RoleInvitation $invitation = null): void
    {
        $platform = $platformBootstrap && $actor->isPlatformAdmin() && $role === BuildingRoleType::BuildingAdmin;

        if (! $platform && ! Gate::forUser($actor)->allows($ability, $invitation ?? RoleInvitation::class)) {
            throw new AuthorizationException('Only a building_admin manages team invitations.');
        }

        // Issue / resend = granting a role to someone else (ADR 0006): passkey / TOTP of this session. Revoke: mfa.
        $interactive = ! app()->runningInConsole() || app()->runningUnitTests();
        $fresh = $ability === 'revoke' ? $this->stepUp->hasFreshMfa($actor) : $this->stepUp->hasFreshStrongMfa($actor);
        if ($interactive && ! $fresh) {
            throw new AuthorizationException('A fresh second factor is required for team invitations.');
        }
    }

    /**
     * @param  list<int>  $sectionIds
     * @return list<int>
     *
     * @throws TeamInvitationRefused
     */
    private function normaliseSections(BuildingRoleType $role, array $sectionIds): array
    {
        if ($role !== BuildingRoleType::SectionElder) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $sectionIds)));
        sort($ids);

        if ($ids === [] || Section::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw new TeamInvitationRefused('invalid_section');
        }

        return $ids;
    }

    /**
     * @param  list<int>  $sectionIds
     */
    private function holdsRole(User $user, BuildingRoleType $role, array $sectionIds): bool
    {
        $query = BuildingRole::query()->current()->where('user_id', $user->id)->where('role', $role->value);

        if ($role === BuildingRoleType::SectionElder) {
            // Only when EVERY requested stairwell is already held; otherwise the invitation adds the missing ones.
            return $query->whereIn('section_id', $sectionIds)->count() === count($sectionIds);
        }

        return $query->exists();
    }

    private function mail(RoleInvitation $invitation, string $email, #[\SensitiveParameter] string $token): void
    {
        $building = CurrentBuilding::get();
        $sections = Section::query()->whereIn('id', $invitation->section_ids)->orderBy('number')->pluck('number')->map(fn ($n): int => (int) $n)->all();

        Notification::route('mail', $email)->notify((new TeamInvitation(
            buildingName: (string) $building->name,
            slug: (string) $building->slug,
            role: $invitation->role->value,
            sectionNumbers: $sections,
            token: $token,
            expiresAt: $invitation->expires_at,
        ))->locale($invitation->locale->value));
    }

    /**
     * "Komandai pievienots jauns administrators" — to the inviter and every other building_admin (no names, no
     * e-mails, no counts).
     */
    private function notifyTeam(User $newHolder, RoleInvitation $invitation): void
    {
        $adminIds = BuildingRole::query()->current()->where('role', BuildingRoleType::BuildingAdmin->value)->pluck('user_id')->all();

        $recipients = User::query()
            ->whereIn('id', array_filter([...$adminIds, $invitation->invited_by]))
            ->whereKeyNot($newHolder->id)
            ->whereNull('anonymised_at')
            ->whereNotNull('email')
            ->get();

        Notification::send($recipients, new TeamMemberJoined((string) CurrentBuilding::get()->name, $invitation->role->value));
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
