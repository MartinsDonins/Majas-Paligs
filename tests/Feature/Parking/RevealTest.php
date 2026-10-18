<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\RevealReason;
use App\Livewire\Me\WhoViewed;
use App\Models\AuditEntry;
use App\Models\Vehicle;
use App\Notifications\VehicleRevealedNotice;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\RevealService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * MVP-SPEC C.8 Feature/Parking/RevealTest (AC 5): roles, step-up, reason (text for "other"), cap 10/day, audit with
 * the apartment's members as subjects, visible in their "Kas skatīja manus datus", e-mail notice without the plate.
 */
beforeEach(function (): void {
    $this->b = parkingHouse('reveal-house');
    $this->officer = parkingOfficer($this->b, 'reveal-officer@example.test');
    $this->car = activeVehicle($this->b, 3, 'AB1234', $this->officer);
    $this->owner = parkingResident($this->b, 3);
    $this->actingAs($this->officer);
});

it('allows reveals only to parking_officer / building_admin with a fresh step-up', function (): void {
    $elder = makeUser();
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 1);

    inBuilding($this->b, function () use ($elder): void {
        expect(Gate::forUser($this->officer)->allows('reveal', $this->car))->toBeFalse(); // no step-up yet
        app(StepUpService::class)->markFresh($this->officer, 'email');
        expect(Gate::forUser($this->officer)->allows('reveal', $this->car))->toBeTrue();

        app(StepUpService::class)->markFresh($elder, 'email');
        expect(Gate::forUser($elder)->allows('reveal', $this->car))->toBeFalse()   // elders never see plates
            ->and(Gate::forUser($this->owner)->allows('reveal', $this->car))->toBeFalse();
    });
});

it('reveals plate + apartment, audits with subject users, notifies them without the plate', function (): void {
    Notification::fake();

    $r = inBuilding($this->b, fn () => app(RevealService::class)->revealVehicle($this->officer, $this->car, RevealReason::BlockingExit));
    expect($r)->toBe(['plate' => 'AB1234', 'apartment' => 3]);

    $audit = inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'vehicle.reveal')->firstOrFail());
    expect($audit->subject_user_ids)->toBe([(int) $this->owner->id])
        ->and($audit->reason_code)->toBe('blocking_exit')
        ->and(json_encode($audit->data))->not->toContain('AB1234');

    Notification::assertSentTo($this->owner, VehicleRevealedNotice::class, function (VehicleRevealedNotice $n): bool {
        $mail = $n->toMail($this->owner);

        return ! str_contains(json_encode([$mail->subject, $mail->introLines]), 'AB1234');
    });

    // Visible to the data subject in "Kas skatīja manus datus" (role only, never a name).
    Livewire::actingAs($this->owner)->test(WhoViewed::class)
        ->assertSee(__('audit.action.vehicle.reveal'))
        ->assertSee(__('audit.actor.parking_officer'))
        ->assertDontSee('reveal-officer@example.test');
});

it('finds the apartment of a plate, and audits a search that finds nothing', function (): void {
    $found = inBuilding($this->b, fn () => app(RevealService::class)->findByPlate($this->officer, 'ab-1234', RevealReason::PoliceRequest));
    $none = inBuilding($this->b, fn () => app(RevealService::class)->findByPlate($this->officer, 'ZZ0000', RevealReason::Damage));

    expect($found['apartment'])->toBe(3)->and($none)->toBeNull()
        ->and(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'vehicle.reveal_search')->count()))->toBe(2);
});

it('requires a text for "other" and caps reveals at 10 a day', function (): void {
    expect(fn () => inBuilding($this->b, fn () => app(RevealService::class)->revealVehicle($this->officer, $this->car, RevealReason::Other)))
        ->toThrow(ParkingActionFailed::class, 'reason_text');

    inBuilding($this->b, function (): void {
        for ($i = 0; $i < 10; $i++) {
            app(RevealService::class)->revealVehicle($this->officer, $this->car, RevealReason::Other, 'Pārbaude '.$i);
        }
        expect(fn () => app(RevealService::class)->revealVehicle($this->officer, $this->car, RevealReason::Damage))->toThrow(ParkingActionFailed::class, 'cap')
            ->and(fn () => app(RevealService::class)->findByPlate($this->officer, 'AB1234', RevealReason::Damage))->toThrow(ParkingActionFailed::class, 'cap');
    });
});

it('masks plates in the vehicle list and the reveal action needs step-up in Filament', function (): void {
    steppedUp($this->officer);
    $this->get('/manage/reveal-house/vehicles')->assertOk()->assertSee('AB••34')->assertDontSee('AB1234');
    expect(inBuilding($this->b, fn () => Vehicle::query()->count()))->toBe(1);
});
