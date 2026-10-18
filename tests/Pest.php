<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Casts\Bytea;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PrivacyNoticeKind;
use App\Enums\ReportSource;
use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Models\BuildingRole;
use App\Models\BuildingSeries;
use App\Models\PrivacyNoticeVersion;
use App\Models\Riser;
use App\Models\Section;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Buildings\BuildingGenerator;
use App\Services\Buildings\GeneratorInput;
use App\Services\Buildings\SeriesTemplateData;
use App\Services\Heating\HeatingGridQuery;
use App\Services\Heating\HeatingReportService;
use App\Services\Heating\HeatingSubmission;
use App\Services\Heating\Views\HeatingSectionView;
use App\Services\Invites\InviteCodeService;
use App\Services\Parking\VehicleRegistry;
use App\Tenancy\CurrentBuilding;
use Database\Seeders\PrivacyNoticeSeeder;
use Database\Seeders\Series602Seeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DbRoles;
use Tests\TestCase;

/*
 * Synthetic data only (MVP-SPEC §0): fictional e-mails @example.test, no real residents, phones or plates.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit', 'Arch');

/**
 * Run $callback inside a building context (test helper; app code uses the allow-listed classes only).
 *
 * @template T
 *
 * @param  callable(Building): T  $callback
 * @return T
 */
function inBuilding(Building $building, callable $callback): mixed
{
    return CurrentBuilding::runFor($building, $callback);
}

function series602(): BuildingSeries
{
    if (! BuildingSeries::query()->where('code', '602')->exists()) {
        (new Series602Seeder)->run();
    }

    return BuildingSeries::query()->where('code', '602')->firstOrFail();
}

/**
 * A generated building (default: 2 stairwells × 3 floors × 4 apartments, numbering 1..24).
 */
function makeBuilding(string $slug = 'test-house', int $sections = 2, int $floors = 3, string $layout = '2-2-3-3', bool $open = false): Building
{
    $series = series602();

    $building = Building::query()->create([
        'slug' => $slug,
        'name' => 'Testa iela '.strlen($slug),
        'address_line' => 'Testa iela '.strlen($slug),
        'city' => 'Rīga',
        'series_id' => $series->id,
        'floors' => $floors,
    ]);

    inBuilding($building, fn (Building $b) => app(BuildingGenerator::class)->generate(
        $b,
        SeriesTemplateData::fromSeries($series),
        GeneratorInput::uniform($sections, $floors, $layout),
    ));

    if ($open) {
        openGate($building);
    }

    return $building->refresh();
}

/**
 * Satisfy gate L-core: controller, DPA (core/heating/outages), published building notice.
 */
function openGate(Building $building): BuildingPrivacyNotice
{
    $building->forceFill(['settings' => $building->settings->with([
        'controller' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test', 'basis_ref' => 'Lēmums 1'],
        'dpa_signed_at' => '2026-10-01',
        'dpa_ref' => 'DPA-TEST-1',
        'dpa_scope' => ['core', 'heating', 'outages'],
    ])])->save();

    if (! PrivacyNoticeVersion::query()->exists()) {
        (new PrivacyNoticeSeeder)->run();
    }
    PrivacyNoticeVersion::query()->update(['published_at' => now()->subDay()]);
    $template = PrivacyNoticeVersion::query()->where('kind', PrivacyNoticeKind::BuildingTemplate->value)->firstOrFail();

    return inBuilding($building, fn () => BuildingPrivacyNotice::query()->create([
        'template_version_id' => $template->id,
        'controller_snapshot' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test'],
        'covers_modules' => ['core', 'heating', 'outages'],
        'published_at' => now()->subHour(),
    ]));
}

function makeUser(?string $email = null, array $attributes = []): User
{
    static $n = 0;
    $n++;

    $user = User::query()->create(['email' => $email ?? "user{$n}@example.test", 'locale' => 'lv']);
    $user->forceFill(['email_verified_at' => now()] + $attributes)->save();

    return $user->refresh();
}

function apartment(Building $building, int $number): Apartment
{
    return inBuilding($building, fn () => Apartment::query()->where('number', $number)->firstOrFail());
}

function section(Building $building, int $number): Section
{
    return inBuilding($building, fn () => Section::query()->where('number', $number)->firstOrFail());
}

/**
 * Membership without the gate guard (fixtures). Gate-related tests create memberships through the real flow.
 */
