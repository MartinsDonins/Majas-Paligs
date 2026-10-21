{{-- GET /admin — administration chooser (ROUND2-SPEC §1.1): one card per building where the user holds a manage
     role (name, address, the user's roles, the L-core summary) and "Platforma" for a platform_admin. Resident shell
     (strict CSP, senior mode, 320 px). Status chips are icon + word, never colour alone. --}}
<x-layouts::app :title="__('admin.entry.title')">
    <div class="stack-lg">
        <section class="stack">
            <h1 class="page-title">{{ __('admin.entry.title') }}</h1>
            <p class="lead">{{ __('admin.entry.lead') }}</p>
        </section>

        <ul class="stack" aria-label="{{ __('admin.entry.title') }}">
            @foreach ($cards as $card)
                <li>
                    <a href="{{ url('/admin/b/'.$card['slug']) }}" class="card stack no-underline text-(--color-fg)" data-admin-target="{{ $card['slug'] }}">
                        <span class="flex items-start justify-between gap-3">
                            <span class="flex min-w-0 items-center gap-3">
                                <x-lucide-building-2 class="size-6 shrink-0 text-(--color-accent)" aria-hidden="true" />
                                <span class="min-w-0">
                                    <span class="block text-lg font-semibold">{{ $card['name'] }}</span>
                                    <span class="block text-sm muted">{{ $card['address'] }}</span>
                                </span>
                            </span>
                            <x-lucide-chevron-right class="size-5 shrink-0" aria-hidden="true" />
                        </span>
                        <span class="flex flex-wrap gap-2">
                            @foreach ($card['roles'] as $role)
                                <span class="chip chip-accent text-xs">
                                    <x-lucide-shield-check class="size-4" aria-hidden="true" />
                                    <span>{{ $role }}</span>
                                </span>
                            @endforeach
                            <x-ui.status-chip :status="$card['core_open'] ? 'open' : 'closed'" size="sm">
                                {{ $card['core_open'] ? __('admin.entry.core_open') : __('admin.entry.core_closed') }}
                            </x-ui.status-chip>
                        </span>
                    </a>
                </li>
            @endforeach

            @if ($platform)
                <li>
                    <a href="{{ url('/admin/platform') }}" class="card no-underline text-(--color-fg)" data-admin-target="platform">
                        <span class="flex items-center justify-between gap-3">
                            <span class="flex min-w-0 items-center gap-3">
                                <x-lucide-server-cog class="size-6 shrink-0 text-(--color-accent)" aria-hidden="true" />
                                <span class="min-w-0">
                                    <span class="block text-lg font-semibold">{{ __('admin.entry.platform') }}</span>
                                    <span class="block text-sm muted">{{ __('admin.entry.platform_help') }}</span>
                                </span>
                            </span>
                            <x-lucide-chevron-right class="size-5 shrink-0" aria-hidden="true" />
                        </span>
                    </a>
                </li>
            @endif
        </ul>

        <a href="{{ route('home.redirect') }}" class="btn btn-block">{{ __('admin.entry.back_to_app') }}</a>
    </div>
</x-layouts::app>
