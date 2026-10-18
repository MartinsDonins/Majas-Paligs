<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\IncidentKind;
use App\Enums\ManagerReportKind;
use App\Http\Controllers\ManagerReportDownloadController;
use App\Models\AuditEntry;
use App\Models\ManagerReport;
use App\Services\Heating\HeatingReportService;
use App\Services\Heating\ManagerReportGenerator;
use App\Services\Heating\ManagerReportRenderer;
use App\Services\Heating\ReportSharing;
use App\Services\Heating\SectionScope;
use App\Services\Outages\IncidentService;
use App\Support\BuildingSettings;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * HEAT-005 / MVP-SPEC B.7 AC 8: gap-free stable number, aggregates only, sharing-flag matrix, snapshot = exported
 * fields, elder scope, no notes / identities, formula-safe CSV, signed + audited downloads.
 */
beforeEach(function (): void {
    $this->b = heatingHouse('mgr-house', 2, 9);
    $this->admin = makeUser();
    grantRole($this->admin, $this->b, BuildingRoleType::BuildingAdmin);
    $this->s1 = section($this->b, 1)->id;
    $this->s2 = section($this->b, 2)->id;
});

function mgrReport(object $t, ?array $sections = null, $user = null, ManagerReportKind $kind = ManagerReportKind::Heating): ManagerReport
{
    return inBuilding($t->b, fn () => app(ManagerReportGenerator::class)->generate($user ?? $t->admin, $kind, SectionScope::fromIds($sections ?? [$t->s1])));
}

function mgrShare(object $t, bool $floors, bool $numbers): void
{
    $t->b->forceFill(['settings' => $t->b->settings->with(['share_with_manager' => ['heating_floors' => $floors, 'apartment_numbers' => $numbers]])])->save();
    $t->b->refresh();
}

it('numbers reports MP-YYYY-NNNN without gaps', function (): void {
    $year = now('Europe/Riga')->format('Y');

    expect(mgrReport($this)->number)->toBe("MP-{$year}-0001")
        ->and(mgrReport($this)->number)->toBe("MP-{$year}-0002")
        ->and(mgrReport($this, [$this->s2], kind: ManagerReportKind::Outage)->number)->toBe("MP-{$year}-0003");
});

it('applies the sharing-flag matrix and stores exactly what is exported', function (bool $floors, bool $numbers): void {
    mgrShare($this, $floors, $numbers);
    residentReports($this->b, 1, 'cold');
    residentReports($this->b, 33, 'cold');

    $report = mgrReport($this);
    $snapshot = json_encode($report->snapshot);

    $riser = $report->snapshot['risers'][0];
    expect($report->sharing)->toBe(['heating_floors' => $floors, 'apartment_numbers' => $numbers])
        ->and($report->snapshot['sharing'])->toBe($report->sharing)
        ->and(array_key_exists('floors_reported', $riser))->toBe($floors)
        ->and(array_key_exists('floor_grid', $report->snapshot))->toBe($floors)
        ->and(array_key_exists('apartment_numbers', $riser))->toBe($numbers)
        ->and(str_contains($snapshot, '"apartment":'))->toBe($numbers)
        ->and($report->snapshot['risers'])->not->toBeEmpty()
        ->and($report->snapshot['columns'])->not->toBeEmpty();

    // The CSV renders from the snapshot only.
    $header = collect(app(ManagerReportRenderer::class)->csvRows($report))->first(fn ($row) => ($row[0] ?? null) === __('report.csv.riser'));
    expect(in_array(__('report.csv.floors_reported'), $header, true))->toBe($floors)
        ->and(in_array(__('report.csv.apartment_numbers'), $header, true))->toBe($numbers);
})->with([
    'nothing shared' => [false, false],
    'floors shared' => [true, false],
    'floors + apartment numbers' => [true, true],
]);

it('rejects apartment_numbers without heating_floors in the DTOs', function (): void {
    expect(fn () => new ReportSharing(heatingFloors: false, apartmentNumbers: true))->toThrow(InvalidArgumentException::class)
        ->and(fn () => BuildingSettings::fromArray(['share_with_manager' => ['heating_floors' => false, 'apartment_numbers' => true]]))->toThrow(InvalidArgumentException::class);
});

it('counts "viss stāvads auksts" in the triage block', function (): void {
    foreach ([1, 9, 21, 33] as $n) { // floors 1, 3, 6, 9 of column 1
        residentReports($this->b, $n, 'cold');
    }

    $report = mgrReport($this);

    expect($report->snapshot['triage']['whole_riser_under'])->toBe(3) // room1, room2, kitchen risers of column 1
        ->and(collect($report->snapshot['risers'])->where('whole_riser_under', true)->count())->toBe(3);
});

