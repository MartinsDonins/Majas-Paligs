<x-filament-panels::page>
    @php($input = 'rounded-lg border border-gray-300 px-3 py-2 dark:border-white/10 dark:bg-white/5')
    @php($buildingNames = $this->buildings())
    <x-filament::section :heading="__('admin.audit.platform_chain')">
        <x-slot name="afterHeader">
            <x-filament::button size="sm" color="gray" wire:click="verifyPlatform" icon="heroicon-o-shield-check">{{ __('admin.audit.verify') }}</x-filament::button>
        </x-slot>
        <div class="grid gap-1 text-sm">
            @foreach ($this->platformEntries() as $e)
                <div class="flex flex-wrap justify-between gap-2 border-b border-gray-100 py-1 dark:border-white/5">
                    <span class="tabular-nums">{{ $e->created_at->timezone('Europe/Riga')->format('d.m.Y H:i:s') }}</span>
                    <span>
                        {{ __('audit.action.'.$e->action) }}
                        @if (is_array($e->data) && isset($e->data['purpose']))
                            · {{ __('admin.audit.purpose.'.$e->data['purpose']) }}
                        @endif
                        @if (is_array($e->data) && isset($e->data['building_id']) && isset($buildingNames[$e->data['building_id']]))
                            · {{ $buildingNames[$e->data['building_id']] }}
                        @endif
                    </span>
                    <span class="text-gray-500">{{ __('audit.actor.'.($e->actor_role ?? 'system')) }}</span>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('admin.audit.break_glass')" :description="__('admin.audit.break_glass_help')" icon="heroicon-o-lock-open" icon-color="danger">
        <div class="grid gap-3 text-sm md:grid-cols-3">
            <label for="break-glass-building" class="sr-only">{{ __('admin.building.label') }}</label>
            <select id="break-glass-building" wire:model="buildingId" class="{{ $input }}">
                <option value="">—</option>
                @foreach ($this->buildings() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            <label class="grid gap-1 md:col-span-2">
                <span class="font-medium">{{ __('admin.audit.reason') }}</span>
                <input wire:model="reason" minlength="10" maxlength="500" placeholder="{{ __('admin.audit.reason_placeholder') }}" class="{{ $input }}">
            </label>
        </div>
        <div class="mt-3">
            <x-filament::button color="danger" wire:click="breakGlass" wire:confirm="{{ __('admin.audit.break_glass_confirm') }}">{{ __('admin.audit.break_glass_open') }}</x-filament::button>
        </div>
        @if ($buildingEntries)
            <div class="mt-4 grid gap-1 text-sm">
                @foreach ($buildingEntries as $e)
                    <div class="flex flex-wrap justify-between gap-2 border-b border-gray-100 py-1 dark:border-white/5">
                        <span class="tabular-nums">{{ $e['time'] }}</span>
                        <span>{{ __('audit.action.'.$e['action']) }}</span>
                        <span class="text-gray-500">{{ __('audit.actor.'.($e['actor'] ?? 'system')) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
