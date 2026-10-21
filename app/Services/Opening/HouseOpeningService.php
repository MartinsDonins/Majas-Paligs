<?php

declare(strict_types=1);

namespace App\Services\Opening;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Gdpr\CoreGate;
use App\Gdpr\CoreGateMonitor;
use App\Gdpr\ParkingGate;
use App\Gdpr\ParkingGateMonitor;
use App\Gdpr\RosterGate;
use App\Gdpr\RosterGateMonitor;
use App\Models\Building;
use App\Models\User;
use App\Services\Documents\DocumentRefused;
use App\Support\BuildingSettings;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * The settings steps of the house opening wizard (ROUND2-SPEC §3.2): controller, mandate, Art. 14 roster
 * information date and the resident parking switch. The wizard is the ONE writer of these gate inputs (the settings
 * page shows them read-only). Every save: building_admin + a fresh second factor, audited `opening.<step>`, and the
 * gate monitors run on the before/after statuses.
 */
final class HouseOpeningService
{
    public function __construct(private readonly StepUpService $stepUp) {}

    /**
     * @param  array{name?: ?string, contact?: ?string, basis_ref?: ?string}  $controller
     *
     * @throws AuthorizationException|DocumentRefused
     */
    public function saveController(User $actor, array $controller): Building
    {
        return $this->save($actor, 'controller', fn (BuildingSettings $s): BuildingSettings => $s->with(['controller' => [
            'name' => $this->clip($controller['name'] ?? null),
            'contact' => $this->clip($controller['contact'] ?? null),
            'basis_ref' => $this->clip($controller['basis_ref'] ?? null),
        ]]));
    }

    /**
     * @throws AuthorizationException|DocumentRefused
     */
    public function saveMandate(User $actor, ?string $mandateRef): Building
    {
        return $this->save($actor, 'mandate', fn (BuildingSettings $s): BuildingSettings => $s->with(['mandate_ref' => $this->clip($mandateRef)]), parking: true);
    }

    /**
     * "Paziņojums izsūtīts / izlikts" (Art. 14) — the date residents were informed about the roster; null clears it.
     *
     * @throws AuthorizationException|DocumentRefused
     */
    public function markRosterInformed(User $actor, ?string $date): Building
    {
        $date = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : ($date === null ? null : CarbonImmutable::now('Europe/Riga')->toDateString());

        return $this->save($actor, 'roster_inform', fn (BuildingSettings $s): BuildingSettings => $s->with(['roster_informed_at' => $date]));
    }

    /**
     * Resident parking on / off — on only at gate L2 (existing rule; ParkingGateMonitor switches it off below L2).
     *
     * @throws AuthorizationException|DocumentRefused
     */
    public function setParkingModule(User $actor, bool $on): Building
    {
        $building = CurrentBuilding::get();
        if ($on && ! ParkingGate::status($building)->l2()) {
            throw new DocumentRefused('parking_needs_l2');
        }

        return $this->save($actor, 'parking_on', fn (BuildingSettings $s): BuildingSettings => $s->with(['modules' => ['parking' => $on]]), parking: true);
    }

    /**
     * @param  callable(BuildingSettings): BuildingSettings  $change
     *
     * @throws AuthorizationException|DocumentRefused
     */
    private function save(User $actor, string $step, callable $change, bool $parking = false): Building
    {
        $building = CurrentBuilding::get();

        $ability = $parking ? 'updateParkingGate' : 'update';
        if (! Gate::forUser($actor)->allows($ability, $building)) {
            throw new AuthorizationException('Only a building_admin changes the opening steps.');
        }
        $interactive = ! app()->runningInConsole() || app()->runningUnitTests();
        if ($interactive && ! $this->stepUp->hasFreshMfa($actor)) {
            throw new AuthorizationException('A fresh second factor is required for the opening steps.');
        }

        $before = CoreGate::status($building);
        $parkingBefore = ParkingGate::status($building);
        $rosterBefore = RosterGate::status($building);
        $old = $building->settings->toArray();

        try {
            $settings = $change($building->settings);
        } catch (\InvalidArgumentException) {
            throw new DocumentRefused('scope_invalid');
        }

        $building->forceFill(['settings' => $settings])->save();

        $changed = array_keys(array_filter($settings->toArray(), static fn ($v, $k): bool => ($old[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        // opening.controller | opening.mandate | opening.roster_inform | opening.parking_on (all translated).
        $action = 'opening.'.$step;
        AuditLogger::record($action, $building, data: ['changed' => $changed], actorUserId: (int) $actor->id);

        CoreGateMonitor::afterChange($building, $before);
        ParkingGateMonitor::afterChange($building, $parkingBefore);
        RosterGateMonitor::afterChange($building, $rosterBefore);

        return $building->refresh();
    }

    private function clip(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 200);
    }
}
