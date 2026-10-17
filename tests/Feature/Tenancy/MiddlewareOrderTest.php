<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureBuildingRole;
use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\EnsurePinChanged;
use App\Http\Middleware\RequireStepUp;
use App\Http\Middleware\SetCurrentBuilding;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

/*
 * Binding order for every /b/{building:slug} route: auth → SetCurrentBuilding → member → role/step-up.
 */
it('orders auth → SetCurrentBuilding → member → role / step-up on every resident route', function (): void {
    $routes = collect(RouteFacade::getRoutes()->getRoutes())->filter(fn (Route $r) => str_starts_with($r->uri(), 'b/{building'));
    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $mw = array_values(array_map(
            fn ($m) => is_string($m) ? explode(':', $m)[0] : $m,
            app('router')->gatherRouteMiddleware($route),
        ));

        $auth = array_search(Authenticate::class, $mw, true);
        $tenant = array_search(SetCurrentBuilding::class, $mw, true);
        $member = array_search(EnsureMember::class, $mw, true);

        expect($auth)->not->toBeFalse($route->uri())
            ->and($tenant)->toBe($member - 1, $route->uri().': SetCurrentBuilding must be immediately before member')
            ->and($auth)->toBeLessThan($tenant);

        foreach ([EnsureBuildingRole::class, RequireStepUp::class] as $later) {
            $pos = array_search($later, $mw, true);
            if ($pos !== false) {
                expect($pos)->toBeGreaterThan($member, $route->uri());
            }
        }
    }
});

it('registers the same chain as persistent Livewire middleware', function (): void {
    $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

    expect($persistent)->toContain(SetCurrentBuilding::class, EnsureMember::class, EnsureBuildingRole::class, RequireStepUp::class, EnsurePinChanged::class);
});

it('keeps the public building privacy page outside /b/ (tenant only, no membership)', function (): void {
    $route = RouteFacade::getRoutes()->getByName('building.privacy');

    expect($route->uri())->toBe('privacy/{building}')
        ->and(app('router')->gatherRouteMiddleware($route))->not->toContain(EnsureMember::class);
});
