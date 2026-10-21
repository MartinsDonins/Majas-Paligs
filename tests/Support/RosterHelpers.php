<?php

declare(strict_types=1);

/*
 * Round 2 test helpers — resident roster (ADMIN-007) and the CSV import pipeline (DB-003). Synthetic data only:
 * phone numbers are generated at runtime (never a phone-shaped literal in git — scripts/personal-data-guard.sh),
 * e-mails are @example.test.
 */

use App\Enums\BuildingRoleType;
use App\Enums\ContactRelation;
use App\Enums\PrivacyNoticeKind;
use App\Gdpr\RosterGate;
use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Models\ImportRun;
use App\Models\PrivacyNoticeVersion;
use App\Models\ResidentContact;
use App\Models\User;
use App\Services\Import\ImportRunService;
use App\Services\Roster\ContactData;
use App\Services\Roster\RosterService;

/**
 * A synthetic Latvian mobile number in E.164: "+371" + "2" + seven digits derived from $n.
 */
function syntheticPhone(int $n): string
{
    return '+371'.'2'.str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT);
}

/**
 * The same number as people type it in a sheet: "2" + digits with spaces ("2 1000 001").
 */
function syntheticPhoneTyped(int $n): string
{
    $digits = substr(syntheticPhone($n), 4);

    return substr($digits, 0, 2).' '.substr($digits, 2, 3).' '.substr($digits, 5);
}

/**
 * Open gate L-R on a building: L-core + dpa_scope ∋ roster + a current notice covering roster.
 */
function openRosterGate(Building $building): void
{
    $building->forceFill(['settings' => $building->settings->with([
        'dpa_scope' => array_values(array_unique([...$building->settings->dpaScope, 'core', 'heating', 'outages', 'roster'])),
    ])])->save();

    $template = PrivacyNoticeVersion::query()->where('kind', PrivacyNoticeKind::BuildingTemplate->value)->orderByDesc('id')->firstOrFail();
    inBuilding($building, function () use ($template): void {
        BuildingPrivacyNotice::query()->effective()->update(['withdrawn_at' => now()]);
        BuildingPrivacyNotice::query()->create([
            'template_version_id' => $template->id,
            'controller_snapshot' => ['name' => 'Testa biedrība', 'contact' => 'dati@example.test'],
            'covers_modules' => ['core', 'heating', 'outages', 'roster'],
            'published_at' => now()->subMinute(),
        ]);
    });
}

/**
 * A generated building (2 stairwells × 3 floors × 4 = apartments 1..24) with gate L-core open and — unless
 * $roster is false — gate L-R open too.
 */
function rosterHouse(string $slug = 'roster-house', bool $roster = true): Building
{
    $b = makeBuilding($slug, 2, 3, open: true);

    if ($roster) {
        openRosterGate($b);
    }

    expect(inBuilding($b->refresh(), fn (Building $x) => RosterGate::status($x)->open))->toBe($roster);

    return $b->refresh();
}

function rosterAdmin(Building $b, ?string $email = null): User
{
    $admin = makeUser($email);
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    return $admin;
}

function rosterElder(Building $b, int $section, ?string $email = null): User
{
    $elder = makeUser($email);
    grantRole($elder, $b, BuildingRoleType::SectionElder, $section);

    return $elder;
}

/**
 * A roster contact created through RosterService (sealed, audited) by $actor.
 *
 * @param  list<string>  $phones
 */
function rosterContact(Building $b, User $actor, int $apartment, ?string $name = 'Anna Paraudziņa', array $phones = [], ?string $email = null, ContactRelation $relation = ContactRelation::Owner): ResidentContact
{
    return inBuilding($b, fn () => app(RosterService::class)->create($actor, apartment($b, $apartment), new ContactData(
        relation: $relation, name: $name, phones: $phones, email: $email, bisRegistered: true, whatsappMember: null,
    )));
}

/**
 * Upload a CSV for $kind as $user (in the building's context) and return the run.
 */
function uploadCsv(Building $b, User $user, string $csv, string $kind = 'roster'): ImportRun
{
    return inBuilding($b, fn () => app(ImportRunService::class)->upload($user, $kind, $csv));
}

/**
 * A synthetic roster sheet as Google Sheets exports it (`,`, UTF-8, no BOM).
 *
 * @param  list<list<string>>  $rows
 */
function rosterCsv(array $rows, string $delimiter = ',', bool $bom = false, array $header = ['Dzīvoklis', 'Vārds', 'Telefons', 'E-pasts', 'Statuss', 'BIS', 'WhatsApp', 'Komentārs']): string
{
    $lines = [implode($delimiter, $header)];
    foreach ($rows as $row) {
        $lines[] = implode($delimiter, array_map(static fn (string $c): string => str_contains($c, $delimiter) || str_contains($c, '"') ? '"'.str_replace('"', '""', $c).'"' : $c, $row));
    }

    return ($bom ? "\xEF\xBB\xBF" : '').implode("\r\n", $lines)."\r\n";
}