function member(User $user, Building $building, int $apartmentNumber, MembershipStatus $status = MembershipStatus::Approved, MembershipRole $role = MembershipRole::Owner): ApartmentMembership
{
    $apartment = apartment($building, $apartmentNumber);

    return inBuilding($building, function () use ($user, $apartment, $status, $role, $building): ApartmentMembership {
        $wasOpen = CoreGate::status($building)->open;
        if (! $wasOpen) {
            openGate($building);
        }

        return ApartmentMembership::query()->create([
            'apartment_id' => $apartment->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'approved_at' => $status === MembershipStatus::Approved ? now() : null,
        ]);
    });
}

function grantRole(User $user, Building $building, BuildingRoleType $role, ?int $sectionNumber = null): BuildingRole
{
    $sectionId = $sectionNumber !== null ? section($building, $sectionNumber)->id : null;

    return inBuilding($building, fn () => BuildingRole::query()->create([
        'user_id' => $user->id,
        'role' => $role,
        'section_id' => $sectionId,
        'granted_at' => now(),
        'expires_at' => now()->addYear(),
    ]));
}

/**
 * Create an invite and return the PLAINTEXT code (test only; production keeps codes in memory during print).
 */
function makeInvite(Building $building, int $apartmentNumber, array $attributes = []): string
{
    $codes = app(InviteCodeService::class);
    $code = $codes->generate();
    $apartment = apartment($building, $apartmentNumber);

    inBuilding($building, fn () => ApartmentInvite::query()->create($attributes + [
        'apartment_id' => $apartment->id,
        'code_hmac' => $codes->hmac($code),
        'max_uses' => 3,
        'expires_at' => now()->addDays(60),
    ]));

    return $code;
}

/**
 * Mark the session as freshly stepped-up (method: email|totp).
 */
/**
 * A fresh step-up in the test session. Default method = what the user's roles need (TOTP for building_admin /
 * parking_officer / platform_admin — StepUpService::requiresMfa, e-mail code otherwise).
 */
function steppedUp(User $user, ?string $method = null): void
{
    $method ??= app(StepUpService::class)->requiresMfa($user) ? 'totp' : 'email';
    test()->withSession([StepUpService::SESSION_KEY => ['user' => $user->id, 'at' => now()->getTimestamp(), 'method' => $method]]);
}

/*
 * ---- Package B helpers (heating & outages) ----
 */

/**
 * A generated, gate-open building with the heating + outages modules on (default 2 stairwells × 9 floors × 4).
 */
function heatingHouse(string $slug = 'heat-house', int $sections = 2, int $floors = 9, array $modules = ['heating' => true, 'outages' => true]): Building
{
    $b = makeBuilding($slug, $sections, $floors, open: true);
    $b->forceFill(['settings' => $b->settings->with(['modules' => $modules])])->save();

    return $b->refresh();
}

/**
 * Submit a heating report for an apartment as $user (states: room code => status).
 *
 * @param  array<string, string>  $states
 */
function reportHeating(User $user, Building $b, int $apartmentNumber, array $states, ReportSource $source = ReportSource::Self): HeatingSubmission
{
    $apartment = apartment($b, $apartmentNumber);

    return inBuilding($b, fn () => app(HeatingReportService::class)->submit($user, $apartment, $states, source: $source));
}

/**
 * Approved resident of an apartment who reports cold/warm in every radiator room.
 */
function residentReports(Building $b, int $apartmentNumber, string $status = 'cold', ?array $states = null): User
{
    $user = makeUser();
    member($user, $b, $apartmentNumber);
    reportHeating($user, $b, $apartmentNumber, $states ?? ['kitchen' => $status, 'room1' => $status, 'room2' => $status]);

    return $user;
}

function residentSectionView(Building $b, int $sectionNumber, string $roundKey = 'rolling'): HeatingSectionView
{
    return inBuilding($b, fn () => app(HeatingGridQuery::class)->residentSection(section($b, $sectionNumber), $roundKey));
}

function riserId(Building $b, string $code): int
{
    return (int) inBuilding($b, fn () => Riser::query()->where('code', $code)->value('id'));
}

/*
 * ---- Package C helpers (parking) ----
 */

/**
 * A generated, gate-open building at parking gate level $level (0, 1 or 2); at L2 the resident module is ON unless
 * $module is false. Default: 2 stairwells × 3 floors × 4 apartments (1..24).
 */
