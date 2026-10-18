<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\IncidentKind;
use App\Enums\LookupReason;
use App\Enums\ManagerReportKind;
use App\Enums\MembershipStatus;
use App\Enums\ParkingExportFormat;
use App\Enums\ParkingExportKind;
use App\Livewire\Home\Dashboard;
use App\Models\ApartmentParkingDeclaration;
use App\Models\BuildingPrivacyNotice;
use App\Models\ColumnStatus;
use App\Models\HeatingReport;
use App\Models\Incident;
use App\Models\IncidentConfirmation;
use App\Models\ManagerReport;
use App\Models\ParkingExport;
use App\Models\ParkingLot;
use App\Models\ParkingOfficerTask;
use App\Models\Permit;
use App\Models\PlateLookup;
use App\Models\PlateSightingStat;
use App\Models\PrivacyNoticeVersion;
use App\Models\RiserCheck;
use App\Models\RiserDispute;
use App\Models\RiserStatus;
use App\Models\Sighting;
use App\Models\SurveyRound;
use App\Models\Vehicle;
use App\Services\Heating\ManagerReportGenerator;
use App\Services\Heating\SectionScope;
use App\Services\Outages\IncidentService;
use App\Services\Parking\ParkingExportService;
use App\Services\Parking\PlateLookupService;
use App\Services\Parking\SightingService;
use App\Tenancy\Exceptions\MissingBuildingContext;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/**
 * Every GET route under /b/{building:slug} (resident group) with a concrete path for a building.
 *
 * @return list<string>
 */
function residentPaths(string $slug): array
{
    return collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'b/{building:slug}') || str_starts_with($r->uri(), 'b/{building}'))
        ->filter(fn (Route $r) => in_array('GET', $r->methods(), true))
        ->map(fn (Route $r) => '/'.strtr($r->uri(), [
            '{building:slug}' => $slug, '{building}' => $slug, '{batch}' => '01JABCDEFGHJKMNPQRSTVWXYZ0', '{export}' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
            '{incident}' => test()->incidentA ?? '01jabcdefghjkmnpqrstvwxyz0', '{report}' => test()->reportA ?? '01jabcdefghjkmnpqrstvwxyz0', '{format}' => 'pdf',
            '{sighting}' => test()->sightingA ?? '01jabcdefghjkmnpqrstvwxyz0',
        ]))
        ->values()
        ->all();
}

beforeEach(function (): void {
    $this->a = makeBuilding('ct-a', 1, 2, open: true);
    $this->b = makeBuilding('ct-b', 1, 2, open: true);
    foreach ([$this->a, $this->b] as $b) {
        $b->forceFill(['settings' => $b->settings->with(['modules' => ['heating' => true, 'outages' => true]])])->save();
    }
    // Package B rows of building A: an incident and a manager report (real ULIDs for the route placeholders).
    $adminA = makeUser('admin-a@example.test');
    grantRole($adminA, $this->a, BuildingRoleType::BuildingAdmin);
    $this->incidentA = inBuilding($this->a, fn () => app(IncidentService::class)->postNotice($adminA, IncidentKind::WaterCold, [
        'target' => 'building', 'reason_lv' => 'Tests', 'reason_ru' => 'Тест',
    ]))->id;
    $this->reportA = inBuilding($this->a, fn () => app(ManagerReportGenerator::class)->generate($adminA, ManagerReportKind::Heating, SectionScope::wholeBuilding()))->id;
    // Package C rows of building A (parking gate L2 in both houses): a vehicle, a sighting, an export request.
    foreach ([$this->a, $this->b] as $b) {
        $b->forceFill(['settings' => $b->settings->with([
            'modules' => ['heating' => true, 'outages' => true, 'parking' => true],
            'dpa_scope' => ['core', 'heating', 'outages', 'parking'], 'dpia_completed_at' => '2026-10-10', 'mandate_ref' => 'Lēmums 2',
            'parking_enforcement_mode' => 'both',
        ])])->save();
        inBuilding($b, fn () => BuildingPrivacyNotice::query()->create([
            'template_version_id' => PrivacyNoticeVersion::query()->where('kind', 'building_template')->value('id'),
            'controller_snapshot' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test'],
            'covers_modules' => ['core', 'heating', 'outages', 'parking'], 'published_at' => now()->subMinute(),
        ]));
    }
    $this->vehicleA = activeVehicle($this->a, 1, 'AB1234', parkingOfficer($this->a));
    $lotA = inBuilding($this->a, fn () => ParkingLot::query()->create(['name' => 'Pagalms A']));
    $reporterA = makeUser('reporter-a@example.test');
    member($reporterA, $this->a, 2);
    inBuilding($this->a, fn () => app(SightingService::class)->submit($reporterA, apartment($this->a, 2), $lotA, 'CD5678'));
    $this->sightingA = inBuilding($this->a, fn () => Sighting::query()->value('id'));
    $this->exportA = inBuilding($this->a, fn () => app(ParkingExportService::class)->request($adminA, ParkingExportKind::Manager, ParkingExportFormat::Csv))->id;

    $this->memberOfB = makeUser('member-b@example.test');
    member($this->memberOfB, $this->b, 1);
    $this->adminOfB = makeUser('admin-b@example.test');
    grantRole($this->adminOfB, $this->b, BuildingRoleType::BuildingAdmin);
    grantRole($this->adminOfB, $this->b, BuildingRoleType::SectionElder, 1);
});

