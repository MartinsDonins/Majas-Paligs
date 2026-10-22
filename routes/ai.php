<?php

declare(strict_types=1);

use App\Mcp\Http\AuthenticateMcpToken;
use App\Mcp\Servers\KaiminiServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * The in-app MCP server (round 3, docs/MCP.md, ADR 0008). Loaded by laravel/mcp WITHOUT the `web` group: no session,
 * no cookies, no CSRF — only `Authorization: Bearer kmcp_…` of a platform_admin. Order: per-client throttle (also
 * counts bad tokens) → bearer auth (10 failures / 15 min per client bucket) → per-token throttle.
 */
Mcp::web('/mcp', KaiminiServer::class)
    ->middleware(['throttle:mcp-ip', AuthenticateMcpToken::class, 'throttle:mcp'])
    ->name('mcp');
