# MCP server — managing Kaimiņos from Claude Code

Round 3 (ADR `docs/ADR/0008-mcp-server.md`). The app has its own MCP server at **`POST /mcp`**
(`https://kaimini.coredigify.com/mcp`). It is built on the official `laravel/mcp` package (v1.1, HTTP transport). It is meant for
the **platform admin** (CoreDigify), and every call is audited. Tool results never contain personal data.

## 1. Set it up (once per computer)

1. Sign in at `/admin/platform` with your passkey or authenticator, then open **Platforma → MCP piekļuve**.
2. Under **Jauns tokens**, enter a name (e.g. "Mans dators") and choose:
   - **read only** (default): an optional expiry of 1–365 days. Leave the field empty for a token that does not expire.
   - **Atļaut izmaiņas** (`write` scope): the token **must** expire within 30 days. The default is 30.
3. **Izveidot tokenu** asks for one passkey tap or TOTP code, because a token is a new credential. The token
   (`kmcp_…`) and the ready-made command are shown **once**. Copy them into your password manager, then press **Esmu
   nokopējis — paslēpt**. The server keeps only a SHA-256 hash and the last four characters.
4. In a terminal:

```sh
claude mcp add --transport http kaimini https://kaimini.coredigify.com/mcp --header "Authorization: Bearer <token>"
```

To test it, start `claude` and ask "Kāds ir Kaimiņos app_status?". To remove it, run `claude mcp remove kaimini`, and
revoke the token under **MCP piekļuve → Atsaukt**.

**The token stops working** when it is:

- revoked;
- expired;
- owned by an account that is no longer `platform_admin`;
- owned by an account whose sign-in credentials changed. The `auth_epoch` changes on TOTP or passkey removal, "Aizmirst
  ierīces", `majas:reset-mfa`, account erasure and a role revoke.

Every failure gets the same `401`. When that happens, create a new token.

Limits:

- At most 5 active tokens per admin.
- 60 calls per minute per token, and 120 per minute per client network (/24 or /48).
- 10 failed authentications per 15 minutes per client network. After that the client gets `429`, even with a good token.
- 30 writes per hour per token.

## 2. Tools

Every tool takes `building` (the slug) where it applies. Call `buildings_list` to see the slugs.

| Tool | Scope | What it returns / does |
|---|---|---|
| `app_status` | read | version, environment, the caller (user id, token id, scopes), DB ok, migrations ran / **pending**, queue connection + pending jobs + Horizon state, **scheduler heartbeat** (healthy ≤ 180 s), failed jobs (count, job class, exception class), the most recent **error summaries** |
| `buildings_list` | read | every building: slug, address, series, status, gates (L-core, roster, parking level), counts (stairwells, apartments, members approved / pending, roles) |
| `building_detail` | read | stairwells (number, floors, apartment range, layout), apartments / rooms / risers by kind and confidence, settings (modules, windows, sharing flags, gate dates), gate inputs as **set / not set**, missing gate conditions, the **opening checklist** (14 steps: status + who), team counts, invite-letter counts |
| `users_and_roles` | read | current roles (role id, user id, **masked e-mail**, stairwell, granted / expires, has a second factor), membership counts by status / role / stairwell |
| `invitations_list` | read | team invitations: id, role, stairwells, masked e-mail, state, dates, resend count |
| `imports_list` | read | CSV import runs: kind, status, row count, dry-run counts and validation codes, committed counts, error code |
| `heating_summary` | read | riser statuses per stairwell (counts only), confirmed / disputed / conflict, valid reports, reporting apartments (a number), open incidents by kind, open survey rounds and disputes |
| `parking_summary` | read | gate level + missing conditions, lots, permits by type and status, vehicles by status, conflicts, declarations, completeness per stairwell, sightings, lookups, open officer tasks |
| `audit_search` | read | the platform chain (default) or one building's chain. Filters: action prefix, actor id, since / until. Data fields only when they are codes or numbers; never free text |
| `templates_status` | read | document masters per kind and language (published version, drafts, LV/RU parity). With `building`, that house's documents (status, versions, newer master, linter codes) |
| `grant_role` | write | grant `building_admin` / `section_elder` (+ `section` number) / `parking_officer` / `volunteer` to a user id, **including yourself**. Re-granting extends the role by 12 months |
| `revoke_role` | write | revoke a role by its id. The last `building_admin` is kept unless you pass `allow_last_admin: true` |
| `invite_team_member` | write | e-mail a team invitation. `building_admin` = the platform bootstrap. For other roles you must hold `building_admin` in that house yourself. The address is never echoed or logged |
| `resend_invitation` | write | a new link and 7 more days, at most 3 times |
| `set_building_setting` | write | ONE of `modules.heating`, `modules.outages`, `adhoc_report_valid_days` (1–30), `confirm_min_apartments` (2–5), `default_locale` (lv / ru) |
| `generate_building_from_series` | write | the generator. **Dry run by default**: it returns the plan, any conflicts, and a `plan` value. A second call with the same arguments + `plan` + `dry_run: false` + `confirm: true` writes |
| `run_recompute` | write | recompute the heating projections of one building now (the module must be on) |

