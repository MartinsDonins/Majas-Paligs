<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\PlatformRole;
use App\Gdpr\CoreGate;
use App\Gdpr\CoreGateMonitor;
use App\Gdpr\ParkingGate;
use App\Gdpr\ParkingGateMonitor;
use App\Gdpr\RosterGate;
use App\Gdpr\RosterGateMonitor;
use App\Models\Building;
use App\Models\BuildingDocument;
use App\Models\BuildingPrivacyNotice;
use App\Models\BuildingRole;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Notifications\DocumentRecorded;
use App\Notifications\OpeningRequestSent;
use App\Support\BuildingSettings;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * House documents made from platform masters (ROUND2-SPEC §3.2–3.6, GOV-010 / GOV-011, D7). Runs in the building
 * context (manage panel / wizard), or — for record() only — inside PlatformBuildingContext (OpeningReview).
 *
 * prepare → saveBlocks / saveAnswers (draft) → finalize (TemplateLinter, snapshot of placeholder values, frozen) →
 * send (DPA / annex → CoreDigify) → record (platform: date + ref + scope into buildings.settings, gates re-evaluated).
 * A privacy notice is published from its document (body_override); a DPIA-lite completion sets dpia_completed_at.
 * Every write: building_admin + a fresh second factor (policy + this backstop), audited `document.*`; every gate input
 * change runs CoreGateMonitor / ParkingGateMonitor / RosterGateMonitor on before/after.
 */
final class DocumentService
{
    public const MAX_BLOCK_CHARS = 4000;

    public function __construct(
        private readonly TemplateLinter $linter,
        private readonly DocumentRenderer $renderer,
        private readonly StepUpService $stepUp,
    ) {}

