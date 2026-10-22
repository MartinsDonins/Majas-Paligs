{{-- Platform house page (FIRSTRUN-001): why this house is empty for the platform admin, and the next step. Resident
     shell (strict CSP, 320 px, senior mode); every status is icon + word. --}}
<div class="stack-lg" data-testid="platform-house">
    @if ($h === [])
        <x-ui.banner variant="info" icon="lock">{{ __('start.house.step_up') }}</x-ui.banner>
    @else
        <section class="stack">
            <p class="text-sm muted">{{ __('start.house.kicker') }}</p>
            <h1 class="page-title">{{ $h['name'] }}</h1>
            <p class="muted">{{ $h['address'] }}</p>
        </section>

        @if (in_array(session('status'), ['no_role_here', 'no_home_here'], true))
            <x-ui.banner variant="info" icon="info" data-testid="why-here">
                <p class="font-semibold">{{ __('start.house.why.'.session('status').'.title') }}</p>
                <p class="text-sm">{{ __('start.house.why.'.session('status').'.body') }}</p>
            </x-ui.banner>
        @endif

        @if ($done)
            <x-ui.banner variant="info" icon="circle-check" data-testid="house-done">{{ __('start.house.done.'.$done) }}</x-ui.banner>
        @endif
        @if ($refused)
            <x-ui.banner variant="warning" icon="circle-alert" role="alert" data-testid="house-refused">{{ __('start.refused.'.$refused) }}</x-ui.banner>
        @endif

        {{-- 1. Opening status --}}
        <section class="card stack" aria-labelledby="open-h" data-testid="house-opening">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="open-h" class="text-lg">{{ __('start.house.opening_title') }}</h2>
                <x-ui.status-chip :status="$h['core_open'] ? 'open' : 'closed'" size="sm">
                    {{ $h['core_open'] ? __('admin.entry.core_open') : __('start.house.core_progress', ['done' => $h['core_progress']['done'], 'total' => $h['core_progress']['total']]) }}
                </x-ui.status-chip>
            </div>
            @if (! $h['core_open'])
                <p class="text-sm">{{ __('start.house.opening_closed_body') }}</p>
                @if ($h['core_missing'] !== [])
                    <div class="text-sm">
                        <p class="font-semibold">{{ __('start.house.missing') }}</p>
                        <ul class="list-disc ps-5">
                            @foreach ($h['core_missing'] as $label)
                                <li>{{ $label }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
                <dt class="muted">{{ __('start.house.roster') }}</dt>
                <dd class="font-semibold">{{ $h['roster_open'] ? __('status.open') : __('status.closed') }}</dd>
                <dt class="muted">{{ __('start.house.parking') }}</dt>
                <dd class="font-semibold">{{ __('opening.chip.'.$h['parking_chip']) }}</dd>
                <dt class="muted">{{ __('start.module.heating') }}</dt>
                <dd class="font-semibold">{{ $h['heating_on'] ? __('common.on') : __('common.off') }}</dd>
                <dt class="muted">{{ __('start.module.outages') }}</dt>
                <dd class="font-semibold">{{ $h['outages_on'] ? __('common.on') : __('common.off') }}</dd>
                <dt class="muted">{{ __('start.house.structure') }}</dt>
                <dd class="font-semibold tnum">{{ __('start.house.structure_value', ['sections' => $h['sections'], 'apartments' => $h['apartments']]) }}</dd>
            </dl>
            @if ($h['is_admin'] && ! $h['core_open'])
                <a href="{{ $wizardUrl }}" class="btn btn-primary btn-block" data-testid="house-wizard">
                    <x-lucide-list-checks class="size-5" aria-hidden="true" /> {{ __('start.house.continue_opening') }}
                </a>
            @endif
            @if ($h['pending_requests'] > 0)
                <a href="{{ $requestsUrl }}" class="btn btn-block" data-testid="house-requests">
                    <x-lucide-mail-plus class="size-5" aria-hidden="true" /> {{ trans_choice('start.house.requests', $h['pending_requests'], ['count' => $h['pending_requests']]) }}
                </a>
            @endif
        </section>

        {{-- 2. My role here --}}
        <section class="card stack" aria-labelledby="role-h" data-testid="house-role">
            <h2 id="role-h" class="text-lg">{{ __('start.house.role_title') }}</h2>
            @if ($h['roles'] === [])
                <p class="flex items-center gap-2 font-semibold"><x-lucide-user-x class="size-5" aria-hidden="true" /> {{ __('start.house.no_role') }}</p>
            @else
                <p class="flex flex-wrap gap-2">
                    @foreach ($h['roles'] as $label)
                        <span class="chip chip-accent text-xs"><x-lucide-shield-check class="size-4" aria-hidden="true" /><span>{{ $label }}</span></span>
                    @endforeach
                </p>
            @endif

            @if ($h['can_manage'])
                <a href="{{ $panelUrl }}" class="btn btn-primary btn-block" data-testid="house-panel">
                    <x-lucide-building-2 class="size-5" aria-hidden="true" /> {{ __('start.house.open_panel') }}
                </a>
            @endif

            @if (! $h['is_admin'])
                <p class="text-sm">{{ __('start.house.claim_body') }}</p>
                @if ($h['admins'] > 0)
                    <p class="text-sm muted">{{ trans_choice('start.house.claim_others', $h['admins'], ['count' => $h['admins']]) }}</p>
                @endif
                <form wire:submit="claimAdmin" class="stack">
                    <x-ui.field id="mandateRef" :label="__('start.house.mandate')" :help="__('start.house.mandate_hint')">
                        <input id="mandateRef" type="text" wire:model="mandateRef" maxlength="200" class="input" autocomplete="off" aria-describedby="mandateRef-help">
                    </x-ui.field>
                    <button type="submit" class="btn btn-primary btn-block" data-testid="house-claim">
                        <x-lucide-key-round class="size-5" aria-hidden="true" /> {{ __('start.house.claim_button') }}
                    </button>
                    <p class="text-sm muted">{{ __('start.house.strong_note') }}</p>
                </form>
            @endif
        </section>

        {{-- 3. My apartment here --}}
        <section class="card stack" aria-labelledby="apt-h" data-testid="house-apartment">
            <h2 id="apt-h" class="text-lg">{{ __('start.house.apartment_title') }}</h2>
            @if ($h['membership'] !== null)
                <p class="flex flex-wrap items-center gap-2">
                    <span class="text-xl font-bold tnum">{{ __('onboarding.role.apartment', ['n' => $h['membership']['number']]) }}</span>
                    <x-ui.status-chip :status="$h['membership']['status']" size="sm" />
                </p>
            @elseif (! $h['core_open'])
                <p class="text-sm">{{ __('start.house.link_closed') }}</p>
            @else
                <p class="text-sm">{{ __('start.house.link_body') }}</p>
                <form wire:submit="linkSelf" class="stack">
                    <x-ui.field id="apartmentNumber" :label="__('start.link.apartment')">
                        <input id="apartmentNumber" type="number" inputmode="numeric" min="1" wire:model="apartmentNumber" class="input tnum" required>
                    </x-ui.field>
                    <x-ui.field id="role" :label="__('start.link.role')">
                        <select id="role" wire:model="role" class="input">
                            @foreach (['owner', 'tenant', 'family'] as $r)
                                <option value="{{ $r }}">{{ __('enum.membership_role.'.$r) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <button type="submit" class="btn btn-primary btn-block" data-testid="house-link">
                        <x-lucide-house class="size-5" aria-hidden="true" /> {{ __('start.link.button') }}
                    </button>
                    <p class="text-sm muted">{{ __('start.link.platform_note') }}</p>
                </form>
            @endif
        </section>

        <a href="{{ route('admin.entry') }}" class="btn btn-block">{{ __('admin.entry.all_buildings') }}</a>
    @endif
</div>
