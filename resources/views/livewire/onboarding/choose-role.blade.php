<div class="stack-lg">
    <x-ui.progress-dots :step="3" />

    @if ($closed || ! $resolved)
        <section class="card stack text-center" role="status">
            <x-lucide-clock class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
            <h1 class="text-xl">{{ __('onboarding.closed.title') }}</h1>
            <p class="muted">{{ __('onboarding.closed.body') }}</p>
            <a href="{{ route('landing') }}" class="btn btn-block">{{ __('common.back_home') }}</a>
        </section>
    @else
        <section class="stack">
            <h1 class="page-title">{{ __('onboarding.role.title') }}</h1>
            <div class="card stack">
                <p class="text-lg font-bold">
                    {{ $building->name }} · {{ __('onboarding.role.section', ['n' => $resolved->sectionNumber]) }}
                </p>
                <p class="text-2xl font-bold tnum">{{ __('onboarding.role.apartment', ['n' => $resolved->apartmentNumber]) }}
                    <span class="text-base font-semibold muted">· {{ __('onboarding.role.floor', ['n' => $resolved->floor]) }}</span>
                </p>
                @if ($section)
                    <div class="mx-auto w-full max-w-xs">
                        <x-ui.building-grid :section="$section" :highlight="$resolved->apartmentNumber" :compact="true" />
                    </div>
                @endif
                <p class="text-sm muted">{{ __('onboarding.role.not_yours') }}</p>
            </div>
        </section>

        <section class="card stack" aria-labelledby="who-h">
            <h2 id="who-h" class="flex items-center gap-2 text-lg">
                <x-lucide-shield-check class="size-5 text-(--color-accent)" aria-hidden="true" />
                {{ __('onboarding.notice.title') }}
            </h2>
            <ul class="stack text-sm">
                <li>
                    <span class="font-semibold">{{ __('onboarding.notice.house_controller') }}:</span>
                    {{ $controller['name'] ?? '—' }}
                    <a href="{{ route('building.privacy', $building) }}" target="_blank" rel="noopener">{{ __('onboarding.notice.read_house') }}</a>
                </li>
                <li>
                    <span class="font-semibold">{{ __('onboarding.notice.platform_controller') }}:</span>
                    {{ __('onboarding.notice.platform_scope') }}
                    <a href="{{ route('privacy') }}" target="_blank" rel="noopener">{{ __('onboarding.notice.read_platform') }}</a>
                </li>
            </ul>
            <p class="text-sm muted">{{ __('onboarding.notice.minimal') }}</p>
        </section>

        <form wire:submit="choose" class="stack" novalidate>
            <fieldset class="stack">
                <legend class="field-label text-lg">{{ __('onboarding.role.question') }}</legend>
                @foreach (['owner', 'tenant', 'family'] as $r)
                    <label class="choice">
                        <input type="radio" wire:model="role" name="role" value="{{ $r }}" class="size-5 accent-(--color-accent)">
                        <span class="flex-1">{{ __('enum.membership_role.'.$r) }}</span>
                    </label>
                @endforeach
                @error('role') <span class="field-error" role="alert">{{ $message }}</span> @enderror
            </fieldset>

            <label class="choice font-normal">
                <input type="checkbox" wire:model="adult" class="size-5 shrink-0 accent-(--color-accent)">
                <span>{{ __('onboarding.role.adult') }}</span>
            </label>
            @error('adult') <span class="field-error" role="alert">{{ $message }}</span> @enderror

            <label class="choice font-normal">
                <input type="checkbox" wire:model="noticesAccepted" class="size-5 shrink-0 accent-(--color-accent)">
                <span>{{ __('onboarding.role.notices') }}</span>
            </label>
            @error('noticesAccepted') <span class="field-error" role="alert">{{ $message }}</span> @enderror

            <button type="submit" class="btn btn-primary btn-block" data-requires-online wire:loading.attr="disabled">
                {{ __('common.continue') }}
                <x-lucide-chevron-right class="size-5" aria-hidden="true" />
            </button>
        </form>
    @endif
</div>
