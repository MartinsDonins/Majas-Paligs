<?php

declare(strict_types=1);

namespace App\Services\Opening;

use App\Enums\BuildingRoleType;
use App\Enums\DocumentStatus;
use App\Gdpr\CoreGate;
use App\Gdpr\CoreGateStatus;
use App\Gdpr\ParkingGate;
use App\Gdpr\ParkingGateStatus;
use App\Gdpr\RosterGate;
use App\Gdpr\RosterGateStatus;
use App\Models\Apartment;
use App\Models\Building;
use App\Models\BuildingDocument;
use App\Models\BuildingPrivacyNotice;
use App\Models\BuildingRole;
use App\Models\Section;

/**
 * The house opening checklist (ROUND2-SPEC §3.2, §3.7, GOV-010 / UI-011): one status per wizard step, progress per
 * gate, and the dashboard gate cards with the first missing step ("Turpināt" → opening#step-<key>). A pure read of
 * the gates, settings, notices and documents of the CURRENT building — nothing here writes.
 */
final class OpeningChecklist
{
    public const DONE = 'done';

    public const TODO = 'todo';

    public const WAITING = 'waiting';

    public const OPTIONAL = 'optional';

    /** step => who does it (lang `opening.who.<who>`). */
    public const STEPS = [
        'structure' => 'platform',
        'team' => 'admin',
        'controller' => 'admin',
        'dpa' => 'admin_coredigify',
        'notice' => 'admin',
        'core_open' => 'result',
        'roster_scope' => 'admin_coredigify',
        'roster_notice' => 'admin',
        'roster_inform' => 'admin',
        'parking_scope' => 'admin_coredigify',
        'dpia' => 'admin',
        'mandate' => 'admin',
        'parking_notice' => 'admin',
        'parking_on' => 'admin',
    ];

    /** gate => the steps counted in its progress header ("Pieteikšanās atvēršana: 3 no 4"). */
    public const GROUPS = [
        'core' => ['structure', 'controller', 'dpa', 'notice'],
        'roster' => ['roster_scope', 'roster_notice', 'roster_inform'],
        'parking' => ['core_open', 'parking_scope', 'dpia', 'mandate', 'parking_notice', 'parking_on'],
    ];

    /** Gate condition key => the wizard step that satisfies it. */
    private const CONDITION_STEP = [
        'controller_name' => 'controller',
        'controller_contact' => 'controller',
        'dpa_signed_at' => 'dpa',
        'dpa_ref' => 'dpa',
        'dpa_scope' => 'dpa',
        'privacy_notice' => 'notice',
        'dpa_scope_roster' => 'roster_scope',
        'privacy_notice_roster' => 'roster_notice',
        'dpa_scope_parking' => 'parking_scope',
        'dpia_completed_at' => 'dpia',
        'privacy_notice_parking' => 'parking_notice',
        'mandate_ref' => 'mandate',
    ];

    public readonly CoreGateStatus $core;

    public readonly RosterGateStatus $roster;

    public readonly ParkingGateStatus $parking;

    /** @var array<string, string> step => status */
    public readonly array $statuses;

    public function __construct(public readonly Building $building)
    {
        $this->core = CoreGate::status($building);
        $this->roster = RosterGate::status($building);
        $this->parking = ParkingGate::status($building);
        $this->statuses = $this->compute();
    }

    public static function for(Building $building): self
    {
        return new self($building);
    }

    public function status(string $step): string
    {
        return $this->statuses[$step] ?? self::TODO;
    }

    /**
     * @return array{done: int, total: int}
     */
    public function progress(string $group): array
    {
        $steps = self::GROUPS[$group];

        return ['done' => count(array_filter($steps, fn (string $s): bool => $this->status($s) === self::DONE)), 'total' => count($steps)];
    }

