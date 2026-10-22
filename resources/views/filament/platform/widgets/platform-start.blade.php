{{-- "Sākt šeit" on the platform dashboard (FIRSTRUN-001): platform checklist + one row per house with the next step. --}}
<x-filament-widgets::widget>
    @php $d = $this->data(); @endphp
    <x-filament::section :heading="__('start.platform.heading')" :description="__('start.platform.lead')" icon="heroicon-o-rocket-launch">
        <div class="grid gap-6">
            <div class="grid gap-2" data-testid="platform-checklist">
                <p class="text-sm font-semibold">{{ __('start.platform.checklist') }}</p>
                <ul class="grid gap-2">
                    @foreach ($d['checklist'] as $item)
                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 dark:border-white/10" data-testid="platform-{{ $item['key'] }}" data-done="{{ $item['done'] ? '1' : '0' }}">
                            <span class="grid gap-1">
                                <span class="text-sm font-medium">{{ __('start.platform.item.'.$item['key'].'.title') }}</span>
                                <span>
                                    <x-filament::badge :color="$item['done'] ? 'success' : 'warning'" :icon="$item['done'] ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'">
                                        @if ($item['key'] === 'requests' && ! $item['done'])
                                            {{ trans_choice('start.platform.requests_waiting', $d['requests'], ['count' => $d['requests']]) }}
                                        @else
                                            {{ __('start.platform.item.'.$item['key'].'.'.($item['done'] ? 'done' : 'todo')) }}
                                        @endif
                                    </x-filament::badge>
                                </span>
                            </span>
                            @unless ($item['done'])
                                <x-filament::button size="sm" tag="a" :href="$item['url']" icon="heroicon-o-arrow-right">{{ __('start.platform.item.'.$item['key'].'.action') }}</x-filament::button>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="grid gap-2" data-testid="platform-houses">
                <p class="text-sm font-semibold">{{ __('start.platform.houses') }}</p>
                @forelse ($d['houses'] as $h)
                    <div class="grid gap-2 rounded-lg border border-gray-200 p-3 md:grid-cols-[1fr_auto] md:items-center dark:border-white/10" data-testid="platform-house-{{ $h['slug'] }}">
                        <div class="grid gap-1">
                            <p class="text-sm font-semibold">{{ $h['name'] }} <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $h['address'] }}</span></p>
                            <div class="flex flex-wrap gap-2">
                                <x-filament::badge :color="$h['core_open'] ? 'success' : 'warning'" :icon="$h['core_open'] ? 'heroicon-o-check-circle' : 'heroicon-o-lock-closed'">
                                    {{ $h['core_open'] ? __('admin.entry.core_open') : __('start.house.core_progress', ['done' => $h['core_progress']['done'], 'total' => $h['core_progress']['total']]) }}
                                </x-filament::badge>
                                <x-filament::badge :color="$h['roster_open'] ? 'success' : 'gray'" icon="heroicon-o-identification">
                                    {{ __('start.house.roster') }}: {{ $h['roster_open'] ? __('status.open') : __('status.closed') }}
                                </x-filament::badge>
                                <x-filament::badge color="gray" icon="heroicon-o-truck">{{ __('start.house.parking') }}: {{ __('opening.chip.'.$h['parking_chip']) }}</x-filament::badge>
                                <x-filament::badge :color="$h['roles'] === [] ? 'gray' : 'info'" icon="heroicon-o-shield-check">
                                    {{ __('start.house.your_role') }}: {{ $h['roles'] === [] ? __('start.house.none') : implode(', ', $h['roles']) }}
                                </x-filament::badge>
                                <x-filament::badge color="gray" icon="heroicon-o-home">
                                    {{ __('start.house.your_apartment') }}: {{ $h['membership'] === null ? __('start.house.none') : __('onboarding.role.apartment', ['n' => $h['membership']['number']]) }}
                                </x-filament::badge>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($h['can_manage'])
                                <x-filament::button size="sm" tag="a" :href="url('/admin/b/'.$h['slug'])" icon="heroicon-o-building-office-2" data-testid="platform-house-panel">{{ __('start.house.open_panel') }}</x-filament::button>
                            @else
                                <x-filament::button size="sm" tag="a" :href="route('admin.house', ['building' => $h['slug']])" icon="heroicon-o-key" data-testid="platform-house-claim">{{ __('start.house.claim_button') }}</x-filament::button>
                            @endif
                            <x-filament::button size="sm" color="gray" tag="a" :href="route('admin.house', ['building' => $h['slug']])" icon="heroicon-o-eye">{{ __('start.house.overview') }}</x-filament::button>
                            @if ($h['pending_requests'] > 0)
                                <x-filament::button size="sm" color="warning" tag="a" :href="\App\Filament\Platform\Pages\OpeningRequestsPage::getUrl()" icon="heroicon-o-inbox-arrow-down">
                                    {{ trans_choice('start.house.requests', $h['pending_requests'], ['count' => $h['pending_requests']]) }}
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm">{{ __('start.platform.no_houses') }}</p>
                @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