**Writes:** a `write` token **and** `"confirm": true` in the call. Without either, the server refuses and changes nothing.
Claude asks you before it calls a write tool. Approve only the exact change it shows.

Not over MCP, by design:

- Gate inputs: the controller, DPA, DPIA, mandate and parking switch are written by the opening wizard, and the DPA
  record by the platform panel.
- Manager-sharing flags and PIN limits.
- Break-glass reads.
- The resident roster, plates and contacts.
- Deleting anything.

## 3. Privacy

Tools return counts, statuses, ids, apartment and stairwell numbers, and **masked e-mail hints** (`j•••s@e•••.test`).
They never return:

- names;
- e-mail addresses;
- phone numbers;
- plates;
- PIN handles, codes or tokens;
- private notes, reasons or other free text a person typed;
- the controller or manager details. A tool says only whether they are *set*.

`App\Mcp\McpPrivacy::assertClean()` checks every finished result. If it finds a forbidden key, or a value that looks
like an e-mail address or a phone number, the whole answer is withheld: the caller sees "withheld" and the audit row says
`withheld`. The arguments you send (for example an invitee's e-mail) are never written to the audit log.

## 4. Audit

- Every call writes `mcp.call` on the **platform chain**: tool, `token_id`, `building_id`, scope and outcome (`ok`,
  `refused:<reason>`, `invalid`, `denied`, `withheld` or `error`), plus `dry_run` for writes. It also records the actor
  (the token's user, `actor_role = platform_admin_mcp`), the IP HMAC and the user-agent hash.
- Entering a building writes `platform.building_context` with purpose `mcp_read`, `mcp_write`, `structure_view`, or
  `generate` for the generator, plus the `mcp_token_id`.
- What changed is recorded on the **building chain** by the existing services: `role.grant`, `role.revoke`,
  `team.invite.*`, `settings.update` (`via: mcp`) and `heating.recompute`.
- Tokens: `mcp.token.create` (scopes, validity) and `mcp.token.revoke`. Search them with
  `audit_search {"action": "mcp."}`.

## 5. Error summaries and the scheduler heartbeat

- `error_events` holds one row per reported exception fingerprint (`sha256(class | file | line)`), with a count, first
  and last seen, origin (`http`, `console`, `queue` or `mcp`), and a **scrubbed** one-line message. E-mails, quoted
  values, digit runs, tokens and query strings are replaced. There is no request data, user or IP. Not-found pages and
  validation errors are never reported, so they never appear here. GlitchTip still gets the full event.
- `ops:heartbeat` runs every minute in the `scheduler` service. It writes the cache key `ops:scheduler:heartbeat` and
  deletes `error_events` rows older than 30 days.

## 6. Security model (short)

See ADR 0008. The token stands in for the session step-up **only inside a confirmed write tool** of a `write` token,
and only for its own user (`App\Mcp\McpAccess::elevated`, checked by `StepUpService` and `PlatformBuildingContext`). A
read token never satisfies a step-up. The MCP purposes `mcp_read` and `mcp_write` are unreachable from a browser
session. Tenancy stays fail-closed: every building read or write runs inside `PlatformBuildingContext::run()`, and no
new class sets the context or lifts the scope.

## 7. Local check

```sh
curl -s https://kaimini.coredigify.com/mcp -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"app_status","arguments":{}}}'
```

Tests: `tests/Feature/Mcp/*` cover auth, scopes, privacy, audit, rate limits, tools and the token page.
`tests/Browser/McpAccessSmokeTest.php` covers the page in Chromium.