    /**
     * The dashboard gate cards (GateStatusWidget): status, up to three missing items in plain words (+ how many more),
     * and the step "Turpināt" opens.
     *
     * @return array<string, array{open: bool, chip: string, missing: list<string>, more: int, step: ?string}>
     */
    public function gateCards(): array
    {
        $coreMissing = $this->core->missingLabels();
        $rosterMissing = $this->roster->missingLabels();

        if (! $this->parking->l1()) {
            $parkingKeys = $this->parking->missingL1;
            $parkingLabels = $this->parking->missingLabels(1);
            $chip = 'l0';
        } elseif (! $this->parking->l2()) {
            $parkingKeys = $this->parking->missingL2;
            $parkingLabels = $this->parking->missingLabels(2);
            $chip = 'l1';
        } else {
            $parkingKeys = $this->parking->moduleOn ? [] : ['parking_on'];
            $parkingLabels = $this->parking->moduleOn ? [] : [__('opening.missing.parking_on')];
            $chip = $this->parking->moduleOn ? 'l2_on' : 'l2_off';
        }

        return [
            'core' => $this->card($this->core->open, $this->core->open ? 'open' : 'closed', $coreMissing, $this->firstStep($this->core->missing)),
            'roster' => $this->card($this->roster->open, $this->roster->open ? 'open' : 'closed', $rosterMissing, $this->firstStep($this->roster->missing)),
            'parking' => $this->card($chip === 'l2_on', $chip, $parkingLabels, $this->firstStep($parkingKeys)),
        ];
    }

    /**
     * Elders per stairwell for the "team" step and the TeamWidget: section number => number of elders.
     *
     * @return array<int, int>
     */
    public function eldersPerSection(): array
    {
        $counts = BuildingRole::query()->current()->where('role', BuildingRoleType::SectionElder->value)
            ->selectRaw('section_id, count(*) AS n')->groupBy('section_id')->pluck('n', 'section_id');

        $out = [];
        foreach (Section::query()->orderBy('number')->get(['id', 'number']) as $section) {
            $out[(int) $section->number] = (int) ($counts[$section->id] ?? 0);
        }

        return $out;
    }

    /**
     * @param  list<string>  $conditions
     */
    private function firstStep(array $conditions): ?string
    {
        foreach ($conditions as $condition) {
            if ($condition === 'core_gate') {
                return $this->firstStep($this->core->missing) ?? 'dpa';
            }
            if ($condition === 'parking_on') {
                return 'parking_on';
            }
            if (isset(self::CONDITION_STEP[$condition])) {
                return self::CONDITION_STEP[$condition];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $labels
     * @return array{open: bool, chip: string, missing: list<string>, more: int, step: ?string}
     */
    private function card(bool $open, string $chip, array $labels, ?string $step): array
    {
        return [
            'open' => $open,
            'chip' => $chip,
            'missing' => array_slice($labels, 0, 3),
            'more' => max(0, count($labels) - 3),
            'step' => $step,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function compute(): array
    {
        $s = $this->building->settings;
        $notice = BuildingPrivacyNotice::current();
        $sentScope = BuildingDocument::query()->whereIn('kind', ['dpa', 'dpa_annex'])
            ->where('status', DocumentStatus::Sent->value)->get()
            ->flatMap(fn (BuildingDocument $d): array => $d->requestedScope())->unique()->values()->all();

        $scopeStep = function (string $module) use ($s, $sentScope): string {
            if (in_array($module, $s->dpaScope, true)) {
                return self::DONE;
            }

            return in_array($module, $sentScope, true) ? self::WAITING : self::TODO;
        };

        $dpaDone = $s->dpaSignedAt !== null && $s->dpaRef !== null && array_diff(CoreGate::REQUIRED_MODULES, $s->dpaScope) === [];
        $structure = Section::query()->exists() && Apartment::query()->active()->exists();
        $elders = $this->eldersPerSection();

        return [
            'structure' => $structure ? self::DONE : self::TODO,
            'team' => $elders !== [] && min($elders) > 0 ? self::DONE : self::OPTIONAL,
            'controller' => $s->controller['name'] !== null && $s->controller['contact'] !== null ? self::DONE : self::TODO,
            'dpa' => $dpaDone ? self::DONE : (array_diff(CoreGate::REQUIRED_MODULES, $sentScope) === [] ? self::WAITING : self::TODO),
            'notice' => $notice !== null && $notice->covers(CoreGate::REQUIRED_MODULES) ? self::DONE : self::TODO,
            'core_open' => $this->core->open ? self::DONE : self::TODO,
            'roster_scope' => $scopeStep('roster'),
            'roster_notice' => $notice !== null && $notice->covers(['roster']) ? self::DONE : self::TODO,
            'roster_inform' => $s->rosterInformedAt !== null ? self::DONE : self::OPTIONAL,
            'parking_scope' => $scopeStep('parking'),
            'dpia' => $s->dpiaCompletedAt !== null ? self::DONE : self::TODO,
            'mandate' => $s->mandateRef !== null ? self::DONE : self::TODO,
            'parking_notice' => $notice !== null && $notice->covers(['parking']) ? self::DONE : self::TODO,
            'parking_on' => $s->moduleEnabled('parking') ? self::DONE : self::TODO,
        ];
    }
}
