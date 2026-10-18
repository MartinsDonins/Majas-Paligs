<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\ManagerReportKind;
use App\Models\ManagerReport;
use App\Models\Section;
use App\Models\SurveyRound;
use App\Services\Heating\ManagerReportGenerator;
use App\Services\Heating\SectionScope;
use App\Services\Heating\SurveyRoundService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * MVP-SPEC B.1 "section_ids integrity" + AC 11: foreign, unknown, duplicate and empty section ids are rejected by
 * the DTO AND by the database trigger; an elder cannot widen a scope; a referenced section cannot be deleted.
 */
beforeEach(function (): void {
    $this->a = heatingHouse('ids-a', 5, 2);
    $this->b = heatingHouse('ids-b', 1, 2);
    $this->foreign = section($this->b, 1)->id;
    $this->s1 = section($this->a, 1)->id;
    $this->s5 = section($this->a, 5)->id;
});

it('rejects bad section_ids in the DTO', function (array $ids): void {
    expect(fn () => inBuilding($this->a, fn () => SectionScope::fromIds(array_map(fn ($x) => $x === 'foreign' ? $this->foreign : ($x === 's1' ? $this->s1 : $x), $ids))))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'section of building B' => [['foreign']],
    'unknown id' => [[999999]],
    'duplicates' => [['s1', 's1']],
    'empty array' => [[]],
    'not an integer' => [['abc']],
]);

it('accepts a valid scope, sorted, and null only where allowed', function (): void {
    $scope = inBuilding($this->a, fn () => SectionScope::fromIds([$this->s5, $this->s1]));

    expect($scope->ids)->toBe([$this->s1, $this->s5])
        ->and(fn () => inBuilding($this->a, fn () => SectionScope::fromIds(null)))->toThrow(InvalidArgumentException::class)
        ->and(inBuilding($this->a, fn () => SectionScope::fromIds(null, allowWholeBuilding: true))->isWholeBuilding())->toBeTrue();
});

it('rejects bad section_ids in the database trigger on a raw insert', function (string $table, string $literal): void {
    $literal = strtr($literal, ['F' => (string) $this->foreign, 'S' => (string) $this->s1]);

    $insert = fn () => $table === 'survey_rounds'
        ? DB::insert("INSERT INTO survey_rounds (building_id, title, starts_at, ends_at, status, section_ids, created_at, updated_at) VALUES (?, '{\"lv\":\"x\"}', now(), now() + interval '1 day', 'draft', ?::bigint[], now(), now())", [$this->a->id, $literal])
        : DB::insert("INSERT INTO manager_reports (id, building_id, number, kind, section_ids, generated_at, created_at, updated_at) VALUES (?, ?, 'MP-2026-9999', 'heating', ?::bigint[], now(), now(), now())", [strtolower((string) Str::ulid()), $this->a->id, $literal]);

    expect($insert)->toThrow(QueryException::class);
})->with([
    'round · foreign' => ['survey_rounds', '{F}'],
    'round · unknown' => ['survey_rounds', '{999999}'],
    'round · duplicate' => ['survey_rounds', '{S,S}'],
    'round · empty' => ['survey_rounds', '{}'],
    'report · foreign' => ['manager_reports', '{F}'],
    'report · unknown' => ['manager_reports', '{999999}'],
    'report · duplicate' => ['manager_reports', '{S,S}'],
    'report · empty' => ['manager_reports', '{}'],
]);

it('accepts a valid raw insert (positive control for the trigger cases)', function (): void {
    DB::insert("INSERT INTO survey_rounds (building_id, title, starts_at, ends_at, status, section_ids, created_at, updated_at) VALUES (?, '{\"lv\":\"x\"}', now(), now() + interval '1 day', 'draft', ?::bigint[], now(), now())", [$this->a->id, '{'.$this->s1.','.$this->s5.'}']);
    DB::insert("INSERT INTO survey_rounds (building_id, title, starts_at, ends_at, status, section_ids, created_at, updated_at) VALUES (?, '{\"lv\":\"x\"}', now(), now() + interval '1 day', 'draft', NULL, now(), now())", [$this->a->id]);
    DB::insert("INSERT INTO manager_reports (id, building_id, number, kind, section_ids, generated_at, created_at, updated_at) VALUES (?, ?, 'MP-2026-9997', 'heating', ?::bigint[], now(), now(), now())", [strtolower((string) Str::ulid()), $this->a->id, '{'.$this->s1.'}']);

    expect(DB::table('survey_rounds')->where('building_id', $this->a->id)->count())->toBe(2)
        ->and(DB::table('manager_reports')->where('building_id', $this->a->id)->count())->toBe(1);
});

