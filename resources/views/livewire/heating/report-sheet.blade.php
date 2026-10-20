<div class="space-y-4">
    @if (! $canReport)
        <x-ui.banner variant="info" icon="clock">{{ __('heating.pending_read_only') }}</x-ui.banner>
    @elseif ($submission || $undone)
        {{-- Thank-you: stays until "Labi" (no disappearing confirmations). --}}
        <div class="space-y-4 text-center" role="status">
            @if ($undone)
                <x-lucide-undo-2 class="mx-auto size-12 text-(--color-fg-muted)" aria-hidden="true" />
                <p class="text-xl font-bold">{{ __('heating.report.undone') }}</p>
            @else
                <x-lucide-circle-check class="mx-auto size-12 text-(--color-success)" aria-hidden="true" />
                <p class="text-xl font-bold">{{ __('heating.report.thanks') }}</p>
                <p>{{ \App\Support\Copy::count('heating.report.thanks_count', $confirmedCount, ['count' => $confirmedCount]) }}</p>
                <p class="text-sm muted">{{ __('heating.report.nothing_else') }}</p>
                <div x-data="countdown" data-seconds="{{ $undoSeconds }}" x-show="active">
                    <button type="button" wire:click="undo" class="btn-link" data-requires-online>
                        <x-lucide-undo-2 class="size-4" aria-hidden="true" /> {{ __('heating.report.undo') }} (<span x-text="left"></span>)
                    </button>
                </div>
            @endif
            <button type="button" wire:click="done" class="btn btn-primary btn-block">{{ __('heating.report.ok') }}</button>
        </div>
    @else
        <form wire:submit="save" class="space-y-4" novalidate>
            <div class="space-y-2">
                <p class="text-sm font-semibold">{{ __('heating.report.same_everywhere') }}</p>
                <div class="status-grid" role="group" aria-label="{{ __('heating.report.same_everywhere') }}">
                    @foreach ($statuses as $s)
                        <button type="button" wire:click="allSame('{{ $s->value }}')" class="btn min-h-12 flex-col gap-1 px-1 text-sm [overflow-wrap:anywhere]" data-requires-online>
                            {{ svg('lucide-'.$s->icon(), 'size-5', ['aria-hidden' => 'true']) }}
                            {{ $s->label() }}
                        </button>
                    @endforeach
                </div>
            </div>

            @error('states') <x-ui.banner variant="warning" icon="circle-alert" role="alert">{{ $message }}</x-ui.banner> @enderror

            @foreach ($rooms as $room)
                <fieldset class="room-row" wire:key="room-{{ $room['code'] }}">
                    <legend class="px-1 font-semibold">{{ $room['label'] }}</legend>
                    <div class="status-grid mt-2">
                        @foreach ($statuses as $s)
                            <label class="status-option">
                                <input type="radio" name="state-{{ $room['code'] }}" value="{{ $s->value }}" wire:model="states.{{ $room['code'] }}">
                                <span class="opt-{{ $s->value }}">
                                    {{ svg('lucide-'.$s->icon(), '', ['aria-hidden' => 'true']) }}
                                    {{ $s->label() }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            <button type="button" wire:click="toggleExtras" class="btn-link text-sm" aria-expanded="{{ $showExtras ? 'true' : 'false' }}">
                <x-lucide-plus class="size-4" aria-hidden="true" /> {{ __('heating.report.extras') }}
            </button>

            @if ($showExtras)
                <div class="space-y-4">
                    <x-ui.field id="temperature" :label="__('heating.report.temperature')" :help="__('heating.report.temperature_help')" :error="$errors->first('temperature')">
                        <input id="temperature" type="text" inputmode="decimal" maxlength="4" class="input tnum" wire:model="temperature" aria-describedby="temperature-help" placeholder="21.5">
                    </x-ui.field>

                    <fieldset>
                        <legend class="field-label">{{ __('heating.report.triage') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($triages as $t)
                                <label class="choice min-h-12 text-sm">
                                    <input type="radio" name="triage" value="{{ $t->value }}" wire:model="triage" class="size-5">
                                    <span>{{ $t->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <x-ui.field id="note" :label="__('heating.report.note')" :help="__('heating.report.note_help')" :error="$errors->first('note')">
                        <textarea id="note" rows="3" maxlength="280" class="input" wire:model="note" aria-describedby="note-help"></textarea>
                    </x-ui.field>
                </div>
            @endif

            <div x-data="connectivity">
                <div x-show="offline" x-cloak class="banner banner-warning mb-3" role="status">
                    <x-lucide-wifi-off class="size-5 shrink-0" aria-hidden="true" /> <span>{{ __('shell.offline') }}</span>
                </div>
                {{-- The same error again right above the button: the banner at the top of the sheet is off-screen on a phone. --}}
                @error('states') <p class="field-error mb-3" role="alert" data-testid="report-error-near-save">{{ $message }}</p> @enderror
                <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
                    <x-lucide-check class="size-5" aria-hidden="true" /> {{ __('common.save') }}
                </button>
            </div>

            <x-ui.privacy-note>{{ __('heating.report.privacy') }}</x-ui.privacy-note>
        </form>
    @endif
</div>
