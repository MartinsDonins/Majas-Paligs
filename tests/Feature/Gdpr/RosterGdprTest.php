<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Gdpr\AccountEraser;
use App\Gdpr\BuildingExporter;
use App\Gdpr\GdprExporter;
use App\Models\AuditEntry;
use App\Models\BuildingExport;
use App\Models\ResidentContact;
use App\Notifications\RosterContactsErased;
use Illuminate\Support\Facades\Notification;

/*
 * AC-R2-14 (GdprTest extension + Feature/Gdpr/BuildingExportExcludesRosterTest): a linked resident's self-service
 * export contains their roster rows (also by e-mail match); account erasure deletes them — even with gate L-R closed —
 * and notifies the admins with a count only; the building export never contains roster data.
 */
beforeEach(function (): void {
    $this->b = rosterHouse('gdpr-roster');
    $this->admin = rosterAdmin($this->b, 'gdpr-admin@example.test');
    $this->actingAs($this->admin);
    $this->resident = makeUser('gdpr-me@example.test');
    member($this->resident, $this->b, 9);
});

it('exports my roster rows (linked or by e-mail) in the self-service export', function (): void {
    rosterContact($this->b, $this->admin, 9, 'Mans Vārds', [syntheticPhone(81)], 'gdpr-me@example.test');
    rosterContact($this->b, $this->admin, 10, 'Cits Kaimiņš', [syntheticPhone(82)], 'cits@example.test');

    $payload = app(GdprExporter::class, ['exportId' => 'x'])->collect($this->resident);
    $mine = $payload['buildings'][0]['roster_contacts'];

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['name'])->toBe('Mans Vārds')
        ->and($mine[0]['phones'])->toBe([syntheticPhone(81)])
        ->and($mine[0]['email'])->toBe('gdpr-me@example.test')
        ->and(json_encode($payload))->not->toContain('Cits Kaimiņš')
        ->and(collect($payload['who_viewed_my_data'])->pluck('action')->all())->toContain('roster.link');
});

it('erases my roster rows with the account — also while the roster gate is closed — and tells the admins a count', function (): void {
    Notification::fake();
    rosterContact($this->b, $this->admin, 9, 'Mans Vārds', email: 'gdpr-me@example.test');
    rosterContact($this->b, $this->admin, 9, 'Otrs ieraksts', email: 'GDPR-me@example.test', phones: [syntheticPhone(83)]);
    $other = rosterContact($this->b, $this->admin, 11, 'Paliek');

    // Gate L-R closes before the erasure (DPA scope removed).
    $this->b->forceFill(['settings' => $this->b->settings->with(['dpa_scope' => ['core', 'heating', 'outages']])])->save();

    app(AccountEraser::class)->erase($this->resident->refresh());

    expect(inBuilding($this->b, fn () => ResidentContact::query()->pluck('id')->all()))->toBe([$other->id]);
    $entry = inBuilding($this->b, fn () => AuditEntry::query()->where('action', 'roster.erase')->sole());
    expect($entry->data)->toBe(['count' => 2])->and($entry->subject_user_ids)->toBe([$this->resident->id]);

    Notification::assertSentTo($this->admin, RosterContactsErased::class, function (RosterContactsErased $n): bool {
        $mail = $n->toMail($this->admin);
        $text = json_encode([$mail->subject, $mail->introLines, $n->toArray($this->admin)]);

        return $n->count === 2 && ! str_contains($text, 'gdpr-me') && ! str_contains($text, 'Mans Vārds') && ! str_contains($text, 'apartment');
    });
});

it('keeps the roster out of the building export', function (): void {
    rosterContact($this->b, $this->admin, 9, 'Neeksportējams Vārds', [syntheticPhone(84)], 'nekad@example.test');

    $export = inBuilding($this->b, fn () => BuildingExport::query()->create(['requested_by' => $this->admin->id, 'status' => ExportStatus::Queued]));
    $data = inBuilding($this->b, fn () => (new BuildingExporter((int) $this->b->id, (string) $export->id))->collect());
    $flat = json_encode($data);

    expect($flat)->not->toContain('Neeksportējams')
        ->not->toContain('nekad@example.test')
        ->not->toContain(substr(syntheticPhone(84), 4))
        ->not->toContain('resident_contacts')
        ->not->toContain('"roster_contacts"');
});
