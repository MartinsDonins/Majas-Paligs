{{--
    "Rādīt kontaktus" modal (ADMIN-007): unmasked values with tel: / mailto: links, audited by RosterViewer::reveal.
    Plain markup on purpose (the panel runs the CSP build of Alpine): the values exist only in this modal's HTML,
    never in component state, and disappear when the modal closes.
--}}
@if ($result === null)
    <p class="text-sm">{{ __('roster.reveal.reopen') }}</p>
@elseif (isset($result['error']))
    <p class="text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $result['error'] }}</p>
@else
    <ul class="grid gap-3" data-testid="roster-reveal">
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
@endif
