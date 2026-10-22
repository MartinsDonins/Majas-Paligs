<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Audit\AuditLogger;
use App\Mcp\McpRefusal;
use App\Models\Building;
use App\Models\McpToken;
use App\Models\RiserStatus;
use App\Models\User;
use App\Services\Heating\RiserStatusProjector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('run_recompute')]
#[Description('WRITE. Recompute the heating projections (riser / column statuses of the rolling window and the open survey rounds) of one building now — what heating:recompute does hourly. Needs the heating module on, a write token and "confirm": true.')]
#[IsIdempotent]
final class RunRecomputeTool extends McpTool
{
    protected string $scope = McpToken::SCOPE_WRITE;

    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug.')->required(),
            'confirm' => $schema->boolean()->description('Must be true.')->required(),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $building = $this->building($request);

        if (! $building->settings->moduleEnabled('heating')) {
            throw new McpRefusal('module', 'The heating module is off for this building (set_building_setting modules.heating true).');
        }

        return $this->inBuilding($building, static function (Building $b): array {
            $started = microtime(true);
            app(RiserStatusProjector::class)->recompute();
            AuditLogger::record('heating.recompute', $b, data: ['via' => 'mcp']);

            return [
                'building' => $b->slug,
                'riser_rows' => RiserStatus::query()->count(),
                'rolling_rows' => RiserStatus::query()->where('round_key', 'rolling')->count(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        });
    }
}
