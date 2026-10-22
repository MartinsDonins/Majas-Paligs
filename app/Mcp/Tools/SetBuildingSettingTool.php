<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Audit\AuditLogger;
use App\Mcp\McpRefusal;
use App\Models\Building;
use App\Models\McpToken;
use App\Models\User;
use App\Services\Heating\RiserStatusProjector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('set_building_setting')]
#[Description('WRITE. Change ONE whitelisted building setting: modules.heating (bool), modules.outages (bool), adhoc_report_valid_days (1–30), confirm_min_apartments (2–5), default_locale (lv / ru). Gate inputs (controller, DPA, DPIA, mandate, parking), manager-sharing flags and PIN limits are NOT settable here — they have one writer each (opening wizard / platform DPA record). Needs a write token and "confirm": true.')]
#[IsIdempotent]
final class SetBuildingSettingTool extends McpTool
{
    protected string $scope = McpToken::SCOPE_WRITE;

    /** key => validation rules for the value. */
    public const KEYS = [
        'modules.heating' => ['required', 'boolean'],
        'modules.outages' => ['required', 'boolean'],
        'adhoc_report_valid_days' => ['required', 'integer', 'min:1', 'max:30'],
        'confirm_min_apartments' => ['required', 'integer', 'min:2', 'max:5'],
        'default_locale' => ['required', 'string', 'in:lv,ru'],
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'building' => $schema->string()->description('Building slug.')->required(),
            'key' => $schema->string()->enum(array_keys(self::KEYS))->required(),
            'value' => $schema->string()->description('New value: true / false, an integer, or lv / ru (JSON booleans and numbers are accepted too).')->required(),
            'confirm' => $schema->boolean()->description('Must be true: the owner approved this exact change.')->required(),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $key = $request->get('key');
        if (! is_string($key) || ! array_key_exists($key, self::KEYS)) {
            throw new McpRefusal('key', 'Unknown or not allowed key. Allowed: '.implode(', ', array_keys(self::KEYS)).'.');
        }

        $value = self::normalise($key, $request->get('value'));
        validator(['value' => $value], ['value' => self::KEYS[$key]])->validate();

        $building = $this->building($request);

        return $this->inBuilding($building, static function (Building $b) use ($key, $value): array {
            $before = self::current($b, $key);

            if ($key === 'default_locale') {
                $b->forceFill(['default_locale' => (string) $value])->save();
            } elseif (str_starts_with($key, 'modules.')) {
                $b->forceFill(['settings' => $b->settings->with(['modules' => [substr($key, 8) => (bool) $value]])])->save();
            } else {
                $b->forceFill(['settings' => $b->settings->with([$key => (int) $value])])->save();
            }

            $b->refresh();
            $after = self::current($b, $key);

            if ($before !== $after) {
                AuditLogger::record('settings.update', $b, data: ['changed' => [$key], 'via' => 'mcp']);

                // The heating projection depends on the validity window / confirmation threshold.
                if (in_array($key, ['adhoc_report_valid_days', 'confirm_min_apartments', 'modules.heating'], true) && $b->settings->moduleEnabled('heating')) {
                    app(RiserStatusProjector::class)->recompute();
                }
            }

            return ['building' => $b->slug, 'key' => $key, 'before' => $before, 'after' => $after, 'changed' => $before !== $after];
        });
    }

    private static function normalise(string $key, mixed $value): mixed
    {
        if (in_array('boolean', self::KEYS[$key], true) && is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off' => false,
                default => $value,
            };
        }

        if (in_array('integer', self::KEYS[$key], true) && is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return $value;
    }

    private static function current(Building $b, string $key): bool|int|string
    {
        return match ($key) {
            'modules.heating' => $b->settings->moduleEnabled('heating'),
            'modules.outages' => $b->settings->moduleEnabled('outages'),
            'adhoc_report_valid_days' => $b->settings->adhocReportValidDays,
            'confirm_min_apartments' => $b->settings->confirmMinApartments,
            default => (string) $b->default_locale,
        };
    }
}