it('requires manager_reports.section_ids (NOT NULL)', function (): void {
    expect(fn () => DB::insert("INSERT INTO manager_reports (id, building_id, number, kind, section_ids, generated_at, created_at, updated_at) VALUES (?, ?, 'MP-2026-9998', 'heating', NULL, now(), now(), now())", [strtolower((string) Str::ulid()), $this->a->id]))
        ->toThrow(QueryException::class);
});

it('stops an elder of stairwell 5 from widening a scope to [1, 5] (403)', function (): void {
    $elder = makeUser();
    grantRole($elder, $this->a, BuildingRoleType::SectionElder, 5);

    expect(fn () => inBuilding($this->a, fn () => app(ManagerReportGenerator::class)->generate($elder, ManagerReportKind::Heating, SectionScope::fromIds([$this->s1, $this->s5]))))
        ->toThrow(AuthorizationException::class);

    expect(fn () => inBuilding($this->a, fn () => app(SurveyRoundService::class)->save($elder, [
        'title_lv' => 'Kārta', 'title_ru' => 'Обход', 'section_ids' => [$this->s1, $this->s5], 'starts_at' => '2026-11-01 08:00', 'ends_at' => '2026-11-08 20:00',
    ])))->toThrow(AuthorizationException::class);

    expect(fn () => inBuilding($this->a, fn () => app(SurveyRoundService::class)->save($elder, [
        'title_lv' => 'Kārta', 'title_ru' => 'Обход', 'section_ids' => null, 'starts_at' => '2026-11-01 08:00', 'ends_at' => '2026-11-08 20:00',
    ])))->toThrow(AuthorizationException::class);

    // Own stairwell works.
    $report = inBuilding($this->a, fn () => app(ManagerReportGenerator::class)->generate($elder, ManagerReportKind::Heating, SectionScope::fromIds([$this->s5])));
    expect($report->section_ids)->toBe([$this->s5]);
    expect(inBuilding($this->a, fn () => SurveyRound::query()->count()))->toBe(0);
});

it('turns a forged foreign section into a validation error, never a cross-building scope', function (): void {
    $admin = makeUser();
    grantRole($admin, $this->a, BuildingRoleType::BuildingAdmin);

    expect(fn () => inBuilding($this->a, fn () => app(SurveyRoundService::class)->save($admin, [
        'title_lv' => 'Kārta', 'title_ru' => 'Обход', 'section_ids' => [$this->foreign], 'starts_at' => '2026-11-01 08:00', 'ends_at' => '2026-11-08 20:00',
    ])))->toThrow(ValidationException::class);
});

it('refuses deleting a section referenced by a survey round or a manager report', function (): void {
    $admin = makeUser();
    grantRole($admin, $this->a, BuildingRoleType::BuildingAdmin);
    inBuilding($this->a, fn () => app(ManagerReportGenerator::class)->generate($admin, ManagerReportKind::Heating, SectionScope::fromIds([$this->s5])));

    expect(fn () => DB::transaction(fn () => DB::delete('DELETE FROM sections WHERE id = ?', [$this->s5])))->toThrow(QueryException::class)
        ->and(inBuilding($this->a, fn () => Section::query()->whereKey($this->s5)->exists()))->toBeTrue();
});

it('freezes number, scope, sharing and snapshot of a generated report', function (): void {
    $admin = makeUser();
    grantRole($admin, $this->a, BuildingRoleType::BuildingAdmin);
    $report = inBuilding($this->a, fn () => app(ManagerReportGenerator::class)->generate($admin, ManagerReportKind::Heating, SectionScope::fromIds([$this->s1])));

    expect(fn () => DB::transaction(fn () => DB::update('UPDATE manager_reports SET section_ids = ?::bigint[] WHERE id = ?', ['{'.$this->s1.','.$this->s5.'}', $report->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::update("UPDATE manager_reports SET snapshot = '{}'::jsonb WHERE id = ?", [$report->id])))->toThrow(QueryException::class);

    // The sent / response log may change.
    inBuilding($this->a, fn () => ManagerReport::query()->whereKey($report->id)->update(['sent_at' => now(), 'manager_ref' => 'RNP-1']));
    expect(inBuilding($this->a, fn () => ManagerReport::query()->findOrFail($report->id)->manager_ref))->toBe('RNP-1');
});
