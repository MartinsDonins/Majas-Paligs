<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\NumberingDirection;
use App\Mcp\McpRefusal;
use App\Models\Building;
use App\Models\BuildingSeries;
use App\Models\McpToken;
use App\Models\User;
use App\Platform\PlatformPurpose;
use App\Services\Buildings\BuildingGenerator;
use App\Services\Buildings\GeneratorInput;
use App\Services\Buildings\SeriesTemplateData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('generate_building_from_series')]
#[Description('WRITE (dry run by default). Generate a building\'s stairwells, apartments, rooms and risers from a series template — the same generator as /admin/platform → Ģenerators. Step 1: call with "dry_run": true (default) → plan summary, conflicts with the stored structure and a "plan" value. Step 2 (after the owner approved): the same arguments + "plan" + "dry_run": false + "confirm": true. Idempotent for the same input; an input that conflicts with existing stairwells is refused and nothing is written.')]
#[IsIdempotent]
final class GenerateBuildingFromSeriesTool extends McpTool
{
    protected string $scope = McpToken::SCOPE_WRITE;

    protected bool $supportsDryRun = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug (create the building in /admin/platform first).')->required(),
            'series' => $schema->string()->description('Series code, e.g. "602" (default: the building\'s series).'),
            'sections' => $schema->integer()->min(1)->max(20)->description('Number of stairwells.')->required(),
            'floors' => $schema->integer()->min(1)->max(30)->description('Floors per stairwell.')->required(),
            'first_apartment_no' => $schema->integer()->min(1)->description('First apartment number (default 1).'),
            'direction' => $schema->string()->enum(['ltr', 'rtl'])->description('Numbering direction on a floor (default ltr).'),
            'layout' => $schema->string()->description('Layout preset for every stairwell (default: the series default).'),
            'layouts' => $schema->object()->description('Per-stairwell presets: {"1": "2-2-3-3", "5": "1-2-3-4"}; overrides "layout".'),
            'dry_run' => $schema->boolean()->description('true (default): plan only, nothing is written.'),
            'plan' => $schema->string()->description('The "plan" value of the dry run (required when dry_run is false).'),
            'confirm' => $schema->boolean()->description('Must be true with dry_run false: the owner approved this plan.'),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $data = $request->validate([
            'sections' => ['required', 'integer', 'min:1', 'max:20'],
            'floors' => ['required', 'integer', 'min:1', 'max:30'],
            'first_apartment_no' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'direction' => ['nullable', 'in:ltr,rtl'],
            'layout' => ['nullable', 'string', 'max:20'],
            'layouts' => ['nullable', 'array', 'max:20'],
            'layouts.*' => ['string', 'max:20'],
            'series' => ['nullable', 'string', 'max:20'],
            'plan' => ['nullable', 'string', 'size:64'],
        ]);

        $building = $this->building($request);
        $series = isset($data['series'])
            ? BuildingSeries::query()->where('code', (string) $data['series'])->first()
            : ($building->series_id !== null ? BuildingSeries::query()->find($building->series_id) : null);
        if ($series === null) {
            throw new McpRefusal('series', 'Unknown series: pass "series" (e.g. "602").');
        }
        if ($building->series_id !== null && (int) $building->series_id !== (int) $series->id) {
            throw new McpRefusal('series', 'This building belongs to another series; the generator never mixes series.');
        }

        $template = SeriesTemplateData::fromSeries($series);
        $default = (string) ($data['layout'] ?? $template->defaultLayoutCode ?? array_key_first($template->layouts));
        $layouts = [];
        for ($i = 1; $i <= (int) $data['sections']; $i++) {
            $layouts[$i] = (string) ($data['layouts'][(string) $i] ?? $data['layouts'][$i] ?? $default);
        }
        $direction = NumberingDirection::from((string) ($data['direction'] ?? 'ltr'));
        $first = (int) ($data['first_apartment_no'] ?? 1);

        try {
            $input = GeneratorInput::perSection((int) $data['floors'], $layouts, $first, $direction);
            $generator = app(BuildingGenerator::class);
            $preview = $generator->preview($template, $input);
        } catch (InvalidArgumentException $e) {
            throw new McpRefusal('input', $e->getMessage());
        }

        $plan = hash_hmac('sha256', json_encode([
            'building' => (int) $building->id,
            'series' => (int) $series->id,
            'floors' => (int) $data['floors'],
            'layouts' => $layouts,
            'first' => $first,
            'direction' => $direction->value,
        ], JSON_THROW_ON_ERROR), 'mcp-generator-plan:'.config('app.key'));

        $summary = [
            'building' => $building->slug,
            'series' => $series->code,
            'apartments' => (int) $preview['apartments'],
            'risers' => (int) $preview['risers'],
            'sections' => array_map(static fn (array $s): array => [
                'number' => $s['number'],
                'floors' => $s['floors'],
                'apartments_per_floor' => $s['apartments_per_floor'],
                'first_apartment' => $s['first'],
                'last_apartment' => $s['last'],
                'layout' => $s['layout_code'],
            ], $preview['sections']),
        ];

        $dryRun = $request->get('dry_run', true) !== false;

        if ($dryRun) {
            $conflicts = $this->inBuilding($building, static fn () => $generator->conflicts($template, $preview), PlatformPurpose::StructureView);

            return $summary + [
                'dry_run' => true,
                'conflicts' => $conflicts,
                'plan' => $conflicts === [] ? $plan : null,
                'next' => $conflicts === []
                    ? 'Show this plan to the owner. After approval call again with the same arguments, "plan", "dry_run": false and "confirm": true.'
                    : 'The input conflicts with the stored stairwells: nothing can be generated with it.',
            ];
        }

        if (! isset($data['plan']) || ! hash_equals($plan, (string) $data['plan'])) {
            throw new McpRefusal('plan', 'Run the dry run first and pass its "plan" value with exactly the same arguments.');
        }

        try {
            $created = $this->inBuilding($building, static fn (Building $b) => $generator->generate($b, $template, $input), PlatformPurpose::Generate);
        } catch (InvalidArgumentException $e) {
            throw new McpRefusal('conflict', $e->getMessage());
        }

        if ($building->series_id === null) {
            $building->forceFill(['series_id' => $series->id])->save();
        }

        return $summary + ['dry_run' => false, 'created' => $created];
    }
}
