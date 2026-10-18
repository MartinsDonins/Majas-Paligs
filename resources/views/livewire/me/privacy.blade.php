<div class="stack-lg">
    <h1 class="page-title">{{ __('privacy.title') }}</h1>
    <p class="lead">{{ __('privacy.lead') }}</p>

    @if (session('status') === 'export_queued')
        <x-ui.banner variant="info" icon="clock">{{ __('privacy.export.queued') }}</x-ui.banner>
    @elseif (session('status') === 'export_limit')
        <x-ui.banner variant="warning" icon="hourglass">{{ __('privacy.export.limit') }}</x-ui.banner>
    @endif

    <section class="card stack" aria-labelledby="exp-h">
        <h2 id="exp-h" class="text-lg">{{ __('privacy.export.title') }}</h2>
        <p class="text-sm muted">{{ __('privacy.export.body', ['count' => $memberships->pluck('buildingId')->unique()->count()]) }}</p>
        @if ($download)
            <a href="{{ $download }}" class="btn btn-primary btn-block"><x-lucide-download class="size-5" aria-hidden="true" /> {{ __('privacy.export.download') }}</a>
            <p class="text-xs muted">{{ __('privacy.export.link_valid') }}</p>
        @elseif ($latest && $latest->status->value === 'queued')
            <p class="chip">{{ __('privacy.export.preparing') }}</p>
        @endif
        <button type="button" wire:click="requestExport" class="btn btn-block">{{ __('privacy.export.request') }}</button>
    </section>

    <livewire:me.who-viewed />

    <section class="card stack" aria-labelledby="del-h">
        <h2 id="del-h" class="text-lg">{{ __('privacy.delete.title') }}</h2>
        <p class="text-sm muted">{{ __('privacy.delete.body') }}</p>
        @if (! $confirmDelete)
            <button type="button" wire:click="askDelete" class="btn btn-danger btn-block"><x-lucide-trash-2 class="size-5" aria-hidden="true" /> {{ __('privacy.delete.button') }}</button>
        @else
            <form wire:submit="delete" class="stack" role="alertdialog" aria-labelledby="del-confirm-h" tabindex="-1" x-data="focusOnShow">
                <p id="del-confirm-h" class="font-semibold">{{ __('privacy.delete.confirm_title') }}</p>
                <x-ui.field id="deleteWord" :label="__('privacy.delete.type_word', ['word' => mb_strtoupper(__('privacy.delete.word'))])" :error="$errors->first('deleteWord')">
                    <input id="deleteWord" wire:model="deleteWord" class="input" autocomplete="off" autocapitalize="characters">
                </x-ui.field>
                <button type="button" wire:click="cancelDelete" class="btn btn-block">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-danger btn-block">{{ __('privacy.delete.confirm') }}</button>
            </form>
        @endif
    </section>
</div>
