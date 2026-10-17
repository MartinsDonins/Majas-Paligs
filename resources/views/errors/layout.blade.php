<x-layouts::guest :title="__('errors.'.$code.'.title')">
    <section class="card stack mt-6 text-center" role="alert">
        <x-dynamic-component :component="'lucide-'.$icon" class="mx-auto size-12 text-(--color-accent)" aria-hidden="true" />
        <h1 class="text-2xl">{{ __('errors.'.$code.'.title') }}</h1>
        <p class="muted">{{ __('errors.'.$code.'.body') }}</p>
        <a href="{{ url('/') }}" class="btn btn-primary btn-block">{{ __('common.back_home') }}</a>
    </section>
</x-layouts::guest>
