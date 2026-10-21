{{-- Dokumenti (ROUND2-SPEC §1.2): list + PDF downloads; editing is in the opening wizard. Stacked cards (320 px). --}}
<x-filament-panels::page>
    @php
        $documents = $this->documents();
    @endphp
    <p class="text-sm">{{ __('document.disclaimer') }}</p>

    @if ($documents->isEmpty())
        <p class="text-sm" data-testid="documents-empty">{{ __('opening.documents.empty') }}</p>
    @else
        <ul class="grid gap-3" data-testid="documents">
            @foreach ($documents as $d)
                <li class="rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="doc-{{ $d->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ $d->kind->label() }}</span>
                        <span class="text-sm">v{{ $d->version }}</span>
                        <x-filament::badge :color="$d->isDraft() ? 'warning' : 'gray'" :icon="$d->isDraft() ? 'heroicon-o-pencil-square' : 'heroicon-o-lock-closed'">{{ $d->status->label() }}</x-filament::badge>
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        {{ __('opening.documents.created', ['date' => $d->created_at->setTimezone('Europe/Riga')->format('d.m.Y')]) }}
                        @if ($d->finalized_at)
                            · {{ __('opening.documents.finalized', ['date' => $d->finalized_at->setTimezone('Europe/Riga')->format('d.m.Y')]) }}
                        @endif
                        @if ($d->recorded_at)
                            · {{ __('opening.documents.recorded', ['date' => $d->recorded_at->setTimezone('Europe/Riga')->format('d.m.Y')]) }}
                        @endif
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach (['lv', 'ru'] as $loc)
                            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="download('{{ $d->id }}', '{{ $loc }}')">PDF {{ strtoupper($loc) }}</x-filament::button>
                        @endforeach
                        <x-filament::button size="sm" color="gray" tag="a" :href="$this->wizardUrl($d->kind)" icon="heroicon-o-arrow-right">{{ __('opening.title') }}</x-filament::button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
