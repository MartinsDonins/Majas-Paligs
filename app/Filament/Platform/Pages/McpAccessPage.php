<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Auth\StepUpService;
use App\Mcp\McpTokenService;
use App\Models\McpToken;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * "MCP piekļuve" (round 3, docs/MCP.md, ADR 0008): the platform_admin creates and revokes personal access tokens for
 * the in-app MCP server (/mcp) and copies the Claude Code command. Creating a token = a new credential: one passkey
 * tap / TOTP code of this session (StepUpService::demandStrong, backstop in McpTokenService::issue). The secret is
 * shown ONCE on this page and only its SHA-256 is stored; leaving or any other action clears it.
 */
final class McpAccessPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?int $navigationSort = 33;

    protected static ?string $slug = 'mcp';

    protected string $view = 'filament.platform.pages.mcp-access';

    public string $label = '';

    public bool $write = false;

    public ?int $validDays = 90;

    /**
     * The plaintext of the token just created. PROTECTED on purpose: Livewire does not dehydrate it, so it is rendered
     * into this one response and never enters the component snapshot that the browser sends back with every request.
     */
    protected ?string $plaintext = null;

    protected ?string $createdLabel = null;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.mcp.title');
    }

    public function getTitle(): string
    {
        return __('admin.mcp.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isPlatformAdmin();
    }

    public function create(McpTokenService $tokens, StepUpService $stepUp): void
    {
        abort_unless(self::canAccess(), 403);
        /** @var User $actor */
        $actor = auth()->user();
        $this->forgetSecret();

        if (! $stepUp->demandStrong($actor, $this)) {
            return;
        }

        $days = $this->validDays !== null && $this->validDays > 0 ? $this->validDays : null;
        if ($this->write && $days === null) {
            $days = McpTokenService::MAX_WRITE_DAYS;
        }

        try {
            $issued = $tokens->issue($actor, $this->label, $this->write ? ['read', 'write'] : ['read'], $days);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->plaintext = $issued['plaintext'];
        $this->createdLabel = $issued['token']->label;
        $this->label = '';
        $this->write = false;
        $this->validDays = 90;

        Notification::make()->success()->title(__('admin.mcp.created'))->send();
    }

    /**
     * A write token lives at most 30 days: ticking "Atļaut izmaiņas" moves the default 90 to 30 (and back).
     */
    public function updatedWrite(bool $value): void
    {
        if ($value && ($this->validDays === null || $this->validDays > McpTokenService::MAX_WRITE_DAYS)) {
            $this->validDays = McpTokenService::MAX_WRITE_DAYS;
        } elseif (! $value && $this->validDays === McpTokenService::MAX_WRITE_DAYS) {
            $this->validDays = 90;
        }
    }

    public function revoke(string $tokenId, McpTokenService $tokens): void
    {
        abort_unless(self::canAccess(), 403);
        /** @var User $actor */
        $actor = auth()->user();
        $this->forgetSecret();

        $token = McpToken::query()->whereKey($tokenId)->where('user_id', $actor->id)->first();
        if ($token === null) {
            return;
        }

        try {
            $tokens->revoke($actor, $token);
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('admin.mcp.revoked'))->send();
    }

    public function forgetSecret(): void
    {
        $this->plaintext = null;
        $this->createdLabel = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['plaintext' => $this->plaintext, 'createdLabel' => $this->createdLabel];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return app(McpTokenService::class)->listFor($user);
    }

    public function endpoint(): string
    {
        return url('/mcp');
    }
}
