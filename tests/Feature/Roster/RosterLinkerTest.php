<?php

declare(strict_types=1);

use App\Auth\LoginCompleter;
use App\Auth\StepUpService;
use App\Enums\MembershipStatus;
use App\Models\AuditEntry;
use App\Services\Memberships\MembershipService;
use App\Services\Roster\ContactData;
use App\Services\Roster\RosterService;
use Illuminate\Http\Request;

/*
 * Feature/Roster/RosterLinkerTest (ROUND2-SPEC §5.5 "Linking"): a contact is linked to an account only when the
 * account's VERIFIED e-mail equals the contact's e-mail AND the account has an APPROVED membership of THAT apartment —
 * never across apartments. Hooks: contact saved, membership approved, e-mail verified. Audited `roster.link`.
 */
beforeEach(function (): void {
    $this->b = rosterHouse('link-house');
    $this->admin = rosterAdmin($this->b, 'link-admin@example.test');
    $this->actingAs($this->admin);
});

it('links on save when an approved member of that apartment has the same verified e-mail', function (): void {
    $member = makeUser('Same.Person@example.test');
    member($member, $this->b, 3);

    $contact = rosterContact($this->b, $this->admin, 3, 'Paraugs', email: 'same.person@EXAMPLE.test');
    expect($contact->refresh()->user_id)->toBe($member->id);

    $entry = inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.link')->sole());
    expect($entry->subject_user_ids)->toBe([$member->id]);
});

it('never links across apartments, to pending members or to unverified e-mails', function (): void {
    $elsewhere = makeUser('elsewhere@example.test');
    member($elsewhere, $this->b, 4);
    $pending = makeUser('pending@example.test');
    member($pending, $this->b, 3, MembershipStatus::Pending);
    $unverified = makeUser('unverified@example.test');
    $unverified->forceFill(['email_verified_at' => null])->save();
    member($unverified, $this->b, 3);

    foreach (['elsewhere@example.test', 'pending@example.test', 'unverified@example.test'] as $i => $email) {
        $contact = rosterContact($this->b, $this->admin, 3, 'Paraugs '.$i, email: $email);
        expect($contact->refresh()->user_id)->toBeNull($email);
    }
});

it('links when the membership is approved later', function (): void {
    $resident = makeUser('later@example.test');
    $membership = member($resident, $this->b, 6, MembershipStatus::Pending);
    $contact = rosterContact($this->b, $this->admin, 6, 'Vēlāk', email: 'later@example.test');
    expect($contact->refresh()->user_id)->toBeNull();

    steppedUp($this->admin, 'totp');
    app(StepUpService::class)->markFresh($this->admin, 'totp');
    inBuilding($this->b, fn () => app(MembershipService::class)->approve($this->admin, $membership));

    expect($contact->refresh()->user_id)->toBe($resident->id);
});

it('links when the account e-mail is verified at the first e-mail login', function (): void {
    $resident = makeUser('first-login@example.test');
    member($resident, $this->b, 7);
    $resident->forceFill(['email_verified_at' => null])->save();
    $contact = rosterContact($this->b, $this->admin, 7, 'Pirmā ieeja', email: 'first-login@example.test');
    expect($contact->refresh()->user_id)->toBeNull();

    $request = Request::create('/login/confirm', 'POST');
    $request->setLaravelSession(app('session.store'));
    app(LoginCompleter::class)->login($request, $resident->refresh(), false, 'email_link');

    expect($contact->refresh()->user_id)->toBe($resident->id);
});

it('unlinks when the contact e-mail changes', function (): void {
    $member = makeUser('changes@example.test');
    member($member, $this->b, 8);
    $contact = rosterContact($this->b, $this->admin, 8, 'Mainās', email: 'changes@example.test');
    expect($contact->refresh()->user_id)->toBe($member->id);

    inBuilding($this->b, fn () => app(RosterService::class)->update($this->admin, $contact, new ContactData(name: 'Mainās', email: 'other@example.test')));
    expect($contact->refresh()->user_id)->toBeNull();
});
