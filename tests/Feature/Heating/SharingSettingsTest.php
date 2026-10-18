<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Filament\Manage\Pages\BuildingSettingsPage;
use App\Models\AuditEntry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
 * BuildingSettingsPolicy::updateSharing (MVP-SPEC B.5): the manager-sharing flags need building_admin + a fresh
 * step-up; every change is audited (settings.sharing). apartment_numbers requires heating_floors.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('share-house', 1, 2);
    $this->admin = makeUser();
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->elder = makeUser();
    grantRole($this->elder, $this->b, BuildingRoleType::SectionElder, 1);
});

it('allows updateSharing only to building_admin with a fresh step-up', function (): void {
    $this->actingAs($this->admin);
    expect(inBuilding($this->b, fn () => Gate::allows('updateSharing', $this->b)))->toBeFalse();

    app(StepUpService::class)->markFresh($this->admin, 'totp');
    expect(inBuilding($this->b, fn () => Gate::allows('updateSharing', $this->b)))->toBeTrue();

    $this->actingAs($this->elder);
    app(StepUpService::class)->markFresh($this->elder, 'email');
    expect(inBuilding($this->b, fn () => Gate::allows('updateSharing', $this->b)))->toBeFalse();
});

it('saves the flags through the settings page with a dedicated audit entry', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    app(StepUpService::class)->markFresh($this->admin, 'totp');

    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(BuildingSettingsPage::class)
        ->set('data.share_heating_floors', true)
        ->set('data.share_apartment_numbers', true)
        ->set('data.share_decided_ref', 'Kopsapulce 2026-11-20')
        ->call('save')
        ->assertHasNoErrors());

    $settings = $this->b->refresh()->settings;
    $audit = inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'settings.sharing')->firstOrFail());

    expect($settings->shareWithManager['heating_floors'])->toBeTrue()
        ->and($settings->shareWithManager['apartment_numbers'])->toBeTrue()
        ->and($audit->data)->toMatchArray(['heating_floors' => true, 'apartment_numbers' => true]);
});

it('refuses a sharing change without a fresh step-up and never stores apartment numbers without floors', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);

    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(BuildingSettingsPage::class)
        ->set('data.share_heating_floors', true)
        ->call('save'));
    expect($this->b->refresh()->settings->shareWithManager['heating_floors'])->toBeFalse();

    app(StepUpService::class)->markFresh($this->admin, 'totp');
    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(BuildingSettingsPage::class)
        ->set('data.share_heating_floors', false)
        ->set('data.share_apartment_numbers', true)
        ->call('save'));
    expect($this->b->refresh()->settings->shareWithManager)->toMatchArray(['heating_floors' => false, 'apartment_numbers' => false]);
});
