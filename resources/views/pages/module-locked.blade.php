{{-- A closed module of the resident app (FIRSTRUN-001, EnsureModule): status 404 as before, but a member sees WHY it is
     closed and who opens it; the house's building_admin gets the button to the fix. No module data here. --}}
<x-layouts::app :title="__('start.module.'.$module)">
    <div class="stack-lg" data-testid="module-locked" data-reason="{{ $reason }}">
        <section class="stack">
            <h1 class="page-title">{{ __('start.module.'.$module) }}</h1>
            <x-ui.banner variant="info" icon="lock">
                <p class="font-semibold">{{ __('start.locked.'.$reason.'.title') }}</p>
                <p class="text-sm">{{ __('start.locked.'.$reason.'.body') }}</p>
            </x-ui.banner>
        </section>

        @if ($fixUrl)
            <a href="{{ $fixUrl }}" class="btn btn-primary btn-block" data-testid="module-fix">
                <x-lucide-wrench class="size-5" aria-hidden="true" />
                {{ __('start.locked.'.$reason.'.fix') }}
            </a>
        @endif

        <a href="{{ route('building.home', $building) }}" class="btn btn-block">{{ __('common.back_home') }}</a>
    </div>
</x-layouts::app>