it('covers every resident route', function (): void {
    expect(residentPaths('ct-a'))->toContain('/b/ct-a', '/b/ct-a/me', '/b/ct-a/help', '/b/ct-a/heating', '/b/ct-a/water',
        '/b/ct-a/incidents/'.$this->incidentA, '/b/ct-a/reports/'.$this->reportA.'.pdf',
        '/b/ct-a/parking', '/b/ct-a/parking/mine', '/b/ct-a/parking/declare', '/b/ct-a/parking/sighting', '/b/ct-a/parking/progress',
        '/b/ct-a/files/sightings/'.$this->sightingA.'/photo', '/b/ct-a/files/parking-sign');
});

it('keeps building A heating / outage rows unreachable from building B (scope → 404)', function (): void {
    steppedUp($this->adminOfB);
    $this->actingAs($this->adminOfB)->get('/b/ct-b/incidents/'.$this->incidentA)->assertNotFound();

    $signed = URL::temporarySignedRoute('building.manager-report', now()->addMinutes(5), ['building' => 'ct-b', 'report' => $this->reportA, 'format' => 'pdf']);
    $this->actingAs($this->adminOfB)->get($signed)->assertNotFound();

    foreach ([Incident::class, ManagerReport::class, HeatingReport::class, SurveyRound::class, RiserStatus::class, ColumnStatus::class, IncidentConfirmation::class, RiserCheck::class, RiserDispute::class] as $model) {
        expect(fn () => $model::query()->count())->toThrow(MissingBuildingContext::class);
    }
    expect(inBuilding($this->b, fn () => Incident::query()->whereKey($this->incidentA)->exists()))->toBeFalse();
});

