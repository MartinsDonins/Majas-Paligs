<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
 * ---- Round 3 helpers: the in-app MCP server (docs/MCP.md). Synthetic @example.test accounts only. ----
 */

function mcpAdmin(string $email = 'mcp-admin@example.test'): User
{
    return makeUser($email, ['platform_role' => 'platform_admin']);
}

/**
 * A token row + its plaintext, the same format McpTokenService::issue() mints (fixtures skip the step-up ceremony).
 *
 * @param  list<string>  $scopes
 * @param  array<string, mixed>  $attributes
 */
function mcpToken(User $user, array $scopes = ['read'], ?int $days = 30, array $attributes = []): string
{
    $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    $token = McpToken::query()->create($attributes + [
        'user_id' => $user->id,
        'label' => 'Test',
        'scopes' => $scopes,
        'token_hash' => hash('sha256', $secret, true),
        'token_hint' => substr($secret, -4),
        'auth_epoch' => (int) $user->auth_epoch,
        'expires_at' => $days !== null ? now()->addDays($days) : null,
    ]);

    return 'kmcp_'.$token->id.'_'.$secret;
}

/**
 * The token id inside a plaintext token.
 */
function mcpTokenId(string $plaintext): string
{
    return explode('_', $plaintext, 3)[1];
}

/**
 * POST one JSON-RPC message to /mcp (legacy framing, like Claude Code's streamable HTTP client after initialize).
 *
 * @param  array<string, mixed>  $params
 */
function mcpRpc(?string $token, string $method, array $params = [], int $id = 1): TestResponse
{
    // The MCP route has no session: never let a test's session step-up leak into a bearer call.
    app(StepUpService::class)->forget();

    $headers = ['Accept' => 'application/json, text/event-stream'];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], $headers);
}

/**
 * tools/call → the decoded result: ['isError' => bool, 'text' => string, 'data' => array|null].
 *
 * @param  array<string, mixed>  $arguments
 * @return array{isError: bool, text: string, data: array<string, mixed>|null, raw: string}
 */
function mcpTool(string $token, string $tool, array $arguments = []): array
{
    $response = mcpRpc($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    $response->assertOk();

    $result = (array) $response->json('result');
    $text = (string) ($result['content'][0]['text'] ?? '');

    return [
        'isError' => (bool) ($result['isError'] ?? false),
        'text' => $text,
        'data' => isset($result['structuredContent']) && is_array($result['structuredContent']) ? $result['structuredContent'] : null,
        'raw' => (string) $response->getContent(),
    ];
}
