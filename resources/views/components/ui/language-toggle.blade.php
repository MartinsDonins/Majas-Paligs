{{-- "LV | RU" text buttons (no flags). Plain POST forms: work without JavaScript, CSRF-protected. `compact` = the
     Filament panels' top bar (36px targets next to the user menu). --}}
@props(['compact' => false])
<div class="flex shrink-0 items-center rounded-full border border-(--color-line) bg-(--color-surface) p-1" role="group" aria-label="{{ __('shell.language') }}">
    @foreach (['lv' => 'LV', 'ru' => 'RU'] as $code => $label)
        <form method="POST" action="{{ route('locale.set', $code) }}" data-locale-form>
            @csrf
            <input type="hidden" name="return" value="{{ \App\Support\SafeReturn::current() }}">
            <button type="submit" lang="{{ $code }}"
                    class="{{ $compact ? 'min-h-9 min-w-9 px-2' : 'min-h-12 min-w-12 px-3' }} rounded-full text-sm font-bold {{ app()->getLocale() === $code ? 'bg-(--color-accent) text-(--color-accent-ink)' : 'text-(--color-fg)' }}"
                    aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}"
                    title="{{ $code === 'lv' ? 'Latviešu' : 'Русский' }}">
                {{ $label }}
            </button>
        </form>
    @endforeach
</div>
