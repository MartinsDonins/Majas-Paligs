<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Casts\Bytea;
use App\Filament\Platform\Pages\McpAccessPage;
use App\Mcp\McpTokenService;
use App\Models\AuditEntry;
use App\Models\McpToken;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * "MCP piekļuve" (/admin/platform/mcp) and McpTokenService: create behind a strong step-up, shown once, hashed at
 * rest, optional expiry (write tokens ≤ 30 days), revoke, audit.
 */

beforeEach(function (): void {
    $this->admin = mcpAdmin();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('platform'));
});

it('issues a token only after a strong step-up and stores only its hash', function (): void {
    $service = app(McpTokenService::class);

    expect(fn () => $service->issue($this->admin, 'Bez apstiprinājuma', ['read'], 30))->toThrow(AuthorizationException::class);

    app(StepUpService::class)->markFresh($this->admin, 'trusted');
    expect(fn () => $service->issue($this->admin, 'Uzticama ierīce', ['read'], 30))->toThrow(AuthorizationException::class);

    app(StepUpService::class)->markFresh($this->admin, 'passkey');
    $issued = $service->issue($this->admin, 'Mans dators', ['read'], 30);

    [, $id, $secret] = explode('_', $issued['plaintext'], 3);
    $row = DB::table('mcp_tokens')->where('id', $id)->first();

    expect($issued['plaintext'])->toMatch('/^kmcp_[0-9a-z]{26}_[A-Za-z0-9_-]{43}$/')
        ->and(Bytea::decode($row->token_hash))->toBe(hash('sha256', $secret, true))
        ->and(str_contains((string) json_encode($row), $secret))->toBeFalse()
        ->and($row->token_hint)->toBe(substr($secret, -4))
        ->and($service->resolve($issued['plaintext'])?->id)->toBe($id)
        ->and(AuditEntry::query()->where('action', 'mcp.token.create')->first()->data)->toMatchArray(['token_id' => $id, 'scopes' => ['read'], 'valid_days' => 30])
        ->and(str_contains(AuditEntry::query()->get()->map(fn ($e) => json_encode($e->data))->join(''), $secret))->toBeFalse();
});

it('caps expiry: write tokens at most 30 days (default 30), read tokens at most 365 or none', function (): void {
    app(StepUpService::class)->markFresh($this->admin, 'totp');
    $service = app(McpTokenService::class);

    $write = $service->issue($this->admin, 'Rakstīt', ['write'], null)['token'];
    $read = $service->issue($this->admin, 'Lasīt', ['read'], null)['token'];

    expect($write->scopes)->toBe(['read', 'write'])
        ->and((int) round(now()->diffInDays($write->expires_at)))->toBe(30)
        ->and($read->expires_at)->toBeNull()
        ->and(fn () => $service->issue($this->admin, 'Par ilgu', ['write'], 31))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->issue($this->admin, 'Par ilgu', ['read'], 366))->toThrow(InvalidArgumentException::class)
        // The database refuses a write token without expiry too.
        ->and(fn () => McpToken::query()->create(['user_id' => $this->admin->id, 'label' => 'x', 'scopes' => ['read', 'write'], 'token_hash' => random_bytes(32), 'token_hint' => 'abcd']))
        ->toThrow(QueryException::class);
});

it('refuses tokens for anyone but a platform admin', function (): void {
    $resident = makeUser('mcp-resident@example.test');
    $this->actingAs($resident);
    app(StepUpService::class)->markFresh($resident, 'totp');

    expect(fn () => app(McpTokenService::class)->issue($resident, 'x', ['read'], 1))->toThrow(AuthorizationException::class);
    $this->get('/admin/platform/mcp')->assertForbidden();
});

