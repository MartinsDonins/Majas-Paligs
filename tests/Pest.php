<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PrivacyNoticeKind;
use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\ApartmentInvite;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Models\BuildingRole;
use App\Models\BuildingSeries;
use App\Models\PrivacyNoticeVersion;
use App\Models\Section;
use App\Models\User;
use App\Services\Buildings\BuildingGenerator;
use App\Services\Buildings\GeneratorInput;
use App\Services\Buildings\SeriesTemplateData;
use App\Services\Invites\InviteCodeService;
use App\Tenancy\CurrentBuilding;
use Database\Seeders\PrivacyNoticeSeeder;
use Database\Seeders\Series602Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
function steppedUp(User $user, string $method = 'email'): void
{
    test()->withSession([StepUpService::SESSION_KEY => ['user' => $user->id, 'at' => now()->getTimestamp(), 'method' => $method]]);
}
