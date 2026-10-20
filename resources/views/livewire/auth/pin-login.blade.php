<div class="stack-lg">
    <section class="stack">
        <h1 class="page-title">{{ __('auth.pin.title') }}</h1>
        <p class="lead">{{ __('auth.pin.lead') }}</p>
    </section>

    @if ($cooldown)
        <section class="card stack text-center" role="status">
            <x-lucide-hourglass class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
            <h2 class="text-xl">{{ __('auth.pin.cooldown_title') }}</h2>
            <p class="muted">{{ __('auth.pin.cooldown_body', ['minutes' => $cooldownMinutes]) }}</p>
        </section>
    @else
        @if ($failed)
            <x-ui.banner variant="danger" icon="circle-alert" role="alert">
                <p data-testid="pin-failed">{{ __('auth.pin.failed') }}</p>
            </x-ui.banner>
        @endif

        <form wire:submit="login" class="card stack" novalidate x-data="revealToggle">
            <x-ui.field id="handle" :label="__('auth.pin.handle_label')" :help="__('auth.pin.handle_help')">
                <input id="handle" type="text" wire:model="handle" class="input input-code" autocomplete="username"
                       autocapitalize="characters" spellcheck="false" maxlength="12" placeholder="145-K7MQ" aria-describedby="handle-help">
            </x-ui.field>
            <x-ui.field id="pin" :label="__('auth.pin.pin_label')">
                <input id="pin" x-bind:type="inputType" wire:model="pin" class="input input-code" inputmode="numeric"
                       autocomplete="current-password" maxlength="8" pattern="[0-9A-Za-z\-]*">
            </x-ui.field>
            <button type="button" class="btn-link self-start" x-on:click="toggle">
                <x-lucide-eye class="inline size-4" aria-hidden="true" /> {{ __('auth.pin.show') }}
            </button>
            <label class="choice font-normal">
                <input type="checkbox" wire:model="remember" class="size-5 accent-(--color-accent)">
                <span>{{ __('auth.remember') }}</span>
            </label>
            <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">{{ __('auth.login') }}</button>
        </form>

        <p class="text-sm muted">{{ __('auth.pin.forgot') }}</p>
    @endif

    <a href="{{ route('login') }}" class="btn btn-block">{{ __('landing.login_email') }}</a>
</div>
