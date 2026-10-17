<section class="card stack" aria-labelledby="queue-h">
    <h2 id="queue-h" class="text-lg">{{ __('elder.approvals.title') }}</h2>

    @if (session('elder.status'))
        <x-ui.banner variant="info" icon="circle-check">{{ __('elder.approvals.'.session('elder.status')) }}</x-ui.banner>
    @endif

    @forelse ($pending as $m)
        <article class="rounded-(--radius-md) border border-(--color-line) p-4" wire:key="m-{{ $m->id }}">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xl font-bold tnum">{{ __('onboarding.role.apartment', ['n' => $m->apartment->number]) }}</p>
                    <p class="text-sm muted">
                        {{ __('onboarding.role.section', ['n' => $m->apartment->section->number]) }} · {{ __('onboarding.role.floor', ['n' => $m->apartment->floor]) }}
                        · {{ $m->role->label() }}
                    </p>
                    <p class="text-xs muted">{{ __('elder.approvals.requested', ['date' => $m->created_at->timezone('Europe/Riga')->format('d.m.Y H:i')]) }}</p>
                </div>
                <x-ui.status-chip status="pending" size="sm" />
            </div>
            @if ($m->user_id !== $me)
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button type="button" wire:click="approve({{ $m->id }})" class="btn btn-primary">
                        <x-lucide-check class="size-5" aria-hidden="true" /> {{ __('elder.approvals.approve') }}
                    </button>
                    <button type="button" wire:click="reject({{ $m->id }})" wire:confirm="{{ __('elder.approvals.reject_confirm') }}" class="btn">
                        {{ __('elder.approvals.reject') }}
                    </button>
                </div>
            @else
                <p class="mt-2 text-sm muted">{{ __('elder.approvals.self') }}</p>
            @endif
        </article>
    @empty
        <x-ui.empty-state :title="__('elder.approvals.empty')" />
    @endforelse

    <x-ui.privacy-note>{{ __('elder.approvals.privacy') }}</x-ui.privacy-note>
</section>