it('shows the token once on the MCP piekļuve page with the Claude Code command, then forgets it', function (): void {
    app(StepUpService::class)->markFresh($this->admin, 'totp');
    $this->get('/admin/platform/mcp')->assertOk()->assertSee(__('admin.mcp.title'));

    $page = Livewire::test(McpAccessPage::class)
        ->set('label', 'Mans klēpjdators')
        ->set('write', true)
        ->set('validDays', 7)
        ->call('create')
        ->assertHasNoErrors();

    $token = McpToken::query()->firstOrFail();
    preg_match('/kmcp_[0-9a-z]{26}_[A-Za-z0-9_-]{43}/', $page->html(), $m);
    $plaintext = $m[0] ?? '';

    expect($plaintext)->toStartWith('kmcp_'.$token->id.'_')
        ->and(app(McpTokenService::class)->resolve($plaintext)?->id)->toBe($token->id)
        ->and($token->scopes)->toBe(['read', 'write'])
        ->and((int) round(now()->diffInDays($token->expires_at)))->toBe(7)
        // Rendered once, but never part of the component snapshot the browser posts back.
        ->and(str_contains((string) json_encode((fn () => $this->lastState->getSnapshot())->call($page)), $plaintext))->toBeFalse();

    $page->assertSee('claude mcp add --transport http kaimini '.url('/mcp').' --header "Authorization: Bearer '.$plaintext.'"', false)
        ->call('forgetSecret')
        ->assertDontSee($plaintext);

    // A fresh page never shows a secret again: only the label and the last four characters.
    Livewire::test(McpAccessPage::class)->assertSee('Mans klēpjdators')->assertSee('…'.$token->token_hint)->assertDontSee($plaintext);
});

it('moves the default validity to 30 days when the write scope is ticked', function (): void {
    app(StepUpService::class)->markFresh($this->admin, 'totp');

    Livewire::test(McpAccessPage::class)
        ->assertSet('validDays', 90)
        ->set('write', true)->assertSet('validDays', 30)
        ->set('write', false)->assertSet('validDays', 90);
});

it('sends the admin to the step-up before creating a token', function (): void {
    app(StepUpService::class)->markFresh($this->admin, 'trusted');

    Livewire::test(McpAccessPage::class)->set('label', 'X')->call('create')->assertRedirect();

    expect(McpToken::query()->count())->toBe(0);
});

it('revokes a token from the page; the token stops working at once', function (): void {
    $plaintext = mcpToken($this->admin);
    app(StepUpService::class)->markFresh($this->admin, 'totp');

    Livewire::test(McpAccessPage::class)->call('revoke', mcpTokenId($plaintext))->assertNotified(__('admin.mcp.revoked'));

    expect(McpToken::query()->find(mcpTokenId($plaintext))->revoked_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('action', 'mcp.token.revoke')->exists())->toBeTrue();

    mcpRpc($plaintext, 'tools/list')->assertStatus(401);
});

it('never revokes another admin\'s token', function (): void {
    $other = mcpAdmin('mcp-other@example.test');
    $plaintext = mcpToken($other);
    app(StepUpService::class)->markFresh($this->admin, 'totp');

    Livewire::test(McpAccessPage::class)->call('revoke', mcpTokenId($plaintext));

    expect(McpToken::query()->find(mcpTokenId($plaintext))->revoked_at)->toBeNull();
});

it('limits active tokens per admin', function (): void {
    for ($i = 0; $i < McpTokenService::MAX_ACTIVE_PER_USER; $i++) {
        mcpToken($this->admin);
    }
    app(StepUpService::class)->markFresh($this->admin, 'totp');

    expect(fn () => app(McpTokenService::class)->issue($this->admin, 'Vēl viens', ['read'], 1))->toThrow(InvalidArgumentException::class);
});

it('lists token states without secrets', function (): void {
    mcpToken($this->admin, attributes: ['label' => 'Aktīvs']);
    mcpToken($this->admin, attributes: ['label' => 'Atsaukts', 'revoked_at' => now()]);
    mcpToken($this->admin, attributes: ['label' => 'Beidzies', 'expires_at' => now()->subDay()]);
    mcpToken($this->admin, attributes: ['label' => 'Vecs', 'auth_epoch' => 99]);

    $states = collect(app(McpTokenService::class)->listFor($this->admin))->pluck('state', 'label')->all();

    expect($states)->toEqualCanonicalizing(['Aktīvs' => 'active', 'Atsaukts' => 'revoked', 'Beidzies' => 'expired', 'Vecs' => 'stale']);
});
