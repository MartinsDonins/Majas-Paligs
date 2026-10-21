<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\PrivacyNoticeKind;
use App\Filament\Manage\Pages\RosterImportPage;
use App\Filament\Manage\Resources\ResidentContactResource\Pages\ListResidentContacts;
use App\Gdpr\RosterGate;
use App\Gdpr\RosterGateClosed;
use App\Gdpr\RosterGateMonitor;
use App\Models\AuditEntry;
use App\Models\BuildingPrivacyNotice;
use App\Models\ImportRun;
use App\Models\PrivacyNoticeVersion;
use App\Models\ResidentContact;
use App\Notifications\RosterGateChanged;
use App\Services\Import\ImportRefused;
use App\Services\Import\ImportRunService;
use App\Services\Roster\ContactData;
use App\Services\Roster\RosterService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * GOV-012 / AC-R2-10 (Feature/Gdpr/RosterGateTest): gate L-R = L-core + dpa_scope ∋ roster + current notice covering
 * roster. Closed → the roster page shows the closed card, the upload is refused server-side (even a forged Livewire
 * call), the model guard throws on save; opening enables it; closing again hides the data and alerts.
 */
it('is a pure function of settings + notice, with the three condition keys', function (): void {
    $b = makeBuilding('lr-pure', 1, 2);
    expect(inBuilding($b, fn ($x) => RosterGate::status($x)->missing))->toBe(['core_gate', 'dpa_scope_roster', 'privacy_notice_roster']);

    openGate($b);
    expect(inBuilding($b->refresh(), fn ($x) => RosterGate::status($x)->missing))->toBe(['dpa_scope_roster', 'privacy_notice_roster']);

    $b->forceFill(['settings' => $b->settings->with(['dpa_scope' => ['core', 'heating', 'outages', 'roster']])])->save();
    expect(inBuilding($b->refresh(), fn ($x) => RosterGate::status($x)->missing))->toBe(['privacy_notice_roster']);

    openRosterGate($b);
    expect(inBuilding($b->refresh(), fn ($x) => RosterGate::status($x)->open))->toBeTrue()
        ->and(inBuilding($b, fn ($x) => RosterGate::status($x)->missingLabels()))->toBe([]);
});

it('refuses roster writes from any path while closed (model guard), allows them when open', function (): void {
    $b = rosterHouse('lr-guard', roster: false);
    $admin = rosterAdmin($b);

    expect(fn () => inBuilding($b, fn () => ResidentContact::query()->create([
        'apartment_id' => apartment($b, 1)->id, 'name_ciphertext' => random_bytes(40),
    ])))->toThrow(RosterGateClosed::class);

    $this->actingAs($admin);
    expect(fn () => inBuilding($b, fn () => app(RosterService::class)->create($admin, apartment($b, 1), new ContactData(name: 'Paraugs'))))
        ->toThrow(AuthorizationException::class);

    openRosterGate($b);
    $contact = inBuilding($b->refresh(), fn () => app(RosterService::class)->create($admin, apartment($b, 1), new ContactData(name: 'Paraugs')));
    expect($contact->exists)->toBeTrue();
});

it('shows the closed card instead of the list and no uploader below the gate', function (): void {
    $b = rosterHouse('lr-card', roster: false);
    $admin = rosterAdmin($b, 'lr-card-admin@example.test');
    steppedUp($admin);

    $this->actingAs($admin)->get('/admin/b/lr-card/roster')->assertOk()
        ->assertSee(__('roster.gate.closed_title'))
        ->assertSee(__('roster.gate.missing.dpa_scope_roster'))
        ->assertSee('data-testid="roster-closed"', false)
        ->assertDontSee(__('roster.list.empty'))
        ->assertDontSee(__('roster.list.add'));

    $this->actingAs($admin)->get('/admin/b/lr-card/roster/import')->assertOk()
        ->assertSee(__('import.gate.title'))
        ->assertSee(__('roster.gate.missing.privacy_notice_roster'))
        ->assertDontSee('type="file"', false);
});

