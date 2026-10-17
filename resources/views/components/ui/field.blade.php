@props(['id', 'label', 'help' => null, 'error' => null])
{{-- Label always above, helper text, error under the field with icon + aria-describedby (DESIGN-SYSTEM §5). --}}
<div class="field">
    <label for="{{ $id }}" class="field-label">{{ $label }}</label>
    {{ $slot }}
    @if ($help)
        <span id="{{ $id }}-help" class="field-help">{{ $help }}</span>
    @endif
    @if ($error)
        <span id="{{ $id }}-error" class="field-error" role="alert">
            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ $error }}</span>
        </span>
    @endif
</div>