    /**
     * A draft of $kind from the newest published master (LV + RU). An open draft of the same kind is reused (its meta
     * replaced); otherwise a new version copies the editable blocks (and DPIA answers) of the previous version.
     *
     * @param  array{requested_scope?: list<string>, covers_modules?: list<string>}  $meta
     *
     * @throws DocumentRefused|AuthorizationException
     */
    public function prepare(User $actor, DocumentKind $kind, array $meta = []): BuildingDocument
    {
        $this->authorise($actor, 'create');
        $building = CurrentBuilding::get();
        $meta = $this->validMeta($building, $kind, $meta);

        $pair = DocumentTemplate::publishedPair($kind);
        if ($pair === null) {
            throw new DocumentRefused('template_missing');
        }
        ['lv' => $lv, 'ru' => $ru] = $pair;

        return DB::transaction(function () use ($actor, $kind, $meta, $lv, $ru): BuildingDocument {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['building-document:'.CurrentBuilding::id().':'.$kind->value]);

            $draft = BuildingDocument::query()->ofKind($kind)->where('status', DocumentStatus::Draft->value)->first();
            if ($draft !== null) {
                $draft->forceFill(['meta' => [...$draft->meta, ...$meta]])->save();
                AuditLogger::record('document.update', $draft, data: ['document_id' => $draft->id, 'kind' => $kind->value, 'meta' => array_keys($meta)], actorUserId: (int) $actor->id);

                return $draft;
            }

            $previous = BuildingDocument::query()->ofKind($kind)->orderByDesc('version')->first();
            $bodyLv = $lv->body;
            $bodyRu = $ru->body;
            if ($previous !== null) {
                $bodyLv = TemplateMarkup::withEditable($bodyLv, $this->editableOf($previous->body_lv));
                $bodyRu = TemplateMarkup::withEditable($bodyRu, $this->editableOf($previous->body_ru));
            }

            $document = BuildingDocument::query()->create([
                'kind' => $kind,
                'template_id' => $lv->id,
                'template_version' => $lv->version,
                'version' => ($previous->version ?? 0) + 1,
                'body_lv' => $bodyLv,
                'body_ru' => $bodyRu,
                'answers' => $kind === DocumentKind::DpiaLite ? ($previous?->answers !== null ? DpiaLite::normalise($previous->answers) : DpiaLite::defaults(app()->getLocale() === 'ru' ? 'ru' : 'lv')) : null,
                'meta' => $meta,
                'status' => DocumentStatus::Draft,
                'created_by' => $actor->id,
            ]);

            AuditLogger::record('document.create', $document, data: ['document_id' => $document->id, 'kind' => $kind->value, 'version' => $document->version], actorUserId: (int) $actor->id);

            return $document;
        });
    }

    /**
     * Write the editable blocks of a draft (keys of locked blocks or unknown keys are refused — never written).
     *
     * @param  array<string, string>  $lv
     * @param  array<string, string>  $ru
     *
     * @throws DocumentRefused|AuthorizationException
     */
    public function saveBlocks(User $actor, BuildingDocument $document, array $lv, array $ru): BuildingDocument
    {
        $this->authorise($actor, 'update', $document);
        $this->assertDraft($document);

        $editable = array_keys(TemplateMarkup::editable($document->body_lv));
        foreach ([$lv, $ru] as $values) {
            foreach ($values as $key => $text) {
                if (! in_array((string) $key, $editable, true)) {
                    throw new DocumentRefused('block_locked');
                }
                if (mb_strlen((string) $text) > self::MAX_BLOCK_CHARS) {
                    throw new DocumentRefused('too_long');
                }
            }
        }

        $document->forceFill([
            'body_lv' => TemplateMarkup::withEditable($document->body_lv, array_map('strval', $lv)),
            'body_ru' => TemplateMarkup::withEditable($document->body_ru, array_map('strval', $ru)),
        ])->save();

        AuditLogger::record('document.update', $document, data: [
            'document_id' => $document->id,
            'kind' => $document->kind->value,
            'blocks' => array_values(array_unique([...array_keys($lv), ...array_keys($ru)])),
        ], actorUserId: (int) $actor->id);

        return $document;
    }

    /**
     * DPIA-lite answers of a draft.
     *
     * @param  array<string, mixed>  $answers
     *
     * @throws DocumentRefused|AuthorizationException
     */
    public function saveAnswers(User $actor, BuildingDocument $document, array $answers): BuildingDocument
    {
        $this->authorise($actor, 'update', $document);
        $this->assertDraft($document);
        if ($document->kind !== DocumentKind::DpiaLite) {
            throw new DocumentRefused('not_dpia');
        }

        $document->forceFill(['answers' => DpiaLite::normalise($answers)])->save();
        AuditLogger::record('document.answers', $document, data: ['document_id' => $document->id], actorUserId: (int) $actor->id);

        return $document;
    }

    /**
     * "Gatavs parakstīšanai": lint LV + RU, freeze the bodies and the placeholder values (snapshot).
     *
     * @throws DocumentRefused|AuthorizationException
     */
    public function finalize(User $actor, BuildingDocument $document): BuildingDocument
    {
        $this->authorise($actor, 'finalize', $document);
        $this->assertDraft($document);

        $errors = $this->linter->check($document);
        if ($errors !== []) {
            throw new DocumentRefused('lint_failed', $errors);
        }

        if ($document->kind === DocumentKind::DpiaLite && DpiaLite::missing($document->answers) !== []) {
            throw new DocumentRefused('dpia_incomplete');
        }

        $building = CurrentBuilding::get();
        $document->forceFill([
            'status' => DocumentStatus::Final,
            'finalized_by' => $actor->id,
            'finalized_at' => now(),
            'meta' => [...$document->meta, 'snapshot' => $this->renderer->snapshot($building, $this->renderer->modulesFor($document))],
        ])->save();

        AuditLogger::record('document.finalize', $document, data: ['document_id' => $document->id, 'kind' => $document->kind->value, 'version' => $document->version], actorUserId: (int) $actor->id);

        return $document;
    }

    /**
     * "Nosūtīt CoreDigify" (DPA / annex): the platform admins see it under "Atvēršanas pieprasījumi".
     *
     * @throws DocumentRefused|AuthorizationException
     */
    public function send(User $actor, BuildingDocument $document): BuildingDocument
    {
        $this->authorise($actor, 'send', $document);
        if (! $document->kind->isAgreement()) {
            throw new DocumentRefused('not_agreement');
        }
        if ($document->status !== DocumentStatus::Final) {
            throw new DocumentRefused('not_final');
        }

        $document->forceFill(['status' => DocumentStatus::Sent, 'sent_by' => $actor->id, 'sent_at' => now()])->save();
        AuditLogger::record('document.send', $document, data: ['document_id' => $document->id, 'kind' => $document->kind->value, 'scope' => $document->requestedScope()], actorUserId: (int) $actor->id);

        $platformAdmins = User::query()->where('platform_role', PlatformRole::PlatformAdmin->value)
            ->whereNull('anonymised_at')->whereNotNull('email')->get();
        Notification::send($platformAdmins, new OpeningRequestSent((string) CurrentBuilding::get()->name, $document->kind->value));

        return $document;
    }

    /**
     * Platform side (OpeningRequestsPage, inside PlatformBuildingContext / OpeningReview): CoreDigify records the
     * signed DPA — date, ref and scope go into the settings (gates re-evaluated) and the document becomes `recorded`.
     *
     * @param  list<string>  $scope
     *
     * @throws DocumentRefused|AuthorizationException
     */
    public function record(User $platformAdmin, BuildingDocument $document, string $signedAt, string $ref, array $scope): void
    {
        if (! Gate::forUser($platformAdmin)->allows('record', $document)) {
            throw new AuthorizationException('Only a platform_admin records a DPA.');
        }
        if ($document->status !== DocumentStatus::Sent || ! $document->kind->isAgreement()) {
            throw new DocumentRefused('not_sent');
        }

        $building = CurrentBuilding::get();

        DB::transaction(function () use ($platformAdmin, $document, $building, $signedAt, $ref, $scope): void {
            self::applyDpa($building, $signedAt, $ref, $scope);

            $document->forceFill(['status' => DocumentStatus::Recorded, 'recorded_by' => $platformAdmin->id, 'recorded_at' => now()])->save();
            AuditLogger::record('document.record', $document, data: [
                'document_id' => $document->id,
                'kind' => $document->kind->value,
                'scope' => $building->refresh()->settings->dpaScope,
            ], actorRole: 'platform_admin', actorUserId: (int) $platformAdmin->id);
        });

        $adminIds = BuildingRole::query()->current()->where('role', BuildingRoleType::BuildingAdmin->value)->pluck('user_id');
        $admins = User::query()->whereIn('id', $adminIds)->whereNull('anonymised_at')->whereNotNull('email')->get();
        Notification::send($admins, new DocumentRecorded((string) $building->name));
    }

    /**
     * The one writer of the DPA fields (platform panel "DPA" action and the opening requests): building context of
     * $building required; audited `settings.dpa`; gate monitors run on before/after.
     *
     * @param  list<string>  $scope
     *
     * @throws DocumentRefused
     */
    public static function applyDpa(Building $building, ?string $signedAt, ?string $ref, array $scope): void
    {
        $before = CoreGate::status($building);
        $parkingBefore = ParkingGate::status($building);
        $rosterBefore = RosterGate::status($building);

        try {
            $settings = $building->settings->with([
                'dpa_signed_at' => $signedAt,
                'dpa_ref' => $ref !== null ? mb_substr(trim($ref), 0, 120) : null,
                'dpa_scope' => array_values($scope),
            ]);
        } catch (InvalidArgumentException) {
            throw new DocumentRefused('scope_invalid');
        }

        $building->forceFill(['settings' => $settings])->save();

        AuditLogger::record('settings.dpa', $building, data: [
            'dpa_signed_at' => $building->settings->dpaSignedAt,
            'dpa_scope' => $building->settings->dpaScope,
        ], actorRole: 'platform_admin');

        CoreGateMonitor::afterChange($building, $before);
        ParkingGateMonitor::afterChange($building, $parkingBefore);
        RosterGateMonitor::afterChange($building, $rosterBefore);
    }

    /**
     * Publish the building privacy notice from its document (wizard step `notice` / `roster_notice` /
     * `parking_notice`): lint + finalise a draft, withdraw the previous notice, write building_privacy_notices with
     * the filled body (body_override) and the covered modules; gates re-evaluated.
     *
     * @throws DocumentRefused|AuthorizationException
     */
    public function publishNotice(User $actor, BuildingDocument $document): BuildingPrivacyNotice
    {
        $this->authorise($actor, 'finalize', $document);
        if ($document->kind !== DocumentKind::PrivacyNotice) {
            throw new DocumentRefused('not_notice');
        }

        $building = CurrentBuilding::get();
        $controller = $building->settings->controller;
        if ($controller['name'] === null || $controller['contact'] === null) {
            throw new DocumentRefused('controller_first');
        }
        $this->validMeta($building, DocumentKind::PrivacyNotice, ['covers_modules' => $document->coversModules()]);

        if ($document->isDraft()) {
            $document = $this->finalize($actor, $document);
        } elseif ($document->status !== DocumentStatus::Final) {
            throw new DocumentRefused('not_final');
        }

        $before = CoreGate::status($building);
        $parkingBefore = ParkingGate::status($building);
        $rosterBefore = RosterGate::status($building);
        $covers = $document->coversModules();

        $notice = DB::transaction(function () use ($actor, $document, $building, $controller, $covers): BuildingPrivacyNotice {
            BuildingPrivacyNotice::current()?->forceFill(['withdrawn_at' => now()])->save();

            $body = [];
            foreach (['lv', 'ru'] as $locale) {
                $values = $document->meta['snapshot'][$locale] ?? $this->renderer->values($building, $locale, $covers);
                $body[$locale] = $this->renderer->noticeMarkdown($document->body($locale), (array) $values, $covers);
            }

            $notice = BuildingPrivacyNotice::query()->create([
                'template_version_id' => null,
                'document_id' => $document->id,
                'body_override' => $body,
                'controller_snapshot' => ['name' => $controller['name'], 'contact' => $controller['contact']],
                'covers_modules' => $covers,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);

            $this->supersedeOthers($document);

            AuditLogger::record('privacy_notice.publish', $notice, data: ['document_id' => $document->id, 'document_version' => $document->version, 'covers' => $covers], actorUserId: (int) $actor->id);

            return $notice;
        });

        CoreGateMonitor::afterChange($building, $before);
        ParkingGateMonitor::afterChange($building, $parkingBefore);
        RosterGateMonitor::afterChange($building, $rosterBefore);

        return $notice;
    }

    /**
     * "Pabeigt" DPIA-lite: finalise (every item answered + residual risk accepted) and set dpia_completed_at = today.
     *
     * @throws DocumentRefused|AuthorizationException
     */
    public function completeDpia(User $actor, BuildingDocument $document): BuildingDocument
    {
        if ($document->kind !== DocumentKind::DpiaLite) {
            throw new DocumentRefused('not_dpia');
        }

        $building = CurrentBuilding::get();
        if (! Gate::forUser($actor)->allows('updateParkingGate', $building)) {
            throw new AuthorizationException('Parking gate fields need building_admin and a fresh step-up.');
        }

        $parkingBefore = ParkingGate::status($building);

        $document = DB::transaction(function () use ($actor, $document, $building): BuildingDocument {
            $document = $this->finalize($actor, $document);
            $this->supersedeOthers($document);

            $building->forceFill(['settings' => $building->settings->with([
                'dpia_completed_at' => CarbonImmutable::now('Europe/Riga')->toDateString(),
            ])])->save();
            AuditLogger::record('opening.dpia', $building, data: ['document_id' => $document->id, 'version' => $document->version], actorUserId: (int) $actor->id);

            return $document;
        });

        ParkingGateMonitor::afterChange($building, $parkingBefore);

        return $document;
    }

    // ---- internals ----

    /**
     * @throws AuthorizationException
     */
    private function authorise(User $actor, string $ability, ?BuildingDocument $document = null): void
    {
        if (! Gate::forUser($actor)->allows($ability, $document ?? BuildingDocument::class)) {
            throw new AuthorizationException('Only a building_admin edits the house documents.');
        }

        $interactive = ! app()->runningInConsole() || app()->runningUnitTests();
        if ($interactive && ! $this->stepUp->hasFreshMfa($actor)) {
            throw new AuthorizationException('A fresh second factor is required for house documents.');
        }
    }

    /**
     * @throws DocumentRefused
     */
    private function assertDraft(BuildingDocument $document): void
    {
        if (! $document->isDraft()) {
            throw new DocumentRefused('not_draft');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, list<string>>
     *
     * @throws DocumentRefused
     */
    private function validMeta(Building $building, DocumentKind $kind, array $meta): array
    {
        $clean = static fn (mixed $list): array => array_values(array_unique(array_map('strval', (array) $list)));
        $recorded = $building->settings->dpaScope;

        if ($kind->isAgreement()) {
            $scope = $clean($meta['requested_scope'] ?? []);
            sort($scope);
            if (array_diff($scope, BuildingSettings::DPA_SCOPES) !== []) {
                throw new DocumentRefused('scope_invalid');
            }
            if (array_diff(CoreGate::REQUIRED_MODULES, $scope) !== []) {
                throw new DocumentRefused('scope_core_required');
            }
            if ($kind === DocumentKind::DpaAnnex && array_diff($scope, $recorded) === []) {
                throw new DocumentRefused('scope_nothing_new');
            }

            return ['requested_scope' => $scope];
        }

        if ($kind === DocumentKind::PrivacyNotice) {
            $covers = $clean($meta['covers_modules'] ?? CoreGate::REQUIRED_MODULES);
            sort($covers);
            if (array_diff(CoreGate::REQUIRED_MODULES, $covers) !== []) {
                throw new DocumentRefused('scope_core_required');
            }
            // Only modules inside the recorded DPA scope may be covered (ROUND2-SPEC §3.2 `notice`).
            if (array_diff($covers, $recorded) !== []) {
                throw new DocumentRefused('scope_outside_dpa');
            }

            return ['covers_modules' => $covers];
        }

        return [];
    }

    /**
     * Older final / sent documents of the same kind are superseded by $document (recorded DPAs stay recorded: they
     * are what CoreDigify signed).
     */
    private function supersedeOthers(BuildingDocument $document): void
    {
        BuildingDocument::query()->ofKind($document->kind)
            ->whereKeyNot($document->id)
            ->whereIn('status', [DocumentStatus::Final->value, DocumentStatus::Sent->value])
            ->get()
            ->each(fn (BuildingDocument $old) => $old->forceFill(['status' => DocumentStatus::Superseded, 'superseded_by' => $document->id])->save());
    }

    /**
     * @return array<string, string>
     */
    private function editableOf(string $body): array
    {
        try {
            return TemplateMarkup::editable($body);
        } catch (InvalidArgumentException) {
            return [];
        }
    }
}
