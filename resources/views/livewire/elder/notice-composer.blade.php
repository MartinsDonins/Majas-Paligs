<section class="card stack" aria-labelledby="notice-h">
    <h2 id="notice-h" class="text-lg">{{ __('elder.notice.title') }}</h2>
    <p class="text-sm muted">{{ __('elder.notice.lead') }}</p>

    @if (! $enabled)
        <x-ui.banner variant="info" icon="hammer">{{ __('elder.module_off') }}</x-ui.banner>
    @else
        @if ($posted)
            <x-ui.banner variant="info" icon="circle-check">{{ __('elder.notice.posted') }}</x-ui.banner>
        @endif

        <form wire:submit="post" class="space-y-4">
            <fieldset class="space-y-2">
                <legend class="field-label">{{ __('elder.notice.kind') }}</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($kinds as $k)
                        <label class="choice min-h-12 text-sm">
                            <input type="radio" name="notice-kind" value="{{ $k->value }}" wire:model.live="kind" class="size-5">
                            <span>{{ $k->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field id="notice-target" :label="__('elder.notice.target')">
                    <select id="notice-target" class="input" wire:model.live="target">
                        @if ($isAdmin)<option value="building">{{ __('elder.notice.target_building') }}</option>@endif
                        <option value="section">{{ __('elder.notice.target_section') }}</option>
                        <option value="column">{{ __('elder.notice.target_column') }}</option>
                        <option value="riser">{{ __('elder.notice.target_riser') }}</option>
                    </select>
                </x-ui.field>
                @if ($target !== 'building')
                    <x-ui.field id="notice-section" :label="__('elder.notice.section')" :error="$errors->first('stairwell')">
                        <select id="notice-section" class="input tnum" wire:model.live="stairwell">
                            @foreach ($sections as $s)
                                <option value="{{ $s->number }}">{{ __('heating.stairwell_tab', ['n' => $s->number]) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                @if ($target === 'column')
                    <x-ui.field id="notice-position" :label="__('elder.notice.position')" :error="$errors->first('position')">
                        <select id="notice-position" class="input tnum" wire:model="position">
                            <option value="">—</option>
                            @foreach ($positions as $p)
                                <option value="{{ $p }}">{{ __('grid.column', ['n' => $p]) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                @if ($target === 'riser')
                    <x-ui.field id="notice-riser" :label="__('elder.notice.riser')" :error="$errors->first('riser')">
                        <select id="notice-riser" class="input" wire:model="riser">
                            <option value="">—</option>
                            @foreach ($risers as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-4">
                <x-ui.field id="notice-from" :label="__('elder.notice.floors_from')" :error="$errors->first('floorsFrom')">
                    <input id="notice-from" type="number" min="1" max="40" inputmode="numeric" class="input tnum" wire:model="floorsFrom">
                </x-ui.field>
                <x-ui.field id="notice-to" :label="__('elder.notice.floors_to')" :error="$errors->first('floorsTo')">
                    <input id="notice-to" type="number" min="1" max="40" inputmode="numeric" class="input tnum" wire:model="floorsTo">
                </x-ui.field>
            </div>

            <x-ui.field id="notice-lv" :label="__('elder.notice.reason_lv')" :error="$errors->first('reasonLv')">
                <textarea id="notice-lv" rows="2" maxlength="300" lang="lv" class="input" wire:model="reasonLv"></textarea>
            </x-ui.field>
            <x-ui.field id="notice-ru" :label="__('elder.notice.reason_ru')" :error="$errors->first('reasonRu')">
                <textarea id="notice-ru" rows="2" maxlength="300" lang="ru" class="input" wire:model="reasonRu"></textarea>
            </x-ui.field>
            <x-ui.field id="notice-end" :label="__('elder.notice.expected_end')" :help="__('elder.notice.expected_end_help')" :error="$errors->first('expectedEnd')">
                <input id="notice-end" type="datetime-local" class="input tnum" wire:model="expectedEnd">
            </x-ui.field>

            <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
                <x-lucide-megaphone class="size-5" aria-hidden="true" /> {{ __('elder.notice.post') }}
            </button>
        </form>

        @if ($notices->isNotEmpty())
            <div class="stack">
                <h3 class="text-base font-bold">{{ __('elder.notice.open') }}</h3>
                @foreach ($notices as $n)
                    <div class="list-row" wire:key="notice-{{ $n->id }}">
                        <div>
                            <p class="font-semibold">{{ $n->kind->label() }}</p>
                            <p class="text-sm muted">{{ $presenter->scope($n) }}</p>
                        </div>
                        <button type="button" wire:click="resolve('{{ $n->id }}')" wire:confirm="{{ __('elder.notice.resolve_confirm') }}" class="btn">{{ __('elder.notice.resolve') }}</button>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
    <p class="text-sm muted flex items-center gap-2"><x-lucide-history class="size-4" aria-hidden="true" /> {{ __('elder.logged') }}</p>
</section>
