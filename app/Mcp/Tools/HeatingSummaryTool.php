<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Building;
use App\Models\HeatingReport;
use App\Models\Incident;
use App\Models\RiserDispute;
use App\Models\RiserStatus;
use App\Models\Section;
use App\Models\SurveyRound;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('heating_summary')]
#[Description('Heating and outages of one building at riser / stairwell level (the resident aggregation rule: counts only, never per floor or per apartment): riser statuses per stairwell (rolling window), confirmed / disputed / conflict counts, valid reports and reporting apartments (numbers), open incidents by kind, open survey rounds and open riser disputes.')]
#[IsReadOnly]
final class HeatingSummaryTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug (see buildings_list).')->required(),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $building = $this->building($request);

        return $this->inBuilding($building, static function (Building $b): array {
            $sections = Section::query()->orderBy('number')->pluck('number', 'id');

            // Resident-safe columns only (no floors_reported / top_floor_only).
            $statuses = RiserStatus::query()->where('round_key', 'rolling')
                ->get(['section_id', 'status', 'confirmed', 'disputed', 'conflict', 'distinct_apartments', 'updated_at']);

            $perSection = [];
            foreach ($sections as $id => $number) {
                $rows = $statuses->where('section_id', $id);
                $perSection[] = [
                    'section' => (int) $number,
                    'risers' => $rows->count(),
                    'by_status' => $rows->countBy(static fn (RiserStatus $r): string => (string) $r->getAttribute('status'))->all(),
                    'confirmed' => $rows->filter(static fn (RiserStatus $r): bool => (bool) $r->getAttribute('confirmed'))->count(),
                    'disputed' => $rows->filter(static fn (RiserStatus $r): bool => (bool) $r->getAttribute('disputed'))->count(),
                    'conflict' => $rows->filter(static fn (RiserStatus $r): bool => (bool) $r->getAttribute('conflict'))->count(),
                ];
            }

            $valid = HeatingReport::query()->valid();

            return [
                'building' => $b->slug,
                'modules' => ['heating' => $b->settings->moduleEnabled('heating'), 'outages' => $b->settings->moduleEnabled('outages')],
                'projection_updated_at' => self::iso($statuses->max('updated_at')),
                'sections' => $perSection,
                'reports' => [
                    'valid' => (clone $valid)->count(),
                    'apartments_reporting' => (clone $valid)->distinct()->count('apartment_id'),
                    'last_7_days' => HeatingReport::query()->where('reported_at', '>=', now()->subDays(7))->count(),
                    'last_reported_at' => self::iso(HeatingReport::query()->max('reported_at')),
                ],
                'incidents_open' => Incident::query()->open()->select('kind', DB::raw('count(*) AS n'))->groupBy('kind')->pluck('n', 'kind')
                    ->map(static fn ($n): int => (int) $n)->all(),
                'survey_rounds_open' => SurveyRound::query()->open()->count(),
                'riser_disputes_open' => RiserDispute::query()->where('status', 'open')->count(),
            ];
        });
    }
}
