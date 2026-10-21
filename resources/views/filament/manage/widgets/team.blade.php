{{-- Team card (ROUND2-SPEC §3.7): counts only, stairwells without an elder, pending invitations. --}}
<x-filament-widgets::widget>
    @php($d = $this->data())
    <x-filament::section :heading="__('admin.team.title')" icon="heroicon-o-user-group">
        <ul class="flex flex-wrap gap-2 text-sm" data-testid="team-counts">
            @foreach ($d['roles'] as $role => $n)
                <li class="rounded-lg border border-gray-200 px-2 py-1 dark:border-white/10">{{ __('enum.building_role.'.$role) }}: {{ $n }}</li>
            @endforeach
            <li class="rounded-lg border border-gray-200 px-2 py-1 dark:border-white/10">{{ __('admin.team.pending') }}: {{ $d['pending'] }}</li>
        </ul>
        @if ($d['without'] !== [])
            <ul class="mt-3 grid gap-1 text-sm text-warning-700 dark:text-warning-400" data-testid="team-no-elder">
                @foreach ($d['without'] as $number)
                    <li class="inline-flex items-center gap-1">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-4" />{{ __('opening.team.section_no_elder', ['n' => $number]) }}
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="mt-3 text-sm"><a href="{{ $d['url'] }}" class="text-primary-600 underline">{{ __('opening.team.open') }}</a></p>
    </x-filament::section>
</x-filament-widgets::widget>
