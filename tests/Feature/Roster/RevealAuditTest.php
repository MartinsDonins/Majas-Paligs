<?php

declare(strict_types=1);

use App\Auth\StepUpService;
use App\Filament\Manage\Resources\ResidentContactResource\Pages\ListResidentContacts;
use App\Livewire\Me\WhoViewed;
use App\Models\ApartmentMembership;
use App\Models\AuditEntry;
use App\Models\ResidentContact;
use App\Services\Roster\ContactVault;
use App\Services\Roster\RosterActionFailed;
use App\Services\Roster\RosterLinker;
use App\Services\Roster\RosterViewer;
use App\Tenancy\UserScopedQuery;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * AC-R2-12 (Feature/Roster/RevealAuditTest): phones and e-mails are stored only as ciphertext + blind index (DB
 * inspection), masked in lists; "Rādīt kontaktus" needs a fresh second factor, is audited with the linked accounts as
 * data subjects (→ "Kas skatīja manus datus", role only) and stops at the daily cap. Viewing the masked list is
 * audited once per 10 minutes per filter set.
 */
beforeEach(function (): void {
    $this->b = rosterHouse('rev-house');
    $this->admin = rosterAdmin($this->b, 'rev-admin@example.test');
    $this->actingAs($this->admin);
    $this->resident = makeUser('rev-resident@example.test');
    member($this->resident, $this->b, 5);
    $this->contact = rosterContact($this->b, $this->admin, 5, 'Rūta Paraudziņa', [syntheticPhone(51), syntheticPhone(52)], 'rev-resident@example.test');
});

