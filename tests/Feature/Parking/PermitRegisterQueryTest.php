<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\OfficerTaskKind;
use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Filament\Manage\Resources\PermitResource\Pages\ListPermits;
use App\Models\AuditEntry;
use App\Models\ParkingOfficerTask;
use App\Models\Permit;
use App\Services\Parking\PermitObservationService;
use App\Services\Parking\PermitRegisterQuery;
use App\Services\Parking\PermitService;
use App\Services\Parking\VehicleRegistry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * ROUND2-SPEC §12 Feature/Parking/PermitRegisterQueryTest (PARK-011, AC-R2-15): the register flags and filters —
 * "> 1 caurlaide" (≥ 2 main or > 2 in total), "viesu caurlaide vienmēr vienam auto" (≥ 3 observations, ≥ 3 days,
 * ≥ 14 days span, one plate), main without vehicle, vehicle pending, conflict — come from SQL over blind indexes and
 * return exactly the expected synthetic permits; no plate is ever rendered in the register.
 */
beforeEach(function (): void {
    $this->b = parkingHouse('register-house', level: 1);
    $this->officer = parkingOfficer($this->b, 'register-officer@example.test');
    $this->actingAs($this->officer);

    inBuilding($this->b, function (): void {
        $permits = app(PermitService::class);
        $registry = app(VehicleRegistry::class);
        $from = CarbonImmutable::parse('2026-09-01');

        // Apartment 1: two main permits → multi_main.
        $a1 = apartment($this->b, 1);
        $permits->issue($this->officer, $a1, 'P-1', PermitType::Main, $registry->officerCreate($this->officer, $a1, 'AB1234'), $from);
        $permits->issue($this->officer, $a1, 'P-2', PermitType::Main, $registry->officerCreate($this->officer, $a1, 'CD5678'), $from);

        // Apartment 2: one main + two guests → > 2 in total (filter), no multi_main flag.
        $a2 = apartment($this->b, 2);
        $permits->issue($this->officer, $a2, 'P-3', PermitType::Main, $registry->officerCreate($this->officer, $a2, 'ZZ0001'), $from);
        $this->sameCar = $permits->issue($this->officer, $a2, 'V-1', PermitType::Guest, null, $from);
        $this->twoCars = $permits->issue($this->officer, $a2, 'V-2', PermitType::Guest, null, $from);

        // Apartment 3: one guest, too few observations.
        $this->fewDays = $permits->issue($this->officer, apartment($this->b, 3), 'V-3', PermitType::Guest, null, $from);

        $obs = app(PermitObservationService::class);
        $today = CarbonImmutable::now('Europe/Riga')->startOfDay();
        foreach ([20, 10, 0] as $daysAgo) {
            $obs->record($this->officer, $this->sameCar, 'ZZ 0007', $today->subDays($daysAgo));
        }
        foreach ([20, 10] as $daysAgo) {
            $obs->record($this->officer, $this->twoCars, 'ZZ0008', $today->subDays($daysAgo));
        }
        $obs->record($this->officer, $this->twoCars, 'ZZ0009', $today);
        foreach ([2, 1, 0] as $daysAgo) {
            $obs->record($this->officer, $this->fewDays, 'ZZ0010', $today->subDays($daysAgo));
        }
    });
});

function registerNumbers(callable $scope): array
{
    return $scope(Permit::query())->orderBy('number')->pluck('number')->all();
}

it('computes the flags of a page in one query', function (): void {
    inBuilding($this->b, function (): void {
        $ids = Permit::query()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $flags = app(PermitRegisterQuery::class)->flags($ids);
        expect($queries)->toBe(1);

        $byNumber = Permit::query()->pluck('id', 'number')->map(fn ($id) => $flags[(int) $id]);
        expect($byNumber['P-1']['flags'])->toBe(['multi_main'])
            ->and($byNumber['P-2']['flags'])->toBe(['multi_main'])
            ->and($byNumber['P-3']['flags'])->toBe([])
            ->and($byNumber['V-1']['flags'])->toBe(['guest_same_car'])
            ->and($byNumber['V-2']['flags'])->toBe([])
            ->and($byNumber['V-3']['flags'])->toBe([])
            ->and($byNumber['P-3']['main'])->toBe(1)->and($byNumber['P-3']['total'])->toBe(3)
            ->and($byNumber['V-1']['observations'])->toBe(3);
    });
});

it('filters "> 1 caurlaide" and "viesu caurlaide vienmēr vienam auto"', function (): void {
    inBuilding($this->b, function (): void {
        expect(registerNumbers(fn ($q) => PermitRegisterQuery::whereMultiPermit($q)))->toBe(['P-1', 'P-2', 'P-3', 'V-1', 'V-2'])
            ->and(registerNumbers(fn ($q) => PermitRegisterQuery::whereGuestSameCar($q)))->toBe(['V-1']);

        // Thresholds come from config.
        config(['majas.parking.guest_same_car.min_span_days' => 1]);
        expect(registerNumbers(fn ($q) => PermitRegisterQuery::whereGuestSameCar($q)))->toBe(['V-1', 'V-3']);
    });
});

