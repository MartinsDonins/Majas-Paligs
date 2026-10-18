<div class="stack-lg">
    <section>
        <h1 class="page-title">{{ __('elder.title') }}</h1>
        <p class="lead">{{ __('elder.lead') }}</p>
    </section>

    <nav class="grid grid-cols-3 gap-2" aria-label="{{ __('elder.title') }}">
        @foreach ($tabs as $key => $icon)
            <button type="button" wire:click="show('{{ $key }}')" class="choice flex-col justify-center gap-1 px-2 text-center text-sm" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">
                <x-dynamic-component :component="'lucide-'.$icon" class="size-6" aria-hidden="true" />
                {{ __('elder.tabs.'.$key) }}
            </button>
        @endforeach
    </nav>

    @if ($tab === 'approvals')
        <livewire:elder.approval-queue />
    @elseif ($tab === 'assisted')
        <livewire:elder.assisted-onboarding />
    @elseif ($tab === 'pin')
        <livewire:elder.pin-reset />
    @elseif ($tab === 'proxy')
        <livewire:elder.proxy-report />
    @elseif ($tab === 'notice')
        <livewire:elder.notice-composer />
    @elseif ($tab === 'digest')
        <livewire:share.digest-card />
    @endif

    <p class="text-sm muted flex items-center gap-2"><x-lucide-history class="size-4" aria-hidden="true" /> {{ __('elder.logged') }}</p>
</div>
