<?php

declare(strict_types=1);

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;
use App\Gdpr\GdprExporter;
use App\Models\Building;
use App\Models\DataExport;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\UserScopedQuery;

/*
 * U1, U2 in buildings A + B; U2 also lives in U1's apartment. Every method returns only the bound user's rows.
 */
beforeEach(function (): void {
    $this->a = makeBuilding('usq-a', 1, 2);
    $this->b = makeBuilding('usq-b', 1, 2);
    $this->u1 = makeUser('u1@example.test');
    $this->u2 = makeUser('u2@example.test');

    member($this->u1, $this->a, 1);
    member($this->u2, $this->a, 1, MembershipStatus::Pending);
    member($this->u2, $this->b, 3);
    grantRole($this->u2, $this->b, BuildingRoleType::BuildingAdmin);
});

it('returns only the bound user memberships and buildings', function (): void {
    $m1 = UserScopedQuery::for($this->u1)->memberships();
    $m2 = UserScopedQuery::for($this->u2)->memberships();

    expect($m1)->toHaveCount(1)
        ->and($m1->first()->buildingSlug)->toBe('usq-a')
        ->and($m1->first()->apartmentNumber)->toBe(1)
        ->and($m2)->toHaveCount(2)
        ->and(UserScopedQuery::for($this->u1)->buildingIds())->toBe([$this->a->id])
        ->and(UserScopedQuery::for($this->u2)->buildingIds())->toBe([$this->a->id, $this->b->id]);
});

it('returns only the bound user roles and manage buildings', function (): void {
    expect(UserScopedQuery::for($this->u1)->roles())->toBeEmpty()
        ->and(UserScopedQuery::for($this->u1)->manageBuildingIds())->toBe([])
        ->and(UserScopedQuery::for($this->u2)->manageBuildingIds())->toBe([$this->b->id]);
});

it('never returns audit entries where only another user is the subject', function (): void {
    inBuilding($this->a, fn () => AuditLogger::record('membership.approve', ['membership', 1], [$this->u1->id]));
    inBuilding($this->a, fn () => AuditLogger::record('membership.approve', ['membership', 2], [$this->u2->id]));
    inBuilding($this->b, fn () => AuditLogger::record('pin.reset', ['user', 2], [$this->u2->id]));

    $mine = UserScopedQuery::for($this->u1)->auditAboutMe();
    $theirs = UserScopedQuery::for($this->u2)->auditAboutMe();

    expect($mine)->toHaveCount(1)
        ->and($mine->first()->buildingId)->toBe($this->a->id)
        ->and($theirs)->toHaveCount(2);
});

it('visits only the user buildings in eachBuilding and restores the context', function (): void {
    $visited = [];
    UserScopedQuery::for($this->u1)->eachBuilding(function (Building $b) use (&$visited): void {
        $visited[] = CurrentBuilding::id();
    });

    expect($visited)->toBe([$this->a->id])->and(CurrentBuilding::has())->toBeFalse();
});

it('builds GDPR exports only from the export row owner (payload cannot be re-pointed)', function (): void {
    $export = DataExport::query()->create(['user_id' => $this->u1->id, 'status' => 'queued']);
    (new GdprExporter((string) $export->id))->handle();

    $export->refresh();
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $json = json_decode((string) $zip->getFromName('my-data.json'), true);
    $zip->close();

    expect($json['account']['email'])->toBe('u1@example.test')
        ->and(json_encode($json))->not->toContain('u2@example.test')
        ->and($json['buildings'])->toHaveCount(1);
});
