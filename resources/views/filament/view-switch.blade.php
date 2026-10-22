{{-- "Iedzīvotāja skats | Admin skats" in both Filament panels (FIRSTRUN-001): the building panel links to the same
     house in the resident app, the platform panel to /home. Only for someone who can administer something. --}}
@php($viewSwitchUser = auth()->user())
@if (\App\Support\ViewSwitch::canSwitch($viewSwitchUser))
    <div class="me-2" data-testid="panel-view-switch">
        <x-ui.view-switch current="admin" :building="\Filament\Facades\Filament::getTenant()" compact :icons="false" />
    </div>
@endif
