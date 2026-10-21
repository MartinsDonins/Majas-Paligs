{{-- /team-invite/{slug}#t=TOKEN — accept a team role (ROUND2-SPEC §2.2). The token is read from the fragment by
     Alpine (fragmentAction), the address bar is cleared, and the token goes to open() as an action argument. --}}
<div class="stack-lg" x-data="fragmentAction" data-param="t" data-method="open">
    <section class="stack text-center">
        <x-lucide-users class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
        <h1 class="page-title">{{ __('team.accept.title') }}</h1>
    </section>

    @if ($state === 'loading')
        <p class="card text-center muted" role="status" wire:loading.remove>{{ __('team.accept.loading') }}</p>
    @elseif ($state === 'invalid')
        <x-ui.banner variant="warning" icon="circle-alert" data-testid="invite-invalid">
            {{ $cooldown ? __('team.accept.cooldown') : __('team.accept.invalid') }}
        </x-ui.banner>
        <a href="{{ route('landing') }}" class="btn btn-block">{{ __('common.back_home') }}</a>
    @else
        <section class="card stack" aria-labelledby="invite-what-h" data-testid="invite-summary">
            <h2 id="invite-what-h" class="text-lg">{{ __('team.accept.what') }}</h2>
            <dl class="stack text-base">
                <div>
                    <dt class="field-label">{{ __('team.accept.building') }}</dt>
                    <dd>{{ $building->name }}</dd>
                </div>
                <div>
                    <dt class="field-label">{{ __('team.accept.role') }}</dt>
                    <dd>
                        <span class="chip chip-accent">
                            <x-lucide-shield-check class="size-4" aria-hidden="true" />
                            <span>{{ $roleLabel }}</span>
                        </span>
                        @if ($sections !== '')
                            <span class="muted">· {{ $sections }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="field-label">{{ __('team.accept.for_email') }}</dt>
                    <dd class="tnum">{{ $emailHint }}</dd>
                </div>
            </dl>
        </section>

        @if ($error)
            <x-ui.banner variant="warning" icon="circle-alert" role="alert">{{ $error }}</x-ui.banner>
        @endif

        @if ($state === 'guest')
            <section class="card stack">
                <p>{{ __('team.accept.confirm_email_lead') }}</p>
                <button type="button" class="btn btn-primary btn-block" wire:click="sendCode" data-requires-online wire:loading.attr="disabled" data-testid="invite-send-code">
                    <x-lucide-mail class="size-5" aria-hidden="true" />
                    {{ __('team.accept.confirm_email') }}
                </button>
            </section>
        @elseif ($state === 'code')
            <form wire:submit="verify" class="card stack" novalidate>
                <p>{{ __('team.accept.code_sent', ['email' => $emailHint]) }}</p>
                <x-ui.field id="invite-code" :label="__('auth.email.code_label')" :error="$errors->first('code')">
                    <input id="invite-code" type="text" wire:model="code" class="input input-code" inputmode="numeric" autocomplete="one-time-code"
                           maxlength="7" pattern="[0-9 ]*" placeholder="000 000">
                </x-ui.field>
                <label class="choice font-normal">
                    <input type="checkbox" wire:model="remember" class="size-5 shrink-0 accent-(--color-accent)">
                    <span>{{ __('auth.remember') }}</span>
                </label>
                <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled" data-testid="invite-verify">{{ __('common.continue') }}</button>
                <button type="button" class="btn-link" wire:click="sendCode">{{ __('team.accept.resend_code') }}</button>
            </form>
        @elseif ($state === 'confirm_new')
            <form wire:submit="createAccount" class="card stack" novalidate>
                <p>{{ __('team.accept.new_account') }}</p>
                <p class="text-sm">
                    <span class="font-semibold">{{ __('onboarding.notice.platform_controller') }}:</span>
                    {{ __('onboarding.notice.platform_scope') }}
                    <a href="{{ route('privacy') }}" target="_blank" rel="noopener">{{ __('onboarding.notice.read_platform') }}</a>
                </p>
                <label class="choice font-normal">
                    <input type="checkbox" wire:model="adult" class="size-5 shrink-0 accent-(--color-accent)" data-testid="invite-adult">
                    <span>{{ __('onboarding.role.adult') }}</span>
                </label>
                @error('adult') <span class="field-error" role="alert">{{ $message }}</span> @enderror
                <label class="choice font-normal">
                    <input type="checkbox" wire:model="notice" class="size-5 shrink-0 accent-(--color-accent)" data-testid="invite-notice">
                    <span>{{ __('team.accept.notice') }}</span>
                </label>
                @error('notice') <span class="field-error" role="alert">{{ $message }}</span> @enderror
                <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled" data-testid="invite-create">{{ __('team.accept.create') }}</button>
            </form>
        @elseif ($state === 'wrong_email')
            <x-ui.banner variant="warning" icon="circle-alert" data-testid="invite-wrong-email">
                {{ __('team.accept.wrong_email', ['email' => $emailHint]) }}
            </x-ui.banner>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-block">{{ __('common.logout') }}</button>
            </form>
        @elseif ($state === 'ready')
            <section class="card stack">
                <p>{{ __('team.accept.ready') }}</p>
                <button type="button" class="btn btn-primary btn-block" wire:click="accept" data-requires-online wire:loading.attr="disabled" data-testid="invite-accept">
                    <x-lucide-circle-check class="size-5" aria-hidden="true" />
                    {{ __('team.accept.accept') }}
                </button>
            </section>
        @endif
    @endif
</div>
