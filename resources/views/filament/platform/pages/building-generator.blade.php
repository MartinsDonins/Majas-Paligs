<x-filament-panels::page>
    @php($input = 'rounded-lg border border-gray-300 px-3 py-2 dark:border-white/10 dark:bg-white/5')
    <x-filament::section :heading="__('admin.generator.input')" icon="heroicon-o-adjustments-horizontal">
        <div class="grid gap-4 text-sm md:grid-cols-3">
            <label class="grid gap-1 font-medium">{{ __('admin.building.label') }}
                <select wire:model="building" class="{{ $input }}">
                    <option value="">—</option>
                    @foreach ($this->buildings() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 font-medium">{{ __('admin.series.label') }}
                <select wire:model.live="seriesId" class="{{ $input }}">
                    @foreach ($this->seriesOptions() as $id => $code)
                        <option value="{{ $id }}">{{ $code }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 font-medium">{{ __('admin.generator.sections') }}
                <input type="number" min="1" max="20" wire:model.live.debounce.500ms="sections" class="{{ $input }}">
            </label>
            <label class="grid gap-1 font-medium">{{ __('admin.section.floors') }}
                <input type="number" min="1" max="30" wire:model="floors" class="{{ $input }}">
            </label>
            <label class="grid gap-1 font-medium">{{ __('admin.generator.first_no') }}
                <input type="number" min="1" wire:model="firstApartmentNo" class="{{ $input }}">
            </label>
            <label class="grid gap-1 font-medium">{{ __('admin.generator.direction') }}
                <select wire:model="direction" class="{{ $input }}">
                    <option value="ltr">{{ __('enum.numbering_direction.ltr') }}</option>
                    <option value="rtl">{{ __('enum.numbering_direction.rtl') }}</option>
                </select>
            </label>
        </div>
        <div class="mt-4 grid gap-3 text-sm md:grid-cols-5">
            @foreach ($layouts as $section => $code)
                <label class="grid gap-1 font-medium">{{ __('admin.section.short', ['n' => $section]) }}
                    <select wire:model="layouts.{{ $section }}" class="{{ $input }}">
                        @foreach ($this->layoutOptions() as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <x-filament::button color="gray" wire:click="previewGrid" icon="heroicon-o-eye">{{ __('admin.generator.preview') }}</x-filament::button>
            <x-filament::button wire:click="generate" wire:confirm="{{ __('admin.generator.confirm') }}" icon="heroicon-o-sparkles">{{ __('admin.generator.generate') }}</x-filament::button>
        </div>
        <p class="mt-2 text-xs text-gray-500">{{ __('admin.generator.hint') }}</p>
    </x-filament::section>

    @if ($result)
        <x-filament::section :heading="__('admin.generator.result')">
            <p class="text-sm">{{ __('admin.generator.done', $result) }}</p>
        </x-filament::section>
    @endif

    @if ($preview)
        <x-filament::section :heading="__('admin.generator.preview_title', ['apartments' => $preview['apartments'], 'risers' => $preview['risers']])">
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($preview['sections'] as $section)
                    <div>
                        <p class="mb-2 text-sm font-semibold">{{ __('admin.section.short', ['n' => $section['number']]) }} · {{ $section['first'] }}–{{ $section['last'] }} · {{ $section['layout_code'] }}</p>
                        <x-ui.building-grid :section="$section" :interactive="false" />
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
