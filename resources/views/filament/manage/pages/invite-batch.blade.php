<x-filament-panels::page>
    @php($gate = $this->gate())

    @if (! $gate->open)
        <x-filament::section :heading="__('admin.invites.blocked')" icon="heroicon-o-lock-closed" icon-color="warning">
            <p class="text-sm">{{ __('admin.invites.blocked_help') }}</p>
            <ul class="mt-2 list-disc ps-5 text-sm">
                @foreach ($gate->missingLabels() as $label)
                    <li>{{ $label }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('admin.invites.coverage')" icon="heroicon-o-chart-bar">
        <div class="grid gap-2 sm:grid-cols-5">
            @foreach ($this->coverage() as $row)
                <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                    <div class="font-semibold">{{ __('admin.section.short', ['n' => $row['section']]) }}</div>
                    <div>{{ __('admin.invites.active_count', ['active' => $row['active'], 'total' => $row['apartments']]) }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('admin.invites.print')" icon="heroicon-o-printer">
        <fieldset @disabled(! $gate->open) class="grid gap-4 text-sm">
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2"><input type="radio" wire:model.live="scope" value="section"> {{ __('admin.invites.scope_section') }}</label>
                <label class="flex items-center gap-2"><input type="radio" wire:model.live="scope" value="numbers"> {{ __('admin.invites.scope_numbers') }}</label>
                <label class="flex items-center gap-2"><input type="radio" wire:model.live="scope" value="all"> {{ __('admin.invites.scope_all') }}</label>
            </div>
            @if ($scope === 'section')
                <label class="grid gap-1 font-medium">{{ __('admin.section.label') }}
                    <select wire:model="sectionId" class="rounded-lg border border-gray-300 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                        @foreach ($this->sections() as $id => $number)
                            <option value="{{ $id }}">{{ __('admin.section.short', ['n' => $number]) }}</option>
                        @endforeach
                    </select>
                </label>
            @elseif ($scope === 'numbers')
                <label class="grid gap-1 font-medium">{{ __('admin.invites.numbers') }}
                    <input wire:model="apartments" class="rounded-lg border border-gray-300 px-3 py-2 dark:border-white/10 dark:bg-white/5" placeholder="145, 149, 153">
                </label>
            @endif
            <label class="grid gap-1 font-medium">{{ __('admin.invites.elder_contact') }}
                <input wire:model="elderContact" maxlength="120" class="rounded-lg border border-gray-300 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                <span class="text-xs text-gray-500">{{ __('admin.invites.elder_contact_help') }}</span>
            </label>
            <label class="grid gap-1 font-medium">{{ __('admin.invites.order') }}
                <select wire:model="order" class="rounded-lg border border-gray-300 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                    <option value="lv">LV → RU</option>
                    <option value="ru">RU → LV</option>
                </select>
            </label>
            <p class="text-xs text-gray-500">{{ __('admin.invites.warning') }}</p>
            <div class="flex flex-wrap gap-2">
                <x-filament::button wire:click="generate" icon="heroicon-o-printer" :disabled="! $gate->open">{{ __('admin.invites.generate') }}</x-filament::button>
                <x-filament::button color="gray" wire:click="revokeAll" wire:confirm="{{ __('admin.invites.revoke_confirm') }}">{{ __('admin.invites.revoke') }}</x-filament::button>
            </div>
        </fieldset>

        @if ($downloadUrl)
            <div class="mt-4 rounded-lg border border-primary-600 p-4">
                <p class="text-sm">{{ __('admin.invites.download_help') }}</p>
                <x-filament::button tag="a" :href="$downloadUrl" target="_blank" icon="heroicon-o-arrow-down-tray" class="mt-2">{{ __('admin.invites.download') }}</x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