it('never includes private notes or reporter identities', function (): void {
    $u = makeUser('reporter-secret@example.test');
    member($u, $this->b, 1);
    inBuilding($this->b, fn () => app(HeatingReportService::class)->submit($u, apartment($this->b, 1), ['kitchen' => 'cold'], note: 'SECRET-NOTE-123', triage: 'valve_closed'));

    $report = mgrReport($this);
    $html = view('pdf.manager-report', ['r' => $report->snapshot, 'locale' => 'lv'])->render();
    $csv = json_encode(app(ManagerReportRenderer::class)->csvRows($report));

    foreach ([json_encode($report->snapshot), $html, $csv] as $payload) {
        expect($payload)->not->toContain('SECRET-NOTE-123')->not->toContain('reporter-secret@example.test')
            ->not->toContain('"user_id"')->not->toContain('reported_by');
    }
    expect($report->snapshot['triage']['valve_closed'])->toBe(1);
});

it('lets an elder generate and download only reports of their own stairwell', function (): void {
    $elder = makeUser();
    grantRole($elder, $this->b, BuildingRoleType::SectionElder, 2);

    expect(fn () => mgrReport($this, [$this->s1], $elder))->toThrow(AuthorizationException::class)
        ->and(fn () => mgrReport($this, [$this->s1, $this->s2], $elder))->toThrow(AuthorizationException::class);

    $own = mgrReport($this, [$this->s2], $elder);
    $foreign = mgrReport($this, [$this->s1]);

    steppedUp($elder);
    $this->actingAs($elder)->get(ManagerReportDownloadController::url($this->b, $own, 'csv'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $this->actingAs($elder)->get(ManagerReportDownloadController::url($this->b, $foreign, 'pdf'))->assertForbidden();
});

it('serves PDF and CSV only through signed URLs, audit-logged', function (): void {
    $report = mgrReport($this);
    steppedUp($this->admin);

    $this->actingAs($this->admin)->get("/b/mgr-house/reports/{$report->id}.pdf")->assertForbidden(); // no signature
    $this->actingAs($this->admin)->get(ManagerReportDownloadController::url($this->b, $report, 'pdf'))
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    expect(inBuilding($this->b, fn () => AuditEntry::query()->whereIn('action', ['manager_report.generate', 'manager_report.download'])->count()))->toBe(2);
});

it('keeps another building’s report invisible (404)', function (): void {
    $other = heatingHouse('mgr-other', 1, 2);
    $otherAdmin = makeUser();
    grantRole($otherAdmin, $other, BuildingRoleType::BuildingAdmin);
    $report = inBuilding($other, fn () => app(ManagerReportGenerator::class)->generate($otherAdmin, ManagerReportKind::Heating, SectionScope::fromIds([section($other, 1)->id])));

    steppedUp($this->admin);
    $this->actingAs($this->admin)->get(ManagerReportDownloadController::url($this->b, $report, 'pdf'))->assertNotFound();
});

it('writes formula-injection-safe CSV', function (): void {
    $this->b->forceFill(['name' => '=HYPERLINK("http://example.test")'])->save();
    $report = mgrReport($this);

    $csv = app(ManagerReportRenderer::class)->csv($report)->getContent();
    expect($csv)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK');
});

it('summarises outages with confirmation counts only', function (): void {
    $u = makeUser('outage-secret@example.test');
    member($u, $this->b, 1);
    inBuilding($this->b, fn () => app(IncidentService::class)->report($u, apartment($this->b, 1), IncidentKind::WaterHot));

    $report = mgrReport($this, kind: ManagerReportKind::Outage);
    $json = json_encode($report->snapshot);

    expect($report->snapshot['incidents'])->toHaveCount(1)
        ->and($report->snapshot['incidents'][0]['confirmations'])->toBe(1)
        ->and($json)->not->toContain('outage-secret@example.test')->not->toContain('"apartment":')->not->toContain('"apartment_id"')
        ->and(array_keys($report->snapshot['incidents'][0]))->not->toContain('apartments');

    $html = view('pdf.manager-report', ['r' => $report->snapshot, 'locale' => 'ru'])->render();
    expect($html)->toContain(__('report.title.outage', [], 'ru'));
});

it('renders the PDF in LV and RU with the sharing line and the emergency footer', function (string $locale): void {
    $this->b->forceFill(['manager_emergency_phone' => '+000 0000 0000'])->save();
    residentReports($this->b, 1, 'cold');
    $report = mgrReport($this);

    $html = view('pdf.manager-report', ['r' => $report->snapshot, 'locale' => $locale])->render();
    expect($html)->toContain($report->number)
        ->toContain(__('report.sharing.not_shared', [], $locale))
        ->toContain(__('report.footer.not_emergency', [], $locale))
        ->toContain(__('report.title.heating', [], $locale));
})->with(['lv', 'ru']);
