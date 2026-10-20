<div class="stack-lg">
    <x-ui.progress-dots :step="2" />

    @if (session('status') === 'no_apartment')
        <x-ui.banner variant="info">{{ __('onboarding.no_apartment') }}</x-ui.banner>
    @elseif (session('status') === 'membership_rejected')
        <x-ui.banner variant="warning" icon="circle-alert" data-testid="membership-rejected">
            <p>{{ __('onboarding.membership_rejected', ['building' => session('membership_building'), 'apartment' => session('membership_apartment')]) }}</p>
            @if (filled(session('membership_reason')))
                <p class="text-sm">{{ __('onboarding.membership_reason', ['reason' => session('membership_reason')]) }}</p>
            @endif
            <p class="text-sm">{{ __('onboarding.membership_contact') }}</p>
        </x-ui.banner>
    @elseif (session('status') === 'membership_ended')
        <x-ui.banner variant="info" data-testid="membership-ended">
            <p>{{ __('onboarding.membership_ended', ['building' => session('membership_building'), 'apartment' => session('membership_apartment')]) }}</p>
            <p class="text-sm">{{ __('onboarding.membership_contact') }}</p>
        </x-ui.banner>
    @endif

    @if ($closed)
        <section class="card stack text-center" role="status">
            <x-lucide-clock class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
            <h1 class="text-xl">{{ __('onboarding.closed.title') }}</h1>
            <p class="muted">{{ __('onboarding.closed.body') }}</p>
            <a href="{{ route('landing') }}" class="btn btn-block">{{ __('common.back_home') }}</a>
        </section>
    @elseif ($cooldown)
        <section class="card stack text-center" role="status">
            <x-lucide-hourglass class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
            <h1 class="text-xl">{{ __('onboarding.cooldown.title') }}</h1>
            <p class="muted">{{ __('onboarding.cooldown.body', ['minutes' => $cooldownMinutes]) }}</p>
        </section>
    @else
        <section class="stack">
            <h1 class="page-title">{{ __('onboarding.code.title') }}</h1>
            <p class="lead">{{ __('onboarding.code.lead') }}</p>
        </section>

        <form wire:submit="submit" class="card stack" novalidate>
            <div x-data="fragmentSecret" data-param="c" data-target="code" data-livewire="true">
                <x-ui.field id="code" :label="__('onboarding.code.label')" :help="__('onboarding.code.help')" :error="$error">
                    <input id="code" type="text" wire:model="code" name="code"
                           class="input input-code" inputmode="text" autocomplete="one-time-code" autocapitalize="characters"
                           spellcheck="false" maxlength="24" placeholder="XXXXX-XXXXX"
                           aria-describedby="code-help{{ $error ? ' code-error' : '' }}" @if($error) aria-invalid="true" @endif>
                </x-ui.field>
            </div>
            <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
                {{ __('common.continue') }}
                <x-lucide-chevron-right class="size-5" aria-hidden="true" />
            </button>
        </form>

        <x-ui.banner variant="info" icon="shield-check">
            <p class="text-sm">{{ __('onboarding.code.trust') }}</p>
        </x-ui.banner>

        <p class="text-sm muted">{{ __('onboarding.code.no_letter') }}</p>
    @endif
</div>
