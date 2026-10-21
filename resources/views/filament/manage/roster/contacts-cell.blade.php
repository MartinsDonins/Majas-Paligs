{{-- Roster list cell (ADMIN-007): the apartment's contacts, stacked; phones and e-mails MASKED. --}}
@php
    /** @var \App\Models\Apartment $apartment */
    $apartment = $getRecord();
    $viewer = app(\App\Services\Roster\RosterViewer::class);
    $contacts = $apartment->residentContacts->sortBy('created_at');
@endphp
<div class="grid gap-2 py-1" data-testid="roster-contacts">
    @forelse ($contacts as $contact)
        @php($m = $viewer->masked($contact))
        <div @class(['grid gap-1 text-sm', 'opacity-60' => $m['moved_out']])>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="font-semibold">{{ $m['name'] ?? __('roster.list.no_name') }}</span>
                <x-filament::badge color="gray" size="sm">{{ __('enum.contact_relation.'.$m['relation']) }}</x-filament::badge>
                @if ($m['moved_out'])
                    <x-filament::badge color="gray" size="sm" icon="heroicon-o-arrow-right-start-on-rectangle">{{ __('roster.list.moved_out') }}</x-filament::badge>
                @endif
            </div>
            @foreach ($m['phones'] as $phone)
                <span class="font-mono tabular-nums" aria-label="{{ __('roster.list.masked_phone_sr', ['last' => $phone['last']]) }}">{{ $phone['mask'] }}</span>
            @endforeach
            @if ($m['email'])
                <span class="font-mono" aria-label="{{ __('roster.list.masked_email_sr') }}">{{ $m['email'] }}</span>
            @endif
            <div class="flex flex-wrap items-center gap-1">
                <x-filament::badge size="sm" :color="$m['bis'] === true ? 'success' : ($m['bis'] === false ? 'danger' : 'gray')"
                    :icon="$m['bis'] === true ? 'heroicon-o-check' : ($m['bis'] === false ? 'heroicon-o-x-mark' : 'heroicon-o-question-mark-circle')">
                    {{ __('roster.list.bis_'.($m['bis'] === null ? 'unknown' : ($m['bis'] ? 'yes' : 'no'))) }}
                </x-filament::badge>
                <x-filament::badge size="sm" :color="$m['whatsapp'] === true ? 'success' : ($m['whatsapp'] === false ? 'danger' : 'gray')"
                    :icon="$m['whatsapp'] === true ? 'heroicon-o-check' : ($m['whatsapp'] === false ? 'heroicon-o-x-mark' : 'heroicon-o-question-mark-circle')">
                    {{ __('roster.list.whatsapp_'.($m['whatsapp'] === null ? 'unknown' : ($m['whatsapp'] ? 'yes' : 'no'))) }}
                </x-filament::badge>
                @if ($m['comment'])
                    <x-filament::badge size="sm" color="info" icon="heroicon-o-chat-bubble-left-ellipsis">{{ __('roster.list.has_comment') }}</x-filament::badge>
                @endif
                @if ($m['reviewed'])
                    <x-filament::badge size="sm" color="success" icon="heroicon-o-check-badge">{{ __('roster.list.reviewed') }}</x-filament::badge>
                @endif
                @if ($m['linked'])
                    <x-filament::badge size="sm" color="info" icon="heroicon-o-link">{{ __('roster.list.linked') }}</x-filament::badge>
                @endif
                @can('update', $contact)
                    <x-filament::link :href="\App\Filament\Manage\Resources\ResidentContactResource::getUrl('edit', ['record' => $contact->id])" size="sm" icon="heroicon-o-pencil-square">
                        {{ __('roster.list.edit') }}
                    </x-filament::link>
                @endcan
            </div>
        </div>
    @empty
        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('roster.list.no_contact') }}</span>
    @endforelse
</div>
