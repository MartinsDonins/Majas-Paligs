<div x-data="sheet" x-on:keydown="onKeydown" class="relative min-w-0 grow basis-28">
    <button type="button" x-on:click="toggle" x-bind:aria-expanded="open" aria-haspopup="dialog"
            class="flex min-h-12 max-w-full items-center gap-2 rounded-full border border-(--color-line) bg-(--color-surface) px-3 text-sm font-bold text-(--color-fg)">
        <x-lucide-building-2 class="size-4 shrink-0 text-(--color-accent)" aria-hidden="true" />
        <span class="truncate">
            {{ $building?->shortLabel() }}@if($current) · {{ __('shell.section_short', ['n' => $current->sectionNumber]) }}@endif
        </span>
        <x-lucide-chevron-down class="size-4 shrink-0" aria-hidden="true" />
    </button>

    <div x-show="open" x-cloak class="fixed inset-0 z-40 bg-black/30" x-on:click="hide"></div>
    <div x-show="open" x-cloak role="dialog" aria-modal="true" aria-labelledby="switcher-h"
         class="fixed inset-x-0 bottom-0 z-50 max-h-[90vh] overflow-y-auto rounded-t-(--radius-lg) bg-(--color-surface) p-4 shadow-(--shadow-2)"
         style="padding-bottom: calc(16px + env(safe-area-inset-bottom))">
        <div class="mx-auto max-w-[40rem] stack">
            <div class="flex items-center justify-between">
                <h2 id="switcher-h" class="text-lg">{{ __('shell.my_homes') }}</h2>
                <button type="button" x-on:click="hide" class="btn">{{ __('common.close') }}</button>
            </div>
            <ul>
                @foreach ($memberships as $m)
                    <li>
                        <a href="{{ route('building.home', $m->buildingSlug) }}" class="list-row no-underline text-(--color-fg)" @if($building && $m->buildingId === $building->id) aria-current="page" @endif>
                            <span>
                                <span class="block font-semibold">{{ $m->buildingName }}</span>
                                <span class="block text-sm muted tnum">{{ __('onboarding.role.apartment', ['n' => $m->apartmentNumber]) }} · {{ __('shell.section_short', ['n' => $m->sectionNumber]) }}</span>
                            </span>
                            <x-ui.status-chip :status="$m->status->value" size="sm" />
                        </a>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('join') }}" class="btn btn-block"><x-lucide-house-plus class="size-5" aria-hidden="true" /> {{ __('shell.add_home') }}</a>
        </div>
    </div>
</div>