it('flags main permits without an active vehicle, pending vehicles and conflicts', function (): void {
    inBuilding($this->b, function (): void {
        $resident = parkingResident($this->b, 4);
        app(VehicleRegistry::class)->declare($resident, apartment($this->b, 4), 'AB1234'); // active in apartment 1 → conflict
        $guest4 = app(PermitService::class)->issue($this->officer, apartment($this->b, 4), 'V-4', PermitType::Guest, null, CarbonImmutable::now());
        $p3 = Permit::query()->where('number', 'P-3')->firstOrFail();
        ParkingOfficerTask::query()->create(['kind' => OfficerTaskKind::PermitWithoutVehicle, 'permit_id' => $p3->id]);

        expect(registerNumbers(fn ($q) => PermitRegisterQuery::whereVehiclePending($q)))->toBe(['V-4'])
            ->and(registerNumbers(fn ($q) => PermitRegisterQuery::whereConflict($q)))->toBe(['V-4'])
            ->and(registerNumbers(fn ($q) => PermitRegisterQuery::whereMainWithoutVehicle($q)))->toBe(['P-3'])
            ->and(app(PermitRegisterQuery::class)->flags([(int) $guest4->id])[(int) $guest4->id]['flags'])->toBe(['vehicle_pending', 'conflict']);
    });
});

it('renders the register with filters, flags and counts — never a plate', function (): void {
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    app(StepUpService::class)->markFresh($this->officer, 'totp');

    $this->get('/admin/b/register-house/permits')->assertOk()
        ->assertSee('P-1')->assertSee(__('admin.parking.register.flag.multi_main'))
        ->assertSee(__('admin.parking.register.flag.guest_same_car'))
        ->assertSee(__('admin.parking.register.apartment_counts', ['main' => 1, 'total' => 3]))
        ->assertDontSee('AB1234')->assertDontSee('CD5678')->assertDontSee('ZZ0007')->assertDontSee('AB••34');

    inBuilding($this->b, function (): void {
        Livewire::actingAs($this->officer)->test(ListPermits::class)
            ->filterTable('guest_same_car')
            ->assertCanSeeTableRecords(Permit::query()->where('number', 'V-1')->get())
            ->assertCanNotSeeTableRecords(Permit::query()->whereIn('number', ['V-2', 'V-3', 'P-1'])->get())
            ->removeTableFilter('guest_same_car')
            ->filterTable('multi')
            ->assertCanSeeTableRecords(Permit::query()->whereIn('number', ['P-1', 'P-2', 'P-3'])->get())
            ->assertCanNotSeeTableRecords(Permit::query()->where('number', 'V-3')->get())
            ->assertDontSeeHtml('ZZ0001');
    });
});

it('records an observation from the register (guest only, sealed, audited) and extends permits in bulk', function (): void {
    $this->actingAs($this->officer);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    app(StepUpService::class)->markFresh($this->officer, 'totp');

    inBuilding($this->b, function (): void {
        $guest = Permit::query()->where('number', 'V-3')->firstOrFail();
        $main = Permit::query()->where('number', 'P-1')->firstOrFail();
        $audited = AuditEntry::query()->where('action', 'permit.observe')->count();

        Livewire::actingAs($this->officer)->test(ListPermits::class)
            ->assertTableActionHidden('observe', $main)
            ->callTableAction('observe', $guest, ['plate' => 'ZZ0011', 'observed_on' => now('Europe/Riga')->toDateString()])
            ->assertHasNoTableActionErrors();

        expect($guest->observations()->count())->toBe(4)
            ->and(AuditEntry::query()->where('action', 'permit.observe')->count())->toBe($audited + 1);

        $to = CarbonImmutable::parse('2028-01-31');
        Livewire::actingAs($this->officer)->test(ListPermits::class)
            ->callTableBulkAction('extend', Permit::query()->whereIn('number', ['P-1', 'P-2'])->get(), ['valid_to' => $to->toDateString()]);

        expect(Permit::query()->where('number', 'P-1')->firstOrFail()->valid_to?->toDateString())->toBe('2028-01-31')
            ->and(Permit::query()->where('number', 'P-3')->value('valid_to'))->toBeNull()
            ->and(AuditEntry::query()->where('action', 'permit.extend')->count())->toBe(2);
    });
});

it('lets only parking staff at L1 see the register', function (): void {
    $elder = makeUser('register-elder@example.test');
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 1);
    steppedUp($elder);
    $this->actingAs($elder)->get('/admin/b/register-house/permits')->assertForbidden();

    inBuilding($this->b, function (): void {
        $permit = Permit::query()->where('number', 'P-1')->firstOrFail();
        expect($permit->status)->toBe(PermitStatus::Active);
    });
});
