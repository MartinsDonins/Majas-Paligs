{{-- /start (FIRSTRUN-001): no apartment yet — how to join, and for administrators the way to their houses. --}}
<div class="stack-lg" data-testid="start-page">
    <section class="stack">
        <h1 class="page-title">{{ __('start.no_home.title') }}</h1>
        <p class="lead">{{ __('start.no_home.lead') }}</p>
    </section>

    <section class="card stack" aria-labelledby="join-h">
        <h2 id="join-h" class="text-lg">{{ __('start.no_home.join_title') }}</h2>
        <p class="text-sm">{{ __('start.no_home.join_body') }}</p>
        <a href="{{ route('join') }}" class="btn btn-block" data-testid="start-join">
            <x-lucide-mail-plus class="size-5" aria-hidden="true" /> {{ __('start.no_home.join_button') }}
        </a>
    </section>

    @if ($canAdmin)
        <section class="card stack" aria-labelledby="adm-h">
            <h2 id="adm-h" class="text-lg">{{ __('start.no_home.admin_title') }}</h2>
            <p class="text-sm">{{ __('start.no_home.admin_body') }}</p>
            <a href="{{ \App\Support\ViewSwitch::url('admin') }}" class="btn btn-primary btn-block" data-testid="start-admin">
                <x-lucide-shield-check class="size-5" aria-hidden="true" /> {{ __('home.tools.administration') }}
            </a>
        </section>
    @endif

    @if ($houses !== [])
        <section class="stack" aria-labelledby="houses-h">
            <h2 id="houses-h" class="text-lg">{{ __('start.no_home.houses_title') }}</h2>
            <ul class="stack">
                @foreach ($houses as $house)
                    <li>
                        <a href="{{ route('admin.house', ['building' => $house['slug']]) }}" class="card block stack no-underline text-(--color-fg)" data-start-house="{{ $house['slug'] }}">
                            <span class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block text-lg font-semibold">{{ $house['name'] }}</span>
                                    <span class="block text-sm muted">{{ $house['address'] }}</span>
                                </span>
                                <x-lucide-chevron-right class="size-5 shrink-0" aria-hidden="true" />
                            </span>
                            <span class="flex flex-wrap gap-2">
                                <x-ui.status-chip :status="$house['core_open'] ? 'open' : 'closed'" size="sm">
                                    {{ $house['core_open'] ? __('admin.entry.core_open') : __('start.house.core_progress', ['done' => $house['core_progress']['done'], 'total' => $house['core_progress']['total']]) }}
                                </x-ui.status-chip>
                                <span class="chip text-xs">
                                    <x-lucide-shield-check class="size-4" aria-hidden="true" />
                                    <span>{{ $house['roles'] === [] ? __('start.house.no_role') : implode(', ', $house['roles']) }}</span>
                                </span>
                                @if ($house['membership'] === null)
                                    <span class="chip text-xs"><x-lucide-house class="size-4" aria-hidden="true" /><span>{{ __('start.house.no_apartment') }}</span></span>
                                @endif
                            </span>
                            <span class="block text-sm font-semibold text-(--color-accent)">{{ __('start.no_home.house_next') }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
