<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AppStatusTool;
use App\Mcp\Tools\AuditSearchTool;
use App\Mcp\Tools\BuildingDetailTool;
use App\Mcp\Tools\BuildingsListTool;
use App\Mcp\Tools\GenerateBuildingFromSeriesTool;
use App\Mcp\Tools\GrantRoleTool;
use App\Mcp\Tools\HeatingSummaryTool;
use App\Mcp\Tools\ImportsListTool;
use App\Mcp\Tools\InvitationsListTool;
use App\Mcp\Tools\InviteTeamMemberTool;
use App\Mcp\Tools\ParkingSummaryTool;
use App\Mcp\Tools\ResendInvitationTool;
use App\Mcp\Tools\RevokeRoleTool;
use App\Mcp\Tools\RunRecomputeTool;
use App\Mcp\Tools\SetBuildingSettingTool;
use App\Mcp\Tools\TemplatesStatusTool;
use App\Mcp\Tools\UsersAndRolesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * The Kaimiņos (Mājas Palīgs) MCP server at POST /mcp (docs/MCP.md, ADR 0008): platform administration from Claude Code.
 * Bearer token of a platform_admin only; read tools need the `read` scope, write tools `write` + `confirm: true`.
 */
#[Name('Kaimiņos')]
#[Instructions(<<<'MD'
    Kaimiņos (Mājas Palīgs) platform administration for the platform admin. Speak Latvian to the owner.

    - Start with app_status, then buildings_list. Buildings are addressed by their slug.
    - Results never contain personal data: counts, statuses, ids, apartment / stairwell numbers and masked e-mail hints
      only. Do not ask for names, phones, plates or e-mails and never guess them.
    - Write tools (grant_role, revoke_role, invite_team_member, resend_invitation, set_building_setting,
      generate_building_from_series, run_recompute) change production data. Call them ONLY after the owner approved
      that exact change in this conversation, then with "confirm": true. A question is not approval.
    - generate_building_from_series: always run it with "dry_run": true first, show the plan, and pass the returned
      "plan" value with "dry_run": false and "confirm": true.
    - Every call is audited (tool, token, building).
    MD)]
final class KaiminiServer extends Server
{
    /**
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = [
        AppStatusTool::class,
        BuildingsListTool::class,
        BuildingDetailTool::class,
        UsersAndRolesTool::class,
        InvitationsListTool::class,
        ImportsListTool::class,
        HeatingSummaryTool::class,
        ParkingSummaryTool::class,
        AuditSearchTool::class,
        TemplatesStatusTool::class,
        GrantRoleTool::class,
        RevokeRoleTool::class,
        InviteTeamMemberTool::class,
        ResendInvitationTool::class,
        SetBuildingSettingTool::class,
        GenerateBuildingFromSeriesTool::class,
        RunRecomputeTool::class,
    ];

    public int $defaultPaginationLength = 50;

    protected function boot(): void
    {
        $this->version = (string) config('app.version');
    }
}