it('refuses the upload server-side below the gate, also a forged Livewire call and the signed-URL request', function (): void {
    $b = rosterHouse('lr-forge', roster: false);
    $admin = rosterAdmin($b);
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($b);
    app(StepUpService::class)->markFresh($admin, 'totp');

    expect(fn () => uploadCsv($b, $admin, rosterCsv([['1', 'Paraugs', '', '', '', '', '', '']])))->toThrow(ImportRefused::class);

    inBuilding($b, fn () => Livewire::actingAs($admin)->test(RosterImportPage::class)
        ->set('paste', rosterCsv([['1', 'Paraugs', '', '', '', '', '', '']]))
        ->call('uploadPaste')
        ->assertSet('paste', '')
        ->assertNotified(__('import.error.gate_closed')));

    inBuilding($b, fn () => Livewire::actingAs($admin)->test(RosterImportPage::class)
        ->call('_startUpload', 'upload', [['name' => 'saraksts.csv', 'size' => 100, 'type' => 'text/csv']], false)
        ->assertStatus(403));

    expect(inBuilding($b, fn () => ImportRun::query()->count()))->toBe(0);
});

it('audits opening and closing, alerts the admins on regression and hides the data again', function (): void {
    Notification::fake();
    $b = rosterHouse('lr-monitor', roster: false);
    $admin = rosterAdmin($b, 'lr-mon-admin@example.test');

    $before = inBuilding($b, fn ($x) => RosterGate::status($x));
    openRosterGate($b);
    inBuilding($b, fn ($x) => RosterGateMonitor::afterChange($x, $before));
    expect(inBuilding($b, fn () => AuditEntry::query()->where('action', 'gate.roster.opened')->count()))->toBe(1);

    $this->actingAs($admin);
    rosterContact($b, $admin, 3, 'Paraugs Viens');
    steppedUp($admin);
    $this->actingAs($admin)->get('/admin/b/lr-monitor/roster')->assertOk()->assertSee('Paraugs Viens');

    // The notice is withdrawn → regression.
    $open = inBuilding($b, fn ($x) => RosterGate::status($x));
    inBuilding($b, fn () => BuildingPrivacyNotice::query()->effective()->update(['withdrawn_at' => now()]));
    $template = PrivacyNoticeVersion::query()->where('kind', PrivacyNoticeKind::BuildingTemplate->value)->firstOrFail();
    inBuilding($b, fn () => BuildingPrivacyNotice::query()->create([
        'template_version_id' => $template->id, 'controller_snapshot' => ['name' => 'T', 'contact' => 'dati@example.test'],
        'covers_modules' => ['core', 'heating', 'outages'], 'published_at' => now(),
    ]));
    inBuilding($b, fn ($x) => RosterGateMonitor::afterChange($x, $open));

    expect(inBuilding($b, fn () => AuditEntry::query()->where('action', 'gate.roster.closed')->first()?->data))->toBe(['missing' => ['privacy_notice_roster']]);
    Notification::assertSentTo($admin, RosterGateChanged::class);

    $this->actingAs($admin)->get('/admin/b/lr-monitor/roster')->assertOk()
        ->assertSee(__('roster.gate.closed_title'))
        ->assertDontSee('Paraugs Viens');
    // The row is kept (unreadable in the UI until the gate is open again).
    expect(inBuilding($b, fn () => ResidentContact::query()->count()))->toBe(1);
});

it('enforces the gate in the policy too (before())', function (): void {
    $b = rosterHouse('lr-policy');
    $admin = rosterAdmin($b);
    $this->actingAs($admin);
    $contact = rosterContact($b, $admin, 1);

    expect(inBuilding($b, fn () => $admin->can('view', $contact)))->toBeTrue();

    $b->forceFill(['settings' => $b->settings->with(['dpa_scope' => ['core', 'heating', 'outages']])])->save();
    $b->refresh();
    expect(inBuilding($b, fn () => $admin->can('view', $contact)))->toBeFalse()
        ->and(inBuilding($b, fn () => $admin->can('openPage', ResidentContact::class)))->toBeTrue();

    expect(fn () => inBuilding($b, fn () => app(ImportRunService::class)->assertAllowed($admin, 'roster')))->toThrow(ImportRefused::class);
    expect(inBuilding($b, fn () => ListResidentContacts::class))->toBeString();
});
