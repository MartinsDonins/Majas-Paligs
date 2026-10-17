@props(['title', 'action' => null, 'href' => null])
{{-- Line illustration of a 602 facade + one sentence + one CTA (DESIGN-SYSTEM §5). --}}
<div class="card flex flex-col items-center gap-4 py-8 text-center">
    <svg viewBox="0 0 120 80" class="h-20 w-32 text-(--color-fg-muted)" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <rect x="20" y="6" width="80" height="70" rx="2"/>
        @for ($r = 0; $r < 5; $r++)
            @for ($c = 0; $c < 6; $c++)
                <rect x="{{ 27 + $c * 12 }}" y="{{ 12 + $r * 12 }}" width="6" height="5" rx="1"/>
            @endfor
        @endfor
        <path d="M56 76v-8h8v8"/>
    </svg>
    <p class="max-w-sm">{{ $title }}</p>
    @if ($action && $href)
        <a href="{{ $href }}" class="btn btn-primary">{{ $action }}</a>
    @endif
    {{ $slot }}
</div>
