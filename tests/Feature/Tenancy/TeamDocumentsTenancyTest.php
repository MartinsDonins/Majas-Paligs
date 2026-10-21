<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\DocumentKind;
use App\Enums\Locale;
use App\Models\BuildingDocument;
use App\Models\RoleInvitation;
use App\Services\Documents\DocumentService;
use App\Services\Team\TeamInvitationService;
use App\Tenancy\Exceptions\MissingBuildingContext;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

/*
 * ROUND2-SPEC §9 A01 — the round-2 tenant tables of this builder (role_invitations, building_documents): fail-closed
 * without a context, invisible from another building (scope → not found), and building A's admin pages answer 404 to
 * an admin of building B. (CrossTenantTest's dataset stays the shared one; these rows live here to keep merges small.)
 */

beforeEach(function (): void {
    Notification::fake();
    (new DocumentTemplateSeeder)->run();
    $this->a = makeBuilding('tt-a', 1, 1);
    $this->b = makeBuilding('tt-b', 1, 1);
    $this->adminA = makeUser('tt-admin-a@example.test');
    grantRole($this->adminA, $this->a, BuildingRoleType::BuildingAdmin);
    $this->adminB = makeUser('tt-admin-b@example.test');
    grantRole($this->adminB, $this->b, BuildingRoleType::BuildingAdmin);

    $this->actingAs($this->adminA);
    session()->put(StepUpService::SESSION_KEY, ['user' => $this->adminA->id, 'at' => now()->getTimestamp(), 'seen' => now()->getTimestamp(), 'method' => 'passkey']);
    $this->invitation = inBuilding($this->a, fn () => app(TeamInvitationService::class)->invite($this->adminA, 'tt-new@example.test', BuildingRoleType::Volunteer, [], null, Locale::Lv));
    $this->document = inBuilding($this->a, fn () => app(DocumentService::class)->prepare($this->adminA, DocumentKind::Dpa, ['requested_scope' => ['core', 'heating', 'outages']]));
});

it('fails closed without a building context and hides building A rows from building B', function (): void {
    foreach ([RoleInvitation::class, BuildingDocument::class] as $model) {
        expect(fn () => $model::query()->count())->toThrow(MissingBuildingContext::class);
    }

    expect(inBuilding($this->b, fn () => RoleInvitation::query()->whereKey($this->invitation->id)->exists()))->toBeFalse()
        ->and(inBuilding($this->b, fn () => BuildingDocument::query()->whereKey($this->document->id)->exists()))->toBeFalse();
});

it('answers 404 on building A admin pages to the admin of building B', function (string $page): void {
    steppedUp($this->adminB);
    $this->actingAs($this->adminB)->get("/admin/b/tt-a/{$page}")->assertNotFound();
})->with(['team', 'opening', 'documents', 'parking-status']);

it('never lets building B write a document of building A, even by id', function (): void {
    $this->actingAs($this->adminB);
    app(StepUpService::class)->markFresh($this->adminB, 'totp');

    expect(fn () => inBuilding($this->b, fn () => app(DocumentService::class)->finalize($this->adminB, $this->document)))
        ->toThrow(AuthorizationException::class);
    expect(inBuilding($this->a, fn () => $this->document->refresh()->isDraft()))->toBeTrue();
});
