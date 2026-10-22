<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Filament\Manage\Resources\AuditEntryResource;
use App\Filament\Manage\Resources\MembershipResource\Pages\ListMemberships;
use App\Filament\Manage\Resources\SurveyRoundResource\Pages\ListSurveyRounds;
use App\Livewire\Heating\ReportSheet;
use App\Livewire\Me\Privacy;
use App\Models\ApartmentMembership;
use App\Models\AuditEntry;
use App\Models\SurveyRound;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Memberships\MembershipService;
use App\Services\Parking\VehicleRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * Production QA 2026-10-20 (pārvaldība, siltums-stāvvieta): survey round times, the empty heating report, former
 * members, audit log wording, deletion word, feedback toasts.
 */

it('stores survey round times exactly as entered in Riga time (no double conversion)', function (): void {
    $b = heatingHouse('qa-round-tz', 1, 3);
    $admin = makeUser('qa-round-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($b);
    app(StepUpService::class)->markFresh($admin, 'totp');

    inBuilding($b, fn () => Livewire::actingAs($admin)->test(ListSurveyRounds::class)
        ->callTableAction('create', data: [
            'title_lv' => 'Oktobris', 'title_ru' => 'Октябрь', 'whole_building' => true,
            'starts_at' => '2026-10-20 08:00:00', 'ends_at' => '2026-11-30 20:00:00', 'status' => 'open',
        ])
        ->assertHasNoTableActionErrors());

    $round = inBuilding($b, fn () => SurveyRound::query()->firstOrFail());
    expect($round->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-20 05:00')   // EEST = UTC+3
        ->and($round->ends_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-30 18:00');   // EET = UTC+2
});

it('explains an empty heating report next to the button and does not spend the report budget', function (): void {
    $b = heatingHouse('qa-empty-report', 1, 3);
    $resident = makeUser('qa-empty-res@example.test');
    member($resident, $b, 1);

    inBuilding($b, fn () => Livewire::actingAs($resident)->test(ReportSheet::class)
        ->call('save')
        ->assertHasErrors('states')
        ->assertSeeHtml('data-testid="report-error-near-save"'));

    expect(RateLimiter::attempts('heating-report:'.$resident->id))->toBe(0);
});

it('tells a rejected resident why instead of a bare 404, with the reason', function (): void {
    Notification::fake();
    $b = makeBuilding('qa-rejected', 1, 2, open: true);
    $admin = makeUser('qa-rej-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $resident = makeUser('qa-rej-res@example.test');
    $membership = member($resident, $b, 3, MembershipStatus::Pending);

    $this->actingAs($admin);
    app(StepUpService::class)->markFresh($admin, 'totp');
    inBuilding($b, fn () => app(MembershipService::class)->reject($admin, $membership, 'Nav šī dzīvokļa iedzīvotājs'));

    $this->flushSession();
    $this->actingAs($resident)->get('/b/qa-rejected')->assertRedirect(route('home.redirect'));
    $this->get('/home')->assertRedirect(route('join'));
    $this->get('/join')->assertOk()
        ->assertSee(__('onboarding.membership_rejected', ['building' => $b->name, 'apartment' => 3]))
        ->assertSee('Nav šī dzīvokļa iedzīvotājs');

    // A stranger still gets the anti-enumeration 404.
    $this->flushSession();
    $this->actingAs(makeUser('qa-stranger@example.test'))->get('/b/qa-rejected')->assertNotFound();
});

it('confirms ending a membership with a toast', function (): void {
    $b = makeBuilding('qa-end', 1, 2, open: true);
    $admin = makeUser('qa-end-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);
    $membership = member(makeUser('qa-end-res@example.test'), $b, 2);
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($b);
    app(StepUpService::class)->markFresh($admin, 'totp');

    inBuilding($b, fn () => Livewire::actingAs($admin)->test(ListMemberships::class)
        ->filterTable('status', 'approved')
        ->callTableAction('end', $membership)
        ->assertNotified(__('admin.membership.ended')));

    expect(inBuilding($b, fn () => ApartmentMembership::query()->find($membership->id)->status))->toBe(MembershipStatus::Ended);
});

it('names the audit subject and translates every recorded action', function (): void {
    expect(AuditEntryResource::subjectLabel('App\\Models\\RiserCheck'))->toBe(__('audit.subject.riser_check'))
        ->and(AuditEntryResource::subjectLabel('invite_scope'))->toBe(__('audit.subject.invite_scope'))
        ->and(AuditEntryResource::subjectLabel(null))->toBe('—');

    $actions = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            preg_match_all("/AuditLogger::record\\(\\s*(?:action:\\s*)?'([a-z_.]+)'/", (string) file_get_contents($file->getPathname()), $m);
            $actions = [...$actions, ...$m[1]];
        }
    }
    $actions = [...$actions, 'riser.dispute_resolved', 'riser.dispute_rejected'];

    $missing = array_values(array_filter(array_unique($actions), fn (string $a): bool => ! str_ends_with($a, '_') && __('audit.action.'.$a) === 'audit.action.'.$a));
    expect($missing)->toBe([]);
});

it('attributes an officer\'s vehicle approval to the officer role, not "Sistēma"', function (): void {
    $b = parkingHouse('qa-park-actor', 2);
    $officer = parkingOfficer($b);
    $resident = parkingResident($b, 2);
    $this->actingAs($resident);
    inBuilding($b, fn () => app(VehicleRegistry::class)->declare($resident, apartment($b, 2), 'QA1234'));
    $vehicle = inBuilding($b, fn () => Vehicle::query()->where('apartment_id', apartment($b, 2)->id)->firstOrFail());

    $this->actingAs($officer);
    inBuilding($b, fn () => app(VehicleRegistry::class)->activate($officer, $vehicle));

    $actors = inBuilding($b, fn () => AuditEntry::query()->whereIn('action', ['vehicle.declare', 'vehicle.activate'])->pluck('actor_role', 'action')->all());
    expect(collect($actors)->sortKeys()->all())->toBe(['vehicle.activate' => 'parking_officer', 'vehicle.declare' => 'self']);
});

it('accepts the deletion word without the long vowel (DZEST)', function (): void {
    $user = makeUser('qa-dzest@example.test');
    $this->actingAs($user);

    Livewire::actingAs($user)->test(Privacy::class)->call('askDelete')->set('deleteWord', 'dzest')->call('delete')->assertRedirect(route('landing'));

    expect(User::withTrashed()->find($user->id)->isTombstone())->toBeTrue();
});

it('uses non-contradicting outage copy and grammatical invite / reveal texts', function (): void {
    expect(trans_choice('admin.invites.revoked', 1, ['count' => 1]))->toBe('Atsaukts 1 kods.')
        ->and(trans_choice('admin.invites.revoked', 3, ['count' => 3]))->toBe('Atsaukti 3 kodi.')
        ->and(__('admin.parking.reveal.logged'))->toContain('10 reizes dienā')
        ->and(__('filament-tables::table.empty.description'))->not->toContain(':model');
});
