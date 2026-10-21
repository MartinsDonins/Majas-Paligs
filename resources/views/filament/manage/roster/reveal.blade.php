{{-- "Rādīt kontaktus" modal (ADMIN-007): unmasked for 5 minutes, tel: / mailto: links; audited by RosterViewer::reveal. --}}
@if ($result === null)
    <p class="text-sm">{{ __('roster.reveal.reopen') }}</p>
@elseif (isset($result['error']))
    <p class="text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $result['error'] }}</p>
@else
    <div x-data="{ shown: true }" x-init="setTimeout(() => shown = false, {{ (int) config('majas.roster.reveal_minutes', 5) }} * 60000)" class="grid gap-3" data-testid="roster-reveal">
        <template x-if="shown">
            <ul class="grid gap-3">
                @foreach ($result['contacts'] as $c)
                    <li class="grid gap-1 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                        <span class="font-semibold">{{ $c['name'] ?? __('roster.list.no_name') }} · {{ __('enum.contact_relation.'.$c['relation']) }}</span>
                        @foreach ($c['phones'] as $phone)
                            <a href="tel:{{ $phone }}" class="inline-flex min-h-12 items-center font-mono text-primary-600 underline dark:text-primary-400">{{ $phone }}</a>
                        @endforeach
                        @if ($c['email'])
                            <a href="mailto:{{ $c['email'] }}" class="inline-flex min-h-12 items-center text-primary-600 underline dark:text-primary-400">{{ $c['email'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </template>
        <p x-show="! shown" class="text-sm">{{ __('roster.reveal.hidden_again') }}</p>
    </div>
@endif
