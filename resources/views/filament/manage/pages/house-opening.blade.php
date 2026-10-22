{{-- Mājas atvēršana (ROUND2-SPEC §3.2, DESIGN-SYSTEM §6): one card per step — title, status chip (icon + word, never
     colour alone), 1–2 line description, "Kāpēc?" disclosure, "Kas to dara?" line, one primary action. Stacked cards
     reflow at 320 px. Deep links: #step-<key>. --}}
<x-filament-panels::page>
    @php
        $c = $this->checklist();
        $edit = $this->canEdit();
        $parkingOnly = $this->parkingOnly();
        $settings = $this->building()->settings;
        $agreement = $this->openAgreement();
        $notice = $this->document(\App\Enums\DocumentKind::PrivacyNotice);
        $dpia = $this->document(\App\Enums\DocumentKind::DpiaLite);
        $editing = $this->editing();
        $chip = [
            'done' => ['success', 'heroicon-o-check-circle'],
            'todo' => ['warning', 'heroicon-o-exclamation-circle'],
            'waiting' => ['info', 'heroicon-o-clock'],
            'optional' => ['gray', 'heroicon-o-minus-circle'],
        ];
        $groups = $parkingOnly ? ['parking'] : ['core', 'roster', 'parking'];
        $steps = $parkingOnly ? \App\Services\Opening\OpeningChecklist::GROUPS['parking'] : array_keys(\App\Services\Opening\OpeningChecklist::STEPS);
    @endphp

    <section class="grid gap-3 sm:grid-cols-3" aria-label="{{ __('opening.progress_label') }}" data-testid="opening-progress">
        @foreach ($groups as $g)
            @php $p = $c->progress($g); @endphp
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <p class="text-sm font-semibold">{{ __('opening.group.'.$g) }}</p>
                <p class="text-sm" data-testid="progress-{{ $g }}">{{ __('opening.progress', ['done' => $p['done'], 'total' => $p['total']]) }}</p>
            </div>
        @endforeach
    </section>

    @unless ($edit)
        <p class="text-sm" data-testid="opening-read-only">{{ __('opening.read_only') }}</p>
    @endunless

    @if ($editing !== null)
        <x-filament::section id="editor" :heading="__('opening.editor.heading', ['kind' => $editing->kind->label()])" icon="heroicon-o-document-text" data-testid="opening-editor">
            @if ($editing->kind === \App\Enums\DocumentKind::DpiaLite)
                @include('filament.manage.pages.partials.dpia-form', ['document' => $editing, 'edit' => $edit])
            @else
                @livewire(\App\Livewire\Admin\DocumentEditor::class, ['documentId' => $editing->id], key('editor-'.$editing->id.'-'.$editing->status->value.'-'.$editing->updated_at?->timestamp))
            @endif

            @if ($problems !== [])
                <div class="mt-4 rounded-lg border border-warning-300 p-3 text-sm dark:border-warning-700" role="alert" data-testid="lint-errors">
                    <p class="inline-flex items-center gap-1 font-semibold">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-4" />{{ __('opening.editor.problems') }}
                    </p>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach ($problems as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($edit && $editing->isDraft() && $editing->kind->isAgreement())
                    <x-filament::button wire:click="finalize('{{ $editing->id }}')" icon="heroicon-o-lock-closed" data-testid="doc-finalize">{{ __('opening.document.finalize') }}</x-filament::button>
                @endif
                @if ($edit && $editing->status === \App\Enums\DocumentStatus::Final && $editing->kind->isAgreement())
                    <x-filament::button wire:click="send('{{ $editing->id }}')" icon="heroicon-o-paper-airplane" data-testid="doc-send">{{ __('opening.document.send') }}</x-filament::button>
                @endif
                @if ($edit && $editing->kind === \App\Enums\DocumentKind::PrivacyNotice && in_array($editing->status, [\App\Enums\DocumentStatus::Draft, \App\Enums\DocumentStatus::Final], true))
                    <x-filament::button wire:click="publishNotice('{{ $editing->id }}')" icon="heroicon-o-megaphone" data-testid="doc-publish">{{ __('opening.document.publish') }}</x-filament::button>
                @endif
                @foreach (['lv', 'ru'] as $loc)
                    <x-filament::button color="gray" wire:click="downloadPdf('{{ $editing->id }}', '{{ $loc }}')" icon="heroicon-o-arrow-down-tray">PDF {{ strtoupper($loc) }}</x-filament::button>
                @endforeach
                <x-filament::button color="gray" wire:click="closeEditor" icon="heroicon-o-x-mark">{{ __('opening.editor.close') }}</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <ol class="grid gap-4" data-testid="opening-steps">
        @foreach ($steps as $key)
            @php
                $status = $c->status($key);
                [$color, $icon] = $chip[$status];
            @endphp
            <li id="step-{{ $key }}" class="scroll-mt-24 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900" data-testid="step-{{ $key }}" data-status="{{ $status }}">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-semibold">{{ __('opening.step.'.$key.'.title') }}</h2>
                    <x-filament::badge :color="$color" :icon="$icon">{{ __('opening.status.'.$status) }}</x-filament::badge>
                </div>
                <p class="mt-1 text-sm">{{ __('opening.step.'.$key.'.desc') }}</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    <span class="font-medium">{{ __('opening.who_label') }}</span> {{ __('opening.who.'.\App\Services\Opening\OpeningChecklist::STEPS[$key]) }}
                </p>
                @if (\Illuminate\Support\Facades\Lang::has('opening.why.'.$key))
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer font-medium">{{ __('opening.why_label') }}</summary>
                        <p class="mt-1">{{ __('opening.why.'.$key) }}</p>
                    </details>
                @endif

                <div class="mt-3 grid gap-3">
                        @if ($key === 'structure')
                            @if ($url = $this->structureUrl())
                                <a href="{{ $url }}" class="text-sm text-primary-600 underline">{{ __('opening.structure.open') }}</a>
                            @endif
                            @endif

                        @if ($key === 'team')
                            @php $elders = $c->eldersPerSection(); @endphp
                            <ul class="flex flex-wrap gap-2 text-sm">
                                @foreach ($elders as $number => $count)
                                    <li class="rounded-lg border border-gray-200 px-2 py-1 dark:border-white/10">
                                        {{ __('admin.section.short', ['n' => $number]) }}: {{ $count > 0 ? trans_choice('opening.team.elders', $count, ['count' => $count]) : __('opening.team.no_elder') }}
                                    </li>
                                @endforeach
                            </ul>
                            <a href="{{ $this->teamUrl() }}" class="text-sm text-primary-600 underline">{{ __('opening.team.open') }}</a>
                            @endif

                        @if ($key === 'controller')
                            @if ($edit)
                                <form wire:submit="saveController" class="grid gap-3 md:grid-cols-3">
                                    <label class="grid gap-1 text-sm"><span class="font-medium">{{ __('opening.controller.name') }}</span>
                                        <input type="text" wire:model="controller.name" maxlength="200" class="rounded-lg border border-gray-300 p-2 dark:border-white/20 dark:bg-white/5" data-testid="controller-name">
                                        @error('controller.name') <span class="text-danger-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="grid gap-1 text-sm"><span class="font-medium">{{ __('opening.controller.contact') }}</span>
                                        <input type="text" wire:model="controller.contact" maxlength="200" class="rounded-lg border border-gray-300 p-2 dark:border-white/20 dark:bg-white/5" data-testid="controller-contact">
                                        <span class="text-xs text-gray-600 dark:text-gray-300">{{ __('opening.controller.contact_help') }}</span>
                                        @error('controller.contact') <span class="text-danger-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="grid gap-1 text-sm"><span class="font-medium">{{ __('opening.controller.basis') }}</span>
                                        <input type="text" wire:model="controller.basis_ref" maxlength="200" class="rounded-lg border border-gray-300 p-2 dark:border-white/20 dark:bg-white/5" data-testid="controller-basis">
                                    </label>
                                    <div class="md:col-span-3"><x-filament::button type="submit" icon="heroicon-o-check" data-testid="controller-save">{{ __('common.save') }}</x-filament::button></div>
                                </form>
                            @else
                                <p class="text-sm">{{ $settings->controller['name'] ?? '—' }}</p>
                            @endif
                            @endif

                        @if (in_array($key, ['dpa', 'roster_scope', 'parking_scope'], true))
                            @php
                                $module = ['dpa' => null, 'roster_scope' => 'roster', 'parking_scope' => 'parking'][$key];
                                $moduleArg = $module === null ? 'null' : "'".$module."'";
                            @endphp
                            @if ($status === 'done')
                                <p class="text-sm">{{ __('opening.dpa.recorded', ['ref' => $settings->dpaRef ?? '—', 'date' => $settings->dpaSignedAt ?? '—']) }}</p>
                            @elseif ($status === 'waiting')
                                <p class="text-sm">{{ __('opening.dpa.waiting') }}</p>
                                {{-- FIRSTRUN-001 (P1): when the house admin is also CoreDigify's platform admin, the record step is one
                                     click away (it stays on the platform side, PlatformBuildingContext purpose opening_review). --}}
                                @if (auth()->user()?->isPlatformAdmin())
                                    <div><x-filament::button size="sm" tag="a" :href="\App\Filament\Platform\Pages\OpeningRequestsPage::getUrl(panel: 'platform')" icon="heroicon-o-check-badge" data-testid="record-dpa-platform">{{ __('opening.requests.record') }}</x-filament::button></div>
                                @endif
                            @elseif ($agreement !== null && ($module === null || in_array($module, $agreement->requestedScope(), true)))
                                <p class="text-sm">{{ __('opening.dpa.in_progress', ['status' => $agreement->status->label()]) }}</p>
                                <div><x-filament::button size="sm" color="gray" wire:click="edit('{{ $agreement->id }}')" icon="heroicon-o-pencil-square">{{ __('opening.document.open') }}</x-filament::button></div>
                            @elseif ($edit && ($module === null || $settings->dpaSignedAt !== null))
                                @if ($module === null)
                                    <fieldset class="grid gap-1 text-sm">
                                        <legend class="font-medium">{{ __('opening.dpa.scope') }}</legend>
                                        <div class="flex flex-wrap gap-x-4 gap-y-2">
                                            @foreach ($this->moduleOptions() as $value => $label)
                                                <label class="inline-flex items-center gap-2">
                                                    <input type="checkbox" wire:model="dpaScope" value="{{ $value }}" @disabled(in_array($value, ['core', 'heating', 'outages'], true)) data-testid="scope-{{ $value }}">
                                                    {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @endif
                                <div><x-filament::button size="sm" wire:click="prepareAgreement({{ $moduleArg }})" icon="heroicon-o-document-plus" data-testid="prepare-{{ $key }}">{{ __($module === null ? 'opening.dpa.prepare' : 'opening.dpa.extend') }}</x-filament::button></div>
                            @elseif ($module !== null)
                                <p class="text-sm">{{ __('opening.dpa.after_dpa') }}</p>
                            @endif
                            @endif

                        @if (in_array($key, ['notice', 'roster_notice', 'parking_notice'], true))
                            @php
                                $module = ['notice' => null, 'roster_notice' => 'roster', 'parking_notice' => 'parking'][$key];
                                $moduleArg = $module === null ? 'null' : "'".$module."'";
                            @endphp
                            @if ($status !== 'done' && $notice !== null && $notice->isDraft() && ($module === null || in_array($module, $notice->coversModules(), true)))
                                <div><x-filament::button size="sm" color="gray" wire:click="edit('{{ $notice->id }}')" icon="heroicon-o-pencil-square">{{ __('opening.document.open') }}</x-filament::button></div>
                            @elseif ($status !== 'done' && $edit)
                                @if ($module !== null && ! in_array($module, $settings->dpaScope, true))
                                    <p class="text-sm">{{ __('opening.notice.needs_scope') }}</p>
                                @elseif ($module === null && ! $c->core->open && array_diff(['core', 'heating', 'outages'], $settings->dpaScope) !== [])
                                    <p class="text-sm">{{ __('opening.notice.needs_dpa') }}</p>
                                @else
                                    @if ($module === null)
                                        <fieldset class="grid gap-1 text-sm">
                                            <legend class="font-medium">{{ __('opening.notice.modules') }}</legend>
                                            <div class="flex flex-wrap gap-x-4 gap-y-2">
                                                @foreach ($this->moduleOptions() as $value => $label)
                                                    @if (in_array($value, $settings->dpaScope, true))
                                                        <label class="inline-flex items-center gap-2">
                                                            <input type="checkbox" wire:model="noticeModules" value="{{ $value }}" @disabled(in_array($value, ['core', 'heating', 'outages'], true)) data-testid="notice-{{ $value }}">
                                                            {{ $label }}
                                                        </label>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endif
                                    <div><x-filament::button size="sm" wire:click="prepareNotice({{ $moduleArg }})" icon="heroicon-o-document-plus" data-testid="prepare-{{ $key }}">{{ __($module === null ? 'opening.notice.prepare' : 'opening.notice.extend') }}</x-filament::button></div>
                                @endif
                            @elseif ($status === 'done')
                                <a href="{{ route('building.privacy', ['building' => $this->building()->slug]) }}" target="_blank" rel="noopener" class="text-sm text-primary-600 underline">{{ __('opening.notice.view') }}</a>
                            @endif
                            @endif

                        @if ($key === 'core_open')
                            @if ($status === 'done')
                                <p class="text-sm font-medium" data-testid="core-open">{{ __('opening.core_open.done', ['date' => $settings->openingCompletedAt ?? '']) }}</p>
                                <ul class="list-disc ps-5 text-sm">
                                    @if ($url = $this->invitesUrl())
                                        <li><a href="{{ $url }}" class="text-primary-600 underline">{{ __('opening.core_open.print') }}</a></li>
                                    @endif
                                    <li><a href="{{ $this->teamUrl() }}" class="text-primary-600 underline">{{ __('opening.core_open.invite') }}</a></li>
                                </ul>
                            @endif
                            @endif

                        @if ($key === 'roster_inform')
                            @if ($edit && $c->roster->open)
                                <form wire:submit="markRosterInformed" class="flex flex-wrap items-end gap-3">
                                    <label class="grid gap-1 text-sm"><span class="font-medium">{{ __('opening.roster_inform.date') }}</span>
                                        <input type="date" wire:model="rosterInformedAt" class="rounded-lg border border-gray-300 p-2 dark:border-white/20 dark:bg-white/5">
                                    </label>
                                    <x-filament::button type="submit" size="sm" icon="heroicon-o-check">{{ __('opening.roster_inform.mark') }}</x-filament::button>
                                </form>
                            @elseif ($status === 'done')
                                <p class="text-sm">{{ __('opening.roster_inform.done', ['date' => $settings->rosterInformedAt]) }}</p>
                            @endif
                            @endif

                        @if ($key === 'dpia')
                            @if ($status !== 'done' && $edit && $c->parking->l1())
                                @if ($dpia !== null && $dpia->isDraft())
                                    <div><x-filament::button size="sm" color="gray" wire:click="edit('{{ $dpia->id }}')" icon="heroicon-o-pencil-square">{{ __('opening.document.open') }}</x-filament::button></div>
                                @else
                                    <div><x-filament::button size="sm" wire:click="prepareDpia" icon="heroicon-o-clipboard-document-check" data-testid="prepare-dpia">{{ __('opening.dpia.start') }}</x-filament::button></div>
                                @endif
                            @elseif ($status === 'done')
                                <p class="text-sm">{{ __('opening.dpia.done', ['date' => $settings->dpiaCompletedAt]) }}</p>
                                @if ($dpia !== null && \Illuminate\Support\Facades\Gate::allows('view', $dpia))
                                    <div><x-filament::button size="sm" color="gray" wire:click="downloadPdf('{{ $dpia->id }}', '{{ app()->getLocale() === 'ru' ? 'ru' : 'lv' }}')" icon="heroicon-o-arrow-down-tray">PDF</x-filament::button></div>
                                @endif
                            @elseif (! $c->parking->l1())
                                <p class="text-sm">{{ __('opening.parking.after_l1') }}</p>
                            @endif
                            @endif

                        @if ($key === 'mandate')
                            @if ($edit)
                                <form wire:submit="saveMandate" class="flex flex-wrap items-end gap-3">
                                    <label class="grid min-w-0 flex-1 gap-1 text-sm"><span class="font-medium">{{ __('opening.mandate.label') }}</span>
                                        <input type="text" wire:model="mandateRef" maxlength="200" class="rounded-lg border border-gray-300 p-2 dark:border-white/20 dark:bg-white/5" placeholder="{{ __('opening.mandate.placeholder') }}" data-testid="mandate-ref">
                                        @error('mandateRef') <span class="text-danger-600">{{ $message }}</span> @enderror
                                    </label>
                                    <x-filament::button type="submit" size="sm" icon="heroicon-o-check" data-testid="mandate-save">{{ __('common.save') }}</x-filament::button>
                                </form>
                            @else
                                <p class="text-sm">{{ $settings->mandateRef ?? '—' }}</p>
                            @endif
                            @can('viewAny', \App\Models\BuildingDocument::class)
                                <p class="text-sm">{{ __('opening.mandate.template') }}
                                    <button type="button" class="text-primary-600 underline" wire:click="downloadDecision('lv')">LV</button> ·
                                    <button type="button" class="text-primary-600 underline" wire:click="downloadDecision('ru')">RU</button>
                                </p>
                            @endcan
                            @endif

                        @if ($key === 'parking_on')
                            <p class="text-sm">{{ __('opening.parking_on.what') }}</p>
                            @if ($edit && $c->parking->l2())
                                <div>
                                    @if ($settings->moduleEnabled('parking'))
                                        <x-filament::button size="sm" color="gray" wire:click="setParking(false)" icon="heroicon-o-pause">{{ __('opening.parking_on.off') }}</x-filament::button>
                                    @else
                                        <x-filament::button size="sm" wire:click="setParking(true)" icon="heroicon-o-play" data-testid="parking-on">{{ __('opening.parking_on.on') }}</x-filament::button>
                                    @endif
                                </div>
                            @elseif (! $c->parking->l2())
                                <p class="text-sm">{{ __('opening.parking_on.needs_l2') }}</p>
                            @endif
                            @endif
                </div>
            </li>
        @endforeach
    </ol>
</x-filament-panels::page>
