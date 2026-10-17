<?php

declare(strict_types=1);

use App\Audit\AuditChainVerifier;
use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\ExportStatus;
use App\Enums\MembershipStatus;
use App\Gdpr\AccountEraser;
use App\Gdpr\BuildingExporter;
use App\Livewire\Me\Privacy;
use App\Livewire\Me\WhoViewed;
use App\Models\ApartmentMembership;
use App\Models\BuildingExport;
use App\Models\DataExport;
use App\Models\User;
use App\Tenancy\UserScopedQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

function zipJson(string $path, string $entry): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($path));
    $data = json_decode((string) $zip->getFromName($entry), true);
    $zip->close();

    return $data;
}

it('exports a multi-building user’s data as a ZIP behind a signed 10-minute link', function (): void {
    $a = makeBuilding('gx-a', 1, 1);
    $b = makeBuilding('gx-b', 1, 1);
    $user = makeUser('multi@example.test');
    member($user, $a, 1);
    member($user, $b, 2);
    $other = makeUser('other@example.test');
    member($other, $a, 1);

    $this->actingAs($user);
    Livewire::test(Privacy::class)->call('requestExport');

    $export = DataExport::query()->where('user_id', $user->id)->firstOrFail();
    expect($export->status)->toBe(ExportStatus::Ready);

    $json = zipJson($export->path, 'my-data.json');
    expect($json['account']['email'])->toBe('multi@example.test')
        ->and($json['buildings'])->toHaveCount(2)
        ->and(json_encode($json))->not->toContain('other@example.test');

    $url = URL::temporarySignedRoute('me.exports.download', now()->addMinutes(10), ['export' => $export->id]);
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();

    $this->actingAs($other)->get(URL::temporarySignedRoute('me.exports.download', now()->addMinutes(10), ['export' => $export->id]))->assertNotFound();
});

it('tombstones the user on deletion, never touching audit rows, and the chain still verifies', function (): void {
    $b = makeBuilding('gx-c', 1, 1);
    $user = makeUser('leaver@example.test');
    $user->forceFill(['login_handle' => '1-LEAV', 'pin_hash' => 'x'])->save();
    member($user, $b, 1);
    $viewer = makeUser('admin-viewer@example.test');
    grantRole($user, $b, BuildingRoleType::Volunteer);
    inBuilding($b, fn () => AuditLogger::record('membership.approve', ['membership', 1], [$user->id], actorUserId: $user->id, actorRole: 'section_elder'));

    $snapshot = fn () => DB::select("SELECT id, encode(hash,'hex') h, actor_user_id, subject_user_ids::text s FROM audit_entries WHERE chain_key = ? ORDER BY id", [$b->id]);
    $before = $snapshot();

    app(AccountEraser::class)->erase($user);
    $after = array_slice($snapshot(), 0, count($before));

    $tomb = User::withTrashed()->findOrFail($user->id);
    expect($tomb->email)->toBeNull()
        ->and($tomb->login_handle)->toBeNull()
        ->and($tomb->pin_hash)->toBeNull()
        ->and($tomb->anonymised_at)->not->toBeNull()
        ->and($tomb->deleted_at)->not->toBeNull()
        ->and(inBuilding($b, fn () => ApartmentMembership::query()->where('user_id', $user->id)->first()->status))->toBe(MembershipStatus::Ended)
        ->and($after)->toEqual($before)
        ->and(app(AuditChainVerifier::class)->verify($b->id)->valid)->toBeTrue();

    // "who viewed my data" of another subject shows "Dzēsts lietotājs" for the erased actor
    inBuilding($b, fn () => AuditLogger::record('pin.reset', ['user', $viewer->id], [$viewer->id], actorUserId: $user->id, actorRole: 'section_elder'));
    $this->actingAs($viewer);
    Livewire::test(WhoViewed::class)->assertSee(__('privacy.who_viewed.deleted_user'));
});

it('logs the user out and makes the account unusable after deletion', function (): void {
    $user = makeUser('bye@example.test');
    $this->actingAs($user);

    Livewire::test(Privacy::class)->call('askDelete')->set('deleteWord', __('privacy.delete.word'))->call('delete')->assertRedirect(route('landing'));

    $this->assertGuest();
    expect(User::query()->where('email', 'bye@example.test')->exists())->toBeFalse();
});

it('lists audit entries where I am the subject, across buildings, by role only', function (): void {
    $a = makeBuilding('gx-d', 1, 1);
    $b = makeBuilding('gx-e', 1, 1);
    $me = makeUser('me@example.test', ['display_name' => 'Secret Name']);
    $elder = makeUser('elder-x@example.test', ['display_name' => 'Elder Name']);
    inBuilding($a, fn () => AuditLogger::record('membership.approve', ['membership', 1], [$me->id], actorUserId: $elder->id, actorRole: 'section_elder'));
    inBuilding($b, fn () => AuditLogger::record('pin.reset', ['user', $me->id], [$me->id], actorUserId: $elder->id, actorRole: 'section_elder'));
    inBuilding($b, fn () => AuditLogger::record('pin.reset', ['user', $elder->id], [$elder->id], actorUserId: $me->id, actorRole: 'section_elder'));

    $this->actingAs($me);
    Livewire::test(WhoViewed::class)
        ->assertSee(__('audit.action.membership.approve'))
        ->assertSee(__('audit.action.pin.reset'))
        ->assertSee(__('audit.actor.section_elder'))
        ->assertDontSee('Elder Name')
        ->assertDontSee('elder-x@example.test');

    expect(UserScopedQuery::for($me)->auditAboutMe())->toHaveCount(2);
});

it('exports the house as structure + aggregates without personal data (AC 13)', function (): void {
    $b = makeBuilding('gx-f', 1, 2, open: true);
    $b->forceFill(['settings' => $b->settings->with(['share_with_manager' => ['heating_floors' => true, 'apartment_numbers' => true]])])->save();
    $resident = makeUser('resident-x@example.test');
    member($resident, $b, 3);
    $admin = makeUser('admin-x@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    $export = inBuilding($b, fn () => BuildingExport::query()->create(['requested_by' => $admin->id, 'status' => ExportStatus::Queued]));
    BuildingExporter::dispatch($b->id, (string) $export->id);

    $path = inBuilding($b, fn () => BuildingExport::query()->firstOrFail()->path);
    $json = zipJson($path, 'building.json');
    $flat = json_encode($json);

    expect($json['apartments'])->toHaveCount(8)
        ->and($json['risers'])->not->toBeEmpty()
        ->and($flat)->not->toContain('resident-x@example.test')
        ->not->toContain('admin-x@example.test')
        ->not->toContain('dati@example.test') // controller contact stripped
        ->not->toContain('"memberships"')
        ->not->toContain('"floors_reported"')
        ->not->toContain('"apartment_numbers"')
        ->not->toContain('"user_id"');

    // defence in depth: the strip() filter removes forbidden keys from any future section
    expect(BuildingExporter::strip(['manager_reports' => [['snapshot' => ['x'], 'floors_reported' => [1], 'apartment_numbers' => [5], 'number' => 'MP-2026-0001']]]))
        ->toBe(['manager_reports' => [['number' => 'MP-2026-0001']]]);
});
