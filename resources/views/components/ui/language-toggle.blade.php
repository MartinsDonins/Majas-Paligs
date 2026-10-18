{{-- "LV | RU" text buttons (no flags). Plain POST forms: work without JavaScript, CSRF-protected. --}}
<div class="flex shrink-0 items-center rounded-full border border-(--color-line) bg-(--color-surface) p-1" role="group" aria-label="{{ __('shell.language') }}">
    @foreach (['lv' => 'LV', 'ru' => 'RU'] as $code => $label)
        <form method="POST" action="{{ route('locale.set', $code) }}">
            @csrf
            <button type="submit" lang="{{ $code }}"
                    class="min-h-12 min-w-12 rounded-full px-3 text-sm font-bold {{ app()->getLocale() === $code ? 'bg-(--color-accent) text-(--color-accent-ink)' : 'text-(--color-fg)' }}"
                    aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}"
                    title="{{ $code === 'lv' ? 'Latviešu' : 'Русский' }}">
                {{ $label }}
            </button>
        </form>
    @endforeach
</div>