function parkingHouse(string $slug = 'park-house', int $level = 2, bool $module = true, int $sections = 2, int $floors = 3): Building
{
    $b = makeBuilding($slug, $sections, $floors, open: true);

    if ($level >= 1) {
        $b->forceFill(['settings' => $b->settings->with(['dpa_scope' => ['core', 'heating', 'outages', 'parking']])])->save();
    }

    if ($level >= 2) {
        $b->forceFill(['settings' => $b->settings->with([
            'dpia_completed_at' => '2026-10-10',
            'mandate_ref' => 'Kopības lēmums Nr. 1 (tests)',
            'modules' => ['parking' => $module],
        ])])->save();

        $template = PrivacyNoticeVersion::query()->where('kind', PrivacyNoticeKind::BuildingTemplate->value)->orderByDesc('id')->firstOrFail();
        inBuilding($b, fn () => BuildingPrivacyNotice::query()->create([
            'template_version_id' => $template->id,
            'controller_snapshot' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test'],
            'covers_modules' => ['core', 'heating', 'outages', 'parking'],
            'published_at' => now()->subMinutes(5),
        ]));
    }

    return $b->refresh();
}

/**
 * parking_officer of the building (with an e-mail, so step-up works).
 */
function parkingOfficer(Building $b, ?string $email = null): User
{
    $officer = makeUser($email);
    grantRole($officer, $b, BuildingRoleType::ParkingOfficer);

    return $officer;
}

/**
 * An ACTIVE vehicle of an apartment, created by an officer (synthetic plates only, e.g. "AB1234").
 */
function activeVehicle(Building $b, int $apartmentNumber, string $plate, ?User $officer = null): Vehicle
{
    $officer ??= parkingOfficer($b);
    $apartment = apartment($b, $apartmentNumber);

    return inBuilding($b, fn () => app(VehicleRegistry::class)->officerCreate($officer, $apartment, $plate));
}

/**
 * Approved owner of an apartment in a parking house.
 */
function parkingResident(Building $b, int $apartmentNumber, MembershipRole $role = MembershipRole::Owner): User
{
    $user = makeUser();
    member($user, $b, $apartmentNumber, role: $role);

    return $user;
}

/*
 * Committed fixtures for retention tests (the majas_retention connection cannot see the test transaction).
 */
function retentionBuilding(Connection $owner, string $slug): array
{
    $now = now();
    $buildingId = (int) $owner->table('buildings')->insertGetId([
        'slug' => $slug, 'name' => $slug, 'address_line' => $slug, 'address_search' => $slug, 'city' => 'Rīga', 'floors' => 1,
        'settings' => '{}', 'status' => 'draft', 'default_locale' => 'lv', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $sectionId = (int) $owner->table('sections')->insertGetId(['building_id' => $buildingId, 'number' => 1, 'first_apartment_no' => 1, 'apartments_per_floor' => 4, 'floors' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $apartments = [];
    foreach ([1, 2, 3, 4] as $n) {
        $apartments[$n] = (int) $owner->table('apartments')->insertGetId(['building_id' => $buildingId, 'section_id' => $sectionId, 'number' => $n, 'floor' => 1, 'position' => $n, 'created_at' => $now, 'updated_at' => $now]);
    }

    return [$buildingId, $apartments];
}

function retentionVehicle(Connection $owner, int $buildingId, int $apartmentId, array $attrs = []): string
{
    $id = strtolower((string) Str::ulid());
    $owner->table('vehicles')->insert($attrs + [
        'id' => $id, 'building_id' => $buildingId, 'apartment_id' => $apartmentId,
        'plate_ciphertext' => Bytea::literal(random_bytes(40)), 'plate_bidx' => Bytea::literal(random_bytes(32)), 'plate_key_version' => 1,
        'status' => 'active', 'source' => 'officer', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function cleanupRetentionBuilding(Connection $owner, int $buildingId): void
{
    DbRoles::wipeChain($buildingId);
    foreach (['parking_officer_tasks', 'permits', 'vehicles', 'apartment_memberships', 'apartments', 'sections'] as $table) {
        $owner->table($table)->where('building_id', $buildingId)->delete();
    }
    $owner->table('buildings')->where('id', $buildingId)->delete();
}
