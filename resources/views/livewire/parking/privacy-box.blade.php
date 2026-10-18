<div>
    <x-ui.privacy-note>
        <ul class="list-disc space-y-1 ps-5">
            @if ($context === 'lookup')
                <li>{{ __('parking.privacy.lookup_answer') }}</li>
                <li>{{ __('parking.privacy.lookup_logged') }}</li>
            @elseif ($context === 'sighting')
                <li>{{ __('parking.privacy.sighting_who') }}</li>
                <li>{{ __('parking.privacy.sighting_photo') }}</li>
            @else
                <li>{{ __('parking.privacy.plate_who') }}</li>
                <li>{{ __('parking.privacy.plate_encrypted') }}</li>
            @endif
            <li>{{ __('parking.privacy.reveal_logged') }}</li>
        </ul>
    </x-ui.privacy-note>
</div>
