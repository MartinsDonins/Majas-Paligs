{{-- Komanda (ROUND2-SPEC §2.3): stacked cards (no wide tables) so it reflows at 320 px; every status is icon + word. --}}
<x-filament-panels::page>
    @php
        $holders = $this->holders();
        $pending = $this->pending();
        $edit = $this->canEdit();
        $noElder = $this->stairwellsWithoutElder();
    @endphp

    <x-filament::section :heading="__('admin.team.holders')" icon="heroicon-o-shield-check">
        @if (count($holders) <= 1 && $pending === [])
            <p class="mb-3 text-sm" data-testid="team-empty">{{ __('admin.team.empty') }}</p>
        @endif

        <ul class="grid gap-3" data-testid="team-holders">
            @foreach ($holders as $h)
                <li class="rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="holder-{{ $h['id'] }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::badge :color="$h['role'] === \App\Enums\BuildingRoleType::BuildingAdmin ? 'primary' : 'gray'" icon="heroicon-o-key">
                            {{ $h['role']->label() }}
                        </x-filament::badge>
                        @if ($h['section'] !== null)
                            <span class="text-sm">{{ __('admin.section.short', ['n' => $h['section']]) }}</span>
                        @endif
                        @if ($h['self'])
                            <span class="text-sm text-gray-500">({{ __('admin.team.you') }})</span>
                        @endif
                    </div>
                    <p class="mt-1 break-all text-sm font-medium">{{ $h['holder'] }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        @if ($h['protected'])
                            <span class="inline-flex items-center gap-1 text-success-700 dark:text-success-400">
                                <x-filament::icon icon="heroicon-o-check-badge" class="size-4" />{{ __('admin.team.protected') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 text-warning-700 dark:text-warning-400">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-4" />{{ __('admin.team.no_second_factor') }}
                            </span>
                        @endif
                        <span>{{ __('admin.team.expires', ['date' => $h['expires']]) }}</span>
                    </div>
                    @if ($h['canReconfirm'] || $h['canRemove'])
                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($h['canReconfirm'])
                                {{ ($this->reconfirmAction)(['id' => $h['id']]) }}
                            @endif
                            @if ($h['canRemove'])
                                {{ ($this->removeAction)(['id' => $h['id'], 'self' => $h['self']]) }}
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($noElder !== [])
            <p class="mt-3 text-sm text-warning-700 dark:text-warning-400" data-testid="team-no-elder">
                {{ __('admin.team.no_elder', ['sections' => collect($noElder)->map(fn ($n) => __('admin.section.short', ['n' => $n]))->join(', ')]) }}
            </p>
        @endif

        @if ($url = $this->grantExistingUrl())
            <p class="mt-3 text-sm"><a href="{{ $url }}" class="text-primary-600 underline">{{ __('admin.team.grant_existing') }}</a></p>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('admin.team.pending')" icon="heroicon-o-envelope">
        @if ($pending === [])
            <p class="text-sm">{{ __('admin.team.pending_none') }}</p>
        @else
            <ul class="grid gap-3" data-testid="team-pending">
                @foreach ($pending as $p)
                    <li class="rounded-lg border border-gray-200 p-3 dark:border-white/10" wire:key="inv-{{ $p['id'] }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge color="warning" icon="heroicon-o-clock">{{ __('admin.team.waiting') }}</x-filament::badge>
                            <span class="text-sm font-medium">{{ $p['role']->label() }}</span>
                            @if ($p['sections'] !== '')
                                <span class="text-sm">{{ $p['sections'] }}</span>
                            @endif
                        </div>
                        <p class="mt-1 break-all text-sm">{{ $p['email'] }}</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('admin.team.sent', ['date' => $p['sent']]) }} · {{ trans_choice('admin.team.days_left', $p['days'], ['count' => $p['days']]) }}
                        </p>
                        @if ($p['canEdit'])
                            <div class="mt-2 flex flex-wrap gap-2">
                                {{ ($this->resendAction)(['id' => $p['id']]) }}
                                {{ ($this->revokeInviteAction)(['id' => $p['id']]) }}
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('admin.team.roles_help')" icon="heroicon-o-information-circle" collapsible>
        <dl class="grid gap-3 text-sm">
            @foreach (['building_admin', 'section_elder', 'parking_officer', 'volunteer'] as $r)
                <div>
                    <dt class="font-semibold">{{ __('enum.building_role.'.$r) }}</dt>
                    <dd>{{ __('admin.team.role_help.'.$r) }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>

</x-filament-panels::page>
