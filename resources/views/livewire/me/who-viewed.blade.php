<section class="card stack" aria-labelledby="who-viewed-h">
    <h2 id="who-viewed-h" class="text-lg">{{ __('privacy.who_viewed.title') }}</h2>
    <p class="text-sm muted">{{ __('privacy.who_viewed.lead') }}</p>
    @if ($entries->isEmpty())
        <p class="muted">{{ __('privacy.who_viewed.empty') }}</p>
    @else
        <ul>
            @foreach ($entries as $e)
                <li class="list-row items-start">
                    <div>
                        <p class="font-semibold">{{ __('audit.action.'.$e->action) }}</p>
                        <p class="text-sm muted">
                            {{ $e->actorDeleted ? __('privacy.who_viewed.deleted_user') : ($e->actorRole ? __('audit.actor.'.$e->actorRole) : __('audit.actor.system')) }}
                            @if ($e->buildingName) · {{ $e->buildingName }} @endif
                        </p>
                    </div>
                    <time class="shrink-0 text-sm tnum muted" datetime="{{ $e->createdAt->toIso8601String() }}">{{ $e->createdAt->timezone('Europe/Riga')->format('d.m.Y H:i') }}</time>
                </li>
            @endforeach
        </ul>
        @if ($entries->count() >= 30)
            <button type="button" wire:click="more({{ $entries->last()->id }})" class="btn btn-block">{{ __('common.show_more') }}</button>
        @endif
    @endif
</section>