it('stores phones, e-mail, name and comment only as ciphertext + blind indexes', function (): void {
    $raw = (array) DB::table('resident_contacts')->where('id', $this->contact->id)->first();
    $flat = implode('|', array_map(static fn ($v): string => is_resource($v) ? (string) stream_get_contents($v) : (string) $v, $raw));

    expect($flat)->not->toContain(substr(syntheticPhone(51), 4))
        ->not->toContain('rev-resident@example.test')
        ->not->toContain('Rūta')
        ->and(array_keys($raw))->not->toContain('phone')->not->toContain('email')->not->toContain('name');

    $columns = collect(DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'resident_contacts'"))
        ->pluck('data_type', 'column_name')->all();
    expect($columns['name_ciphertext'])->toBe('bytea')
        ->and($columns['phones_ciphertext'])->toBe('bytea')
        ->and($columns['email_ciphertext'])->toBe('bytea')
        ->and($columns['comment_ciphertext'])->toBe('bytea')
        ->and($columns['phone_bidx'])->toBe('ARRAY')
        ->and($columns['email_bidx'])->toBe('bytea')
        ->and($this->contact->refresh()->phone_bidx)->toHaveCount(2);

    // Exact-match lookups by blind index work inside the building.
    $bidx = app(ContactVault::class)->bidx((int) $this->b->id, 'phone', syntheticPhone(52));
    expect(inBuilding($this->b, fn () => ResidentContact::query()->withPhoneBidx($bidx)->count()))->toBe(1);
});

it('reveals only with a fresh second factor and audits it with the linked account as subject', function (): void {
    // Link the resident (approved member of apt 5 with the same verified e-mail).
    inBuilding($this->b, fn () => app(RosterLinker::class)->afterMembershipApproved(ApartmentMembership::query()->where('user_id', $this->resident->id)->firstOrFail()));
    expect($this->contact->refresh()->user_id)->toBe($this->resident->id);

    // An e-mail step-up is not a second factor.
    app(StepUpService::class)->markFresh($this->admin, 'email');
    expect(fn () => inBuilding($this->b, fn () => app(RosterViewer::class)->reveal($this->admin, apartment($this->b, 5))))->toThrow(RosterActionFailed::class);

    app(StepUpService::class)->markFresh($this->admin, 'totp');
    $shown = inBuilding($this->b, fn () => app(RosterViewer::class)->reveal($this->admin, apartment($this->b, 5)));
    expect($shown[0]['phones'])->toBe([syntheticPhone(51), syntheticPhone(52)])
        ->and($shown[0]['email'])->toBe('rev-resident@example.test');

    $entry = inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.reveal')->latest('id')->firstOrFail());
    expect($entry->subject_user_ids)->toBe([$this->resident->id])
        ->and($entry->actor_role)->toBe('building_admin')
        ->and($entry->data)->toEqual(['apartment_id' => apartment($this->b, 5)->id, 'contact_ids' => [$this->contact->id]])
        ->and(json_encode($entry->data))->not->toContain('@example.test');

    // "Kas skatīja manus datus": the resident sees the reveal by role, never the admin's name.
    $about = UserScopedQuery::for($this->resident)->auditAboutMe();
    expect($about->pluck('action')->all())->toContain('roster.reveal')->toContain('roster.link');

    $this->flushSession();
    $html = Livewire::actingAs($this->resident)->test(WhoViewed::class)->html();
    expect($html)->toContain(__('audit.action.roster.reveal'))
        ->toContain(__('audit.actor.building_admin'))
        ->not->toContain('rev-admin@example.test');
});

it('stops at 100 reveals per user per day', function (): void {
    config(['majas.roster.reveal_per_day' => 2]);
    app(StepUpService::class)->markFresh($this->admin, 'totp');

    inBuilding($this->b, fn () => app(RosterViewer::class)->reveal($this->admin, apartment($this->b, 5)));
    inBuilding($this->b, fn () => app(RosterViewer::class)->reveal($this->admin, apartment($this->b, 5)));

    try {
        inBuilding($this->b, fn () => app(RosterViewer::class)->reveal($this->admin, apartment($this->b, 5)));
        $this->fail('expected the cap');
    } catch (RosterActionFailed $e) {
        expect($e->key)->toBe('cap')->and($e->label())->toBe(__('roster.error.cap'));
    }
    expect(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.reveal')->count()))->toBe(2);
});

it('reveals through the list action (modal with tel: / mailto: links) and masks the list itself', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('manage'));
    Filament::setTenant($this->b);
    app(StepUpService::class)->markFresh($this->admin, 'totp');

    $list = inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(ListResidentContacts::class));
    $list->assertSee('+371 2•••••'.substr(syntheticPhone(51), -2))
        ->assertDontSee(substr(syntheticPhone(51), 4))
        ->assertSee(__('roster.list.masked_phone_sr', ['last' => substr(syntheticPhone(51), -2)]));

    $apartment = apartment($this->b, 5);
    $modal = inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(ListResidentContacts::class)
        ->mountTableAction('reveal', $apartment)
        ->assertMountedActionModalSeeHtml(['tel:'.syntheticPhone(51), 'mailto:rev-resident@example.test', 'data-testid="roster-reveal"']));

    // No unmasked value in the component state (wire:snapshot).
    $snapshot = json_encode($modal->snapshot);
    expect($snapshot)->not->toContain(substr(syntheticPhone(51), 4))->not->toContain('rev-resident@example.test');

    // Without a fresh second factor the action sends the admin to the step-up first.
    app(StepUpService::class)->forget();
    inBuilding($this->b, fn () => Livewire::actingAs($this->admin)->test(ListResidentContacts::class)
        ->mountTableAction('reveal', $apartment)
        ->assertRedirectContains('/step-up'));
});

it('audits the masked list once per user per 10 minutes per filter set', function (): void {
    steppedUp($this->admin);
    $this->actingAs($this->admin)->get('/admin/b/rev-house/roster')->assertOk();
    $this->actingAs($this->admin)->get('/admin/b/rev-house/roster')->assertOk();

    $entries = inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.list')->get());
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->data['rows'])->toBe(24)
        ->and($entries[0]->data['section_ids'])->toBe([section($this->b, 1)->id, section($this->b, 2)->id]);

    $this->travel(11)->minutes();
    $this->actingAs($this->admin)->get('/admin/b/rev-house/roster')->assertOk();
    expect(inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.list')->count()))->toBe(2);
});
