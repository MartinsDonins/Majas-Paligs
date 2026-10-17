<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\MembershipStatus;

/*
 * Every resident screen renders for the roles that may see it (LV and RU), with the version footer.
 */
beforeEach(function (): void {
    $this->b = makeBuilding('pages', 1, 2, open: true);
    $this->resident = makeUser('pages-res@example.test');
    member($this->resident, $this->b, 1);
    $this->elder = makeUser('pages-elder@example.test');
    member($this->elder, $this->b, 2);
    grantRole($this->elder, $this->b, BuildingRoleType::SectionElder, 1);
    grantRole($this->elder, $this->b, BuildingRoleType::BuildingAdmin);
    member(makeUser(), $this->b, 3, MembershipStatus::Pending);
});

it('renders home, my apartment and privacy for a resident', function (string $locale): void {
    $this->resident->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->resident);

    $this->get('/b/pages')->assertOk()->assertSee(__('home.my_house.title', [], $locale))->assertSee('data-testid="app-version"', false);
    $this->get('/b/pages/me')->assertOk()->assertSee(__('me.title', [], $locale));
    $this->get('/me/privacy')->assertOk()->assertSee(__('privacy.who_viewed.title', [], $locale));
    $this->get('/home')->assertRedirect('/b/pages');
    $this->get('/privacy/pages')->assertOk()->assertSee('Testa biedrība');
})->with(['lv', 'ru']);

it('shows elder tools and the helper panel after step-up', function (): void {
    steppedUp($this->elder);
    $this->actingAs($this->elder);

    $this->get('/b/pages')->assertOk()->assertSee(__('home.tools.helper'));
    $this->get('/b/pages/help')->assertOk()->assertSee(__('elder.approvals.title'))->assertSee(__('onboarding.role.apartment', ['n' => 3]));
    $this->get('/b/pages/help?tab=pin')->assertOk();
    $this->get('/b/pages/help?tab=assisted')->assertOk();
    $this->get('/me/security')->assertOk();
});

it('forbids the helper panel to plain residents', function (): void {
    steppedUp($this->resident);
    $this->actingAs($this->resident)->get('/b/pages/help')->assertForbidden();
});

it('renders the large-text (senior) home without the extra cards', function (): void {
    $this->resident->forceFill(['text_size' => 'large'])->save();

    $this->actingAs($this->resident)->get('/b/pages')->assertOk()
        ->assertSee('data-density="large"', false)
        ->assertDontSee(__('home.my_house.title'));
});
