<div class="stack-lg">
    <h1 class="page-title">{{ __('me.title') }}</h1>

    @if (session('status') === 'devices_forgotten')
        <x-ui.banner variant="info" icon="circle-check">{{ __('me.devices.forgotten') }}</x-ui.banner>
    @endif
    @if (in_array(session('status'), ['self_linked', 'self_requested'], true))
        <x-ui.banner variant="info" icon="circle-check" data-testid="self-link-done">{{ __('start.link.done.'.session('status')) }}</x-ui.banner>
    @endif

    {{-- FIRSTRUN-001: an administrator without an apartment here links himself (no letter needed). --}}
    @if ($canLinkSelf)
        @if ($houseOpen)
            <livewire:me.link-myself />
        @else
            <section class="card stack" aria-labelledby="link-closed-h" data-testid="link-closed">
                <h2 id="link-closed-h" class="text-lg">{{ __('start.link.title') }}</h2>
                <p class="text-sm">{{ __('start.house.link_closed') }}</p>
                @if ($isAdmin)
                    <a href="{{ $wizardUrl }}" class="btn btn-primary btn-block"><x-lucide-list-checks class="size-5" aria-hidden="true" /> {{ __('start.house.continue_opening') }}</a>
                @endif
            </section>
        @endif
    @endif

    @if ($isHelper || $canManage)
        <section class="card stack" aria-labelledby="tools-me-h">
            <h2 id="tools-me-h" class="text-lg">{{ __('home.tools.title') }}</h2>
            @if ($isHelper)
                <a href="{{ route('building.elder', $building) }}" class="list-row no-underline text-(--color-fg)">
                    <span class="flex items-center gap-3">
                        <x-lucide-hand-helping class="size-6 text-(--color-accent)" aria-hidden="true" />
                        <span class="font-semibold">{{ __('home.tools.helper') }}</span>
                    </span>
                    <span class="flex items-center gap-2">
                        @if ($pendingForMe > 0)
                            <span class="chip chip-warning tnum">{{ trans_choice('home.tools.pending', $pendingForMe, ['count' => $pendingForMe]) }}</span>
                        @endif
                        <x-lucide-chevron-right class="size-5" aria-hidden="true" />
                    </span>
                </a>
            @endif
            @if ($canManage)
                {{-- "Administrēšana" → /admin (ROUND2-SPEC §1.1): every house the person administers + the platform. --}}
                <a href="{{ route('admin.entry') }}" class="list-row no-underline text-(--color-fg)" data-testid="more-admin">
                    <span class="flex items-center gap-3">
                        <x-lucide-shield-check class="size-6 text-(--color-accent)" aria-hidden="true" />
                        <span class="font-semibold">{{ __('home.tools.administration') }}</span>
                    </span>
                    <x-lucide-chevron-right class="size-5" aria-hidden="true" />
                </a>
            @endif
        </section>
    @endif

    <section class="card stack" aria-labelledby="apt-h">
        <h2 id="apt-h" class="text-lg">{{ __('me.apartment.title') }}</h2>
        @if ($apartment)
            <p class="text-2xl font-bold tnum">{{ __('onboarding.role.apartment', ['n' => $apartment->number]) }}</p>
            <p class="muted">{{ $building->name }} · {{ __('onboarding.role.section', ['n' => $apartment->section->number]) }} · {{ __('onboarding.role.floor', ['n' => $apartment->floor]) }}</p>
            <div>
                <h3 class="field-label">{{ __('me.apartment.members') }}</h3>
                <ul>
                    @foreach ($members as $m)
                        <li class="list-row">
                            <span>
                                {{ $m->user_id === $user->id ? __('me.apartment.you') : __('me.apartment.neighbour') }}
                                · {{ $m->role->label() }}
                            </span>
                            <x-ui.status-chip :status="$m->status->value" size="sm" />
                        </li>
                    @endforeach
                </ul>
                <p class="text-sm muted">{{ __('me.apartment.members_hint') }}</p>
            </div>
            {{-- A building_admin's own request: he cannot approve it himself — say who can, with the way to invite. --}}
            @if ($membership?->isPending() && $isAdmin)
                <x-ui.banner variant="info" icon="info" data-testid="self-pending">
                    <p class="text-sm">{{ __('start.self.who_approves') }}</p>
                    <a href="{{ $teamUrl }}" class="btn mt-2">
                        <x-lucide-user-plus class="size-5" aria-hidden="true" /> {{ __('start.self.invite_elder', ['n' => $apartment->section->number]) }}
                    </a>
                </x-ui.banner>
            @endif
        @else
            <p class="muted">{{ __('me.apartment.none') }}</p>
        @endif

        @if ($roles->isNotEmpty())
            <div>
                <h3 class="field-label">{{ __('me.roles.title') }}</h3>
                <ul>
                    @foreach ($roles as $r)
                        <li class="list-row">
                            <span>{{ $r['role']->label() }}</span>
                            <span class="text-sm muted">{{ __('me.roles.until', ['date' => $r['expires']->timezone('Europe/Riga')->format('d.m.Y')]) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    <section class="card stack" aria-labelledby="view-h">
        <h2 id="view-h" class="text-lg">{{ __('me.display.title') }}</h2>
        <div>
            <p class="field-label">{{ __('me.display.language') }}</p>
            <x-ui.language-toggle />
        </div>
        <div>
            <p class="field-label">{{ __('me.display.text_size') }}</p>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" wire:click="setTextSize('normal')" class="choice justify-center" aria-pressed="{{ $user->text_size->value === 'normal' ? 'true' : 'false' }}">{{ __('enum.text_size.normal') }}</button>
                <button type="button" wire:click="setTextSize('large')" class="choice justify-center" aria-pressed="{{ $user->text_size->value === 'large' ? 'true' : 'false' }}">{{ __('enum.text_size.large') }}</button>
            </div>
        </div>
        <div>
            <p class="field-label">{{ __('me.display.theme') }}</p>
            <div class="grid grid-cols-3 gap-2">
                @foreach (['system' => 'monitor', 'light' => 'sun', 'dark' => 'moon'] as $theme => $icon)
                    <button type="button" wire:click="setTheme('{{ $theme }}')" class="choice flex-col justify-center gap-1 text-sm" aria-pressed="{{ $user->theme->value === $theme ? 'true' : 'false' }}">
                        <x-dynamic-component :component="'lucide-'.$icon" class="size-5" aria-hidden="true" />
                        {{ __('enum.theme.'.$theme) }}
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    <section class="card stack" aria-labelledby="dev-h">
        <h2 id="dev-h" class="text-lg">{{ __('me.devices.title') }}</h2>
        <p class="text-sm muted">{{ __('me.devices.body') }}</p>
        <button type="button" wire:click="forgetDevices" wire:confirm="{{ __('me.devices.confirm') }}" class="btn btn-block">
            <x-lucide-smartphone class="size-5" aria-hidden="true" /> {{ __('me.devices.forget') }}
        </button>
        @if ($user->login_handle)
            <p class="text-sm">{{ __('me.devices.handle') }}: <span class="font-bold tnum">{{ $user->login_handle }}</span></p>
        @endif
    </section>

    <section class="card stack" aria-labelledby="priv-h">
        <h2 id="priv-h" class="text-lg">{{ __('me.privacy.title') }}</h2>
        <a href="{{ route('me.privacy') }}" class="list-row no-underline text-(--color-fg)">
            <span class="flex items-center gap-3"><x-lucide-shield-check class="size-5 text-(--color-accent)" aria-hidden="true" /> {{ __('me.privacy.link') }}</span>
            <x-lucide-chevron-right class="size-5" aria-hidden="true" />
        </a>
        {{-- Everyone may add a passkey (fingerprint / Face ID) here; administrators also manage trusted devices / TOTP. --}}
        <a href="{{ route('me.security') }}" class="list-row no-underline text-(--color-fg)">
            <span class="flex items-center gap-3"><x-lucide-fingerprint class="size-5 text-(--color-accent)" aria-hidden="true" /> {{ __('me.security.link') }}</span>
            <x-lucide-chevron-right class="size-5" aria-hidden="true" />
        </a>
    </section>

    <section class="stack">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-block"><x-lucide-log-out class="size-5" aria-hidden="true" /> {{ __('common.logout') }}</button>
        </form>
        @if ($membership)
            @if (! $confirmLeave)
                <button type="button" wire:click="askLeave" class="btn btn-danger btn-block">{{ __('me.leave.button') }}</button>
            @else
                <div class="card stack" role="alertdialog" aria-labelledby="leave-h" tabindex="-1" x-data="focusOnShow">
                    <h2 id="leave-h" class="text-lg">{{ __('me.leave.confirm_title') }}</h2>
                    <p class="muted">{{ __('me.leave.confirm_body') }}</p>
                    <button type="button" wire:click="cancelLeave" class="btn btn-block">{{ __('common.cancel') }}</button>
                    <button type="button" wire:click="leave" class="btn btn-danger btn-block">{{ __('me.leave.confirm') }}</button>
                </div>
            @endif
        @endif
    </section>
</div>
