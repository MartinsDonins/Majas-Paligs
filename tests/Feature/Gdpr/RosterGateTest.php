<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Gdpr\GuardedByRosterGate;
use App\Gdpr\RosterGate;
use App\Gdpr\RosterGateClosed;
use App\Gdpr\RosterGateMonitor;
use App\Models\AuditEntry;
use App\Models\Building;
use App\Models\BuildingPrivacyNotice;
use App\Notifications\RosterGateChanged;
use App\Tenancy\Exceptions\MissingBuildingContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

/*
 * Gate L-R (ROUND2-SPEC D2, §3.1, GOV-012): L-core + dpa_scope ∋ roster + current notice covering roster. The status,
 * the model-guard trait and the monitor (audit + alert on open / close). The roster resource itself is ADMIN-007.
 */

function rosterHouse(string $slug, bool $scope = true, bool $notice = true): Building
{
    $b = makeBuilding($slug, 1, 1, open: true);
    if ($scope) {
        $b->forceFill(['settings' => $b->settings->with(['dpa_scope' => ['core', 'heating', 'outages', 'roster']])])->save();
    }
    if ($notice) {
        inBuilding($b, fn () => BuildingPrivacyNotice::query()->update(['covers_modules' => '{core,heating,outages,roster}']));
    }

    return $b->refresh();
}

it('is open only with L-core, the roster DPA scope and a notice covering the roster', function (): void {
    expect(inBuilding($b = rosterHouse('roster-open'), fn () => RosterGate::status($b)->open))->toBeTrue();

    $noScope = rosterHouse('roster-no-scope', scope: false);
    expect(inBuilding($noScope, fn () => RosterGate::status($noScope)->missing))->toBe(['dpa_scope_roster']);

    $noNotice = rosterHouse('roster-no-notice', notice: false);
    expect(inBuilding($noNotice, fn () => RosterGate::status($noNotice)->missing))->toBe(['privacy_notice_roster']);

    $closed = makeBuilding('roster-core-closed', 1, 1);
    expect(inBuilding($closed, fn () => RosterGate::status($closed)->missing))->toBe(['core_gate', 'dpa_scope_roster', 'privacy_notice_roster']);

    expect(fn () => RosterGate::status($closed))->toThrow(MissingBuildingContext::class);
});

it('guards roster models on save and delete while the gate is closed', function (): void {
    $model = new class extends Model
    {
        use GuardedByRosterGate;

        protected $table = 'sections';
    };

    $b = rosterHouse('roster-guard', scope: false);
    expect(fn () => inBuilding($b, fn () => $model->newInstance()->save()))->toThrow(RosterGateClosed::class);
});

it('audits and alerts a regression and the re-opening', function (): void {
    Notification::fake();
    $b = rosterHouse('roster-monitor');
    $admin = makeUser('roster-admin@example.test');
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    inBuilding($b, function () use ($b): void {
        $before = RosterGate::status($b);
        BuildingPrivacyNotice::query()->update(['covers_modules' => '{core,heating,outages}']);
        RosterGateMonitor::afterChange($b, $before);

        $before = RosterGate::status($b);
        BuildingPrivacyNotice::query()->update(['covers_modules' => '{core,heating,outages,roster}']);
        RosterGateMonitor::afterChange($b, $before);
    });

    expect(AuditEntry::query()->where('building_id', $b->id)->whereIn('action', ['gate.roster.closed', 'gate.roster.opened'])->orderBy('id')->pluck('action')->all())
        ->toBe(['gate.roster.closed', 'gate.roster.opened']);
    Notification::assertSentTo($admin, RosterGateChanged::class, fn (RosterGateChanged $n): bool => ! $n->open && $n->missing === ['privacy_notice_roster']);
    Notification::assertSentTo($admin, RosterGateChanged::class, fn (RosterGateChanged $n): bool => $n->open);
});
