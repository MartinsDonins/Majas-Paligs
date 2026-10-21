<?php

declare(strict_types=1);

/*
 * Old panel URLs answer 301 to the same page under /admin (ROUND2-SPEC §1.1, D3, AC-R2-02).
 */

it('redirects every old panel GET URL to the same page under /admin with the query kept', function (string $old, string $new): void {
    $this->get($old)->assertStatus(301)->assertHeader('Location', url($new));
})->with([
    ['/manage', '/admin'],
    ['/manage/', '/admin'],
    ['/manage/puces-51', '/admin/b/puces-51'],
    ['/manage/puces-51/memberships', '/admin/b/puces-51/memberships'],
    ['/manage/puces-51/apartments/12/edit', '/admin/b/puces-51/apartments/12/edit'],
    ['/manage/puces-51/settings', '/admin/b/puces-51/settings'],
    ['/manage/puces-51/privacy-notice', '/admin/b/puces-51/privacy-notice'],
    ['/manage/puces-51/permit-import', '/admin/b/puces-51/permit-import'],
    ['/manage/puces-51/memberships?tableFilters[status][value]=pending', '/admin/b/puces-51/memberships?tableFilters%5Bstatus%5D%5Bvalue%5D=pending'],
    ['/platform', '/admin/platform'],
    ['/platform/buildings', '/admin/platform/buildings'],
    ['/platform/buildings/puces-51/edit', '/admin/platform/buildings/puces-51/edit'],
    ['/platform/generator?building=3', '/admin/platform/generator?building=3'],
    ['/platform/audit', '/admin/platform/audit'],
]);

it('answers 308 without the query for other methods', function (): void {
    $this->post('/manage/puces-51/settings?x=1')->assertStatus(308)->assertHeader('Location', url('/admin/b/puces-51/settings'));
    $this->call('PUT', '/platform/buildings')->assertStatus(308)->assertHeader('Location', url('/admin/platform/buildings'));
});

it('never echoes control characters or backslashes into Location', function (): void {
    $this->get('/manage/x%0d%0aSet-Cookie:a=b')->assertNotFound();
    $this->get('/platform/%5C%5Cevil.example')->assertNotFound();
});

it('lands on the real panel page after the redirect', function (): void {
    $platform = makeUser(attributes: ['platform_role' => 'platform_admin']);
    steppedUp($platform, 'totp');

    $this->actingAs($platform)->get('/platform/buildings')->assertRedirect('/admin/platform/buildings');
    $this->actingAs($platform)->get('/admin/platform/buildings')->assertOk();
});
