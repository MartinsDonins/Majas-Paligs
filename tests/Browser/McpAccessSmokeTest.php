<?php

declare(strict_types=1);

use App\Models\McpToken;

/*
 * Round 3 "MCP piekļuve" in real Chromium (Filament panel, Livewire, Alpine): the platform admin creates a write token,
 * sees it ONCE together with the Claude Code command, the token works against POST /mcp, it is never part of a
 * Livewire snapshot, and "Esmu nokopējis — paslēpt" removes it from the page. Synthetic account only.
 */
it('creates an MCP token, shows it once with the Claude Code command and hides it again', function (): void {
    $admin = mcpAdmin(browserEmail('mcp-admin'));
    $this->actingAs($admin);
    steppedUp($admin, 'totp');

    $page = phone('/admin/platform/mcp');
    $page->waitForText(__('admin.mcp.new'));
    $page->assertSee(__('admin.mcp.empty'))->assertNoJavaScriptErrors();

    $page->type('[data-testid="mcp-create"] input[type="text"]', 'Pārlūka tests')
        ->check('[data-testid="mcp-create"] input[type="checkbox"]');
    // A write token lives at most 30 days: ticking the box moves the default 90 to 30 (Livewire round trip).
    waitUntil($page, 'document.querySelector(\'[data-testid="mcp-create"] input[type="number"]\').value === "30"', 10);
    $page->click(__('admin.mcp.create'))
        ->waitForText(__('admin.mcp.command_title'));

    $plaintext = trim((string) $page->script('document.querySelector("[data-testid=mcp-token]").innerText'));
    $token = McpToken::query()->sole();

    expect($plaintext)->toStartWith('kmcp_'.$token->id.'_')
        ->and($token->scopes)->toBe(['read', 'write'])
        ->and($token->expires_at)->not->toBeNull()
        // Shown in the page, never inside a component snapshot that travels back with every request.
        ->and((bool) $page->script('[...document.querySelectorAll("[wire\\\\:snapshot]")].some(e => e.getAttribute("wire:snapshot").includes('.json_encode(substr($plaintext, -12)).'))'))->toBeFalse();

    $page->assertSee('claude mcp add --transport http kaimini')->assertSee('…'.$token->token_hint)->assertNoJavaScriptErrors();

    $page->click(__('admin.mcp.copied'))->waitForText(__('admin.mcp.list'));
    waitUntil($page, '!document.querySelector("[data-testid=mcp-token]")', 10);
    expect((string) $page->script('document.body.innerText'))->not->toContain($plaintext);
    $page->assertNoJavaScriptErrors();

    // The token really opens the MCP server (last: an MCP call carries no session step-up).
    mcpRpc($plaintext, 'tools/list')->assertOk()->assertJsonPath('result.tools.0.name', 'app_status');
});
