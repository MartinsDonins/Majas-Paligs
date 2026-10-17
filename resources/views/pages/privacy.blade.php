<x-layouts::guest :title="__('privacy_page.title')">
    <article class="stack-lg">
        <h1 class="page-title">{{ __('privacy_page.title') }}</h1>

        @if ($building)
            <section class="card stack" aria-labelledby="house-notice-h">
                <h2 id="house-notice-h" class="text-xl">{{ __('privacy_page.house', ['building' => $building->name]) }}</h2>
                @if ($buildingText)
                    <div class="whitespace-pre-line">{{ $buildingText }}</div>
                @else
                    <p class="muted">{{ __('privacy_page.house_missing') }}</p>
                @endif
            </section>
        @endif

        <section class="card stack" aria-labelledby="platform-notice-h">
            <h2 id="platform-notice-h" class="text-xl">{{ __('privacy_page.platform') }}</h2>
            @if ($platformText)
                <div class="whitespace-pre-line">{{ $platformText }}</div>
                <p class="text-xs muted">{{ __('privacy_page.version', ['version' => $platformVersion]) }}</p>
            @else
                <p class="muted">{{ __('privacy_page.platform_missing') }}</p>
            @endif
        </section>

        <section class="card stack">
            <h2 class="text-lg">{{ __('privacy_page.rights_title') }}</h2>
            <p>{{ __('privacy_page.rights_body') }}</p>
        </section>
    </article>
</x-layouts::guest>
