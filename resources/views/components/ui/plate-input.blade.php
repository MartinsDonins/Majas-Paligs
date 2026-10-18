@props(['id' => 'plate', 'label' => null, 'help' => null, 'error' => null])
{{--
    Plate input (DESIGN-SYSTEM §5): 64px, mono/tnum, uppercase, autocomplete off; spaces, dashes and Cyrillic
    look-alikes are accepted and normalised on the server. NOT wire:model-bound: the enclosing x-data="plateForm"
    sends the value as an action argument and clears the field (no plate in the Livewire snapshot).
--}}
<x-ui.field :id="$id" :label="$label ?? __('parking.plate.label')" :help="$help ?? __('parking.plate.help')" :error="$error">
    <input id="{{ $id }}" type="text" data-plate class="plate-input" inputmode="text" maxlength="16"
           autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false"
           placeholder="{{ __('parking.plate.placeholder') }}" required
           aria-describedby="{{ $id }}-help{{ $error ? ' '.$id.'-error' : '' }}" @if($error) aria-invalid="true" @endif
           {{ $attributes }}>
</x-ui.field>