it('keeps building A parking rows unreachable from building B (scope → 404, fail-closed without context)', function (): void {
    steppedUp($this->adminOfB);
    grantRole($this->adminOfB, $this->b, BuildingRoleType::ParkingOfficer);

    $photo = URL::temporarySignedRoute('building.sighting-photo', now()->addMinutes(5), ['building' => 'ct-b', 'sighting' => $this->sightingA]);
    $this->actingAs($this->adminOfB)->get($photo)->assertNotFound();
    $export = URL::temporarySignedRoute('building.parking-export', now()->addMinutes(5), ['building' => 'ct-b', 'export' => $this->exportA]);
    $this->actingAs($this->adminOfB)->get($export)->assertNotFound();

    foreach ([Vehicle::class, Permit::class, Sighting::class, PlateLookup::class, ParkingLot::class, ParkingOfficerTask::class,
        ApartmentParkingDeclaration::class, ParkingExport::class, PlateSightingStat::class] as $model) {
        expect(fn () => $model::query()->count())->toThrow(MissingBuildingContext::class);
    }

    expect(inBuilding($this->b, fn () => Vehicle::query()->whereKey($this->vehicleA->id)->exists()))->toBeFalse()
        ->and(inBuilding($this->b, fn () => Sighting::query()->whereKey($this->sightingA)->exists()))->toBeFalse();

    // The same plate in building B is NOT registered (per-building blind index).
    $resident = makeUser('lookup-b@example.test');
    member($resident, $this->b, 3);
    expect(inBuilding($this->b, fn () => app(PlateLookupService::class)->lookup($resident, 'AB1234', LookupReason::OwnCheck)->registered))->toBeFalse();
});

it('returns 404 for a non-member on every /b/{A} route, identical to an unknown slug', function (string $who): void {
    $user = $who === 'member of B' ? $this->memberOfB : $this->adminOfB;
    steppedUp($user);

    foreach (residentPaths('ct-a') as $path) {
        $this->actingAs($user)->get($path)->assertNotFound();
    }

    foreach (residentPaths('no-such-house') as $path) {
        $this->actingAs($user)->get($path)->assertNotFound();
    }
})->with(['member of B', 'admin + elder of B']);

it('runs no tenant query other than the user’s own membership/role lookup before the 404', function (): void {
    DB::enableQueryLog();
    $this->actingAs($this->memberOfB)->get('/b/ct-a')->assertNotFound();
    $queries = collect(DB::getQueryLog())->pluck('query');

    $tenantTables = ['sections', 'apartments', 'apartment_cells', 'rooms', 'risers', 'riser_links', 'apartment_invites', 'building_privacy_notices', 'land_parcels'];
    foreach ($queries as $sql) {
        foreach ($tenantTables as $t) {
            expect(str_contains($sql, '"'.$t.'"'))->toBeFalse("tenant table {$t} queried before 404: {$sql}");
        }
    }
});

it('lets members (approved and pending) and role holders in', function (): void {
    $pending = makeUser('pending-a@example.test');
    member($pending, $this->a, 3, MembershipStatus::Pending);

    $this->actingAs($this->memberOfB)->get('/b/ct-b')->assertOk();
    $this->actingAs($pending)->get('/b/ct-a')->assertOk()->assertSee(__('onboarding.pending.title'));
    $this->actingAs($this->adminOfB)->get('/b/ct-b')->assertOk();
});

it('keeps guests out (redirect to login, slug not revealed)', function (): void {
    $this->get('/b/ct-a')->assertRedirect(route('login'));
    $this->get('/b/no-such-house')->assertRedirect(route('login'));
});

it('returns 404 for a member of B who tampers a Livewire snapshot to building A', function (): void {
    $response = $this->actingAs($this->memberOfB)->get('/b/ct-b')->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $m);
    $snapshot = json_decode(htmlspecialchars_decode($m[1]), true);
    // Re-sign the tampered snapshot (worst case: the checksum would normally already reject it), so the test
    // proves the persistent middleware (SetCurrentBuilding → member) stops it.
    $snapshot['memo']['path'] = 'b/ct-a';
    unset($snapshot['checksum']);
    $snapshot['checksum'] = Checksum::generate($snapshot);

    $response = $this->actingAs($this->memberOfB)
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(EndpointResolver::updatePath(), [
            'components' => [['snapshot' => json_encode($snapshot), 'updates' => [], 'calls' => []]],
        ]);

    expect($response->getStatusCode())->toBe(404);
});

it('renders the home of building B with structure only for its member', function (): void {
    inBuilding($this->b, fn () => Livewire::actingAs($this->memberOfB)->test(Dashboard::class)->assertOk()->assertSee(__('home.my_house.title')));
});
