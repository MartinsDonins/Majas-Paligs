# ADR 0008 — In-app MCP server for platform administration

- Status: proposed (round 3, MCP-001). Owner request 2026-10-22: "izveidojam MCP savienojumu, lai var pārvaldīt projektu".
- Numbering: the round-3 brief said "ADR 0007". That number was already taken by `0007-admin-area.md`, so this ADR is
  0008.
- Setup and tool reference: `docs/MCP.md`.

## Context
The owner runs the project from Claude Code. After 0.0.30 he saw empty pages in production and could not tell what
existed or what was missing: whether Pūces 51 was generated, whether its gates were open, and who held which role. The
only way to look was the Filament panels. The other CoreDigify projects already have MCP servers (in the same style:
platform-scoped tokens, PII-free tools, writes that need `confirm`).

Two constraints shape the design. The house data is personal data in the house's controllership (PLANNING §5). Every
building access is fail-closed tenancy with allow-listed context setters (§4.2).

## Decision
1. **Official `laravel/mcp` ^1.1, HTTP transport, one route.** `routes/ai.php`: `Mcp::web('/mcp', KaiminiServer)`. The
   route is outside the `web` group, so there is no session, cookie or CSRF. GET and DELETE return 405. The
   2025-11-25 `initialize` handshake works (Claude Code; covered by a test). The package also implements the
   2026-07-28 discovery handshake, which is not tested here.
2. **Bearer personal access tokens, platform_admin only** (`mcp_tokens`, `McpTokenService`).
   - Format `kmcp_<ulid>_<32 random bytes base64url>`. The database keeps only SHA-256(secret) and the last four
     characters.
   - Creating a token needs a **strong** step-up (passkey or TOTP of this session). A token is a new credential, like
     adding a sign-in factor.
   - Scopes are `read`, or `read` + `write`. A write token must expire within 30 days (DB CHECK + service). A read token
     may expire after up to 365 days, or not at all. At most 5 active tokens per admin.
   - A token is bound to the owner's `auth_epoch`, so every credential change ends it.
   - The secret is shown once on **MCP piekļuve** (`/admin/platform/mcp`), rendered from a *protected* Livewire
     property, so it never enters a component snapshot.
3. **One neutral 401** for a missing, malformed, unknown, wrong, revoked, expired or stale token, and for a token whose
   owner is no longer platform_admin. 10 failures per 15 minutes per client bucket → 429 before any lookup.
4. **Throttles:** 120 calls per minute per client bucket before auth, 60 per minute per token after it, and 30 writes
   per hour per token.
5. **Tenancy unchanged.** Tools enter a building only through `PlatformBuildingContext::run()`, with the new purposes
   `mcp_read` and `mcp_write`. The generator uses the existing `structure_view` and `generate` purposes. No new class
   sets the context or lifts the scope, so the Arch allow-lists are unchanged. Instead of the session step-up,
   `PlatformBuildingContext` accepts the request's `McpAccess`:
   - any token for the read purposes;
   - only the **write elevation** for the write purposes.

   The MCP purposes cannot be reached from a session.
6. **Write elevation = the only stand-in for a step-up.** `McpTool::handle()` checks, in order: the `write` scope,
   `confirm: true` (or a dry run), and the write rate limit. Only after that does it run the tool inside
   `McpAccess::elevated()`.

   While the elevation is active, `StepUpService::hasFreshMfa / hasFreshStrongMfa` return true **for the token's user
   only**. So the existing backstops apply unchanged:
   - `BuildingRoleService::grant` (strong, for someone else);
   - `TeamInvitationService::invite / resend` (strong);
   - `PlatformBuildingContext` (mfa).

   A read token, a session, or code outside a write tool never gets it.
7. **Privacy by construction plus a backstop.** Tools read whitelisted columns and return counts, statuses, ids,
   apartment and stairwell numbers and masked e-mail hints. Free-text gate inputs (controller, DPA ref, mandate,
   manager) are reported as *set / not set*. `McpPrivacy::assertClean()` withholds the whole result on a forbidden key
   or on an e-mail- or phone-shaped value. Audit `data` is shown only for code- or number-shaped values. `reason_text`
   is never shown.
8. **Every call is audited:**
   - `mcp.call` on the platform chain: tool, token, building, scope, outcome and dry run, but never the arguments;
   - `platform.building_context` (purpose + `mcp_token_id`) for every building entered;
   - the services' own building-chain rows (`role.*`, `team.invite.*`, `settings.update via mcp`, `heating.recompute`).
9. **Write tools are deliberately narrow.**
   - `grant_role` / `revoke_role` follow the platform bootstrap. The last admin is kept unless `allow_last_admin`.
   - `invite_team_member` / `resend_invitation`: `building_admin` via the platform bootstrap. Other roles only when the
     caller holds `building_admin` in that house; the RoleInvitationPolicy applies.
   - `set_building_setting` takes five non-gate, non-privacy keys only.
   - `generate_building_from_series` is a dry run by default. The write needs the dry run's HMAC `plan` for exactly the
     same input.
   - `run_recompute`.

   Gate inputs keep one writer each (opening wizard / platform DPA record). Break-glass, the roster, plates and
   deletions are not available.
10. **Operations data for `app_status`:**
    - `error_events`: PII-free error fingerprints from the exception handler's report callback. The message is
      scrubbed. The writer never throws and uses a savepoint inside open transactions. **Superseded in 0.0.34:** the
      table became `app_error_events` (OPS-001, `App\Support\ErrorLog`, stronger scrubber, throttling, no DELETE for
      `majas_app`); `app_status` reads it through `ErrorLogReader`; `prune:error-events` (majas_retention) prunes it.
    - `ops:heartbeat`: every minute. It writes the scheduler heartbeat.

## Consequences
- The owner can see from Claude Code what exists and what blocks a house. He can make himself `building_admin` of
  Pūces 51, invite the team and switch modules. Each of those writes needs his explicit "jā" in the conversation and
  `confirm: true`.
- A stolen **read** token reveals platform statistics, masked hints and ids, but no personal data. A stolen **write**
  token can change roles, invitations and five settings for at most 30 days. The mitigations:
  - every use is audited with an IP HMAC;
  - tokens are listed with last use;
  - revocation is instant;
  - any credential change kills all tokens.

  The token must therefore live in a password manager or in the Claude Code config, and never in a repo or a chat.
- The token replaces the step-up for confirmed writes. That is weaker than a passkey tap per action, and it is accepted
  for the platform admin only, as a trade-off of the narrow write set above.
- Tracked follow-ups:
  - OAuth 2.1 (Passport) instead of static tokens, once Claude Code's OAuth flow is used for other CoreDigify servers;
  - a per-token IP allow-list;
  - Telegram / e-mail alerts on write calls.
