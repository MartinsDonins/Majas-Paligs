<?php

declare(strict_types=1);

use App\Gdpr\CoreGateClosed;
use App\Http\Middleware\DiscardSecretQuery;
use App\Http\Middleware\EnsureBuildingRole;
use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\EnsureModule;
use App\Http\Middleware\EnsurePinChanged;
use App\Http\Middleware\EnsureSessionEpoch;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\RequireStepUp;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionLifetime;
use App\Http\Middleware\SetCurrentBuilding;
use App\Http\Middleware\SetLocale;
use App\Support\ErrorEventRecorder;
use App\Tenancy\Exceptions\BuildingContextAlreadySet;
use App\Tenancy\Exceptions\MissingBuildingContext;
use Filament\Http\Middleware\IdentifyTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as BasePreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            prepend: [SessionLifetime::class],
            append: [EnsureSessionEpoch::class, SetLocale::class, EnsurePinChanged::class],
            replace: [BasePreventRequestForgery::class => PreventRequestForgery::class],
        );

        // Remembered devices get a longer session; it must be decided before StartSession builds the handler.
        $middleware->prependToPriorityList(StartSession::class, SessionLifetime::class);

        $middleware->prepend(SecurityHeaders::class);

        $middleware->alias([
            'member' => EnsureMember::class,
            'module' => EnsureModule::class,
            'role' => EnsureBuildingRole::class,
            'step-up' => RequireStepUp::class,
            'tenant' => SetCurrentBuilding::class,
            'discard.query' => DiscardSecretQuery::class,
        ]);

        // The UI language is known BEFORE route-model binding, so a 404 for an unknown building / record is rendered
        // in the visitor's language (it used to be always Latvian).
        $middleware->prependToPriorityList(SubstituteBindings::class, SetLocale::class);

        // Binding order: auth → SubstituteBindings → SetCurrentBuilding → member → role/step-up.
        // Filament resolves the tenant (IdentifyTenant, via User::getTenants/canAccessTenant) BEFORE we set it.
        $middleware->appendToPriorityList(SubstituteBindings::class, IdentifyTenant::class);
        $middleware->appendToPriorityList(IdentifyTenant::class, SetCurrentBuilding::class);
        $middleware->appendToPriorityList(SetCurrentBuilding::class, EnsureMember::class);
        $middleware->appendToPriorityList(EnsureMember::class, EnsureModule::class);
        $middleware->appendToPriorityList(EnsureModule::class, EnsureBuildingRole::class);
        $middleware->appendToPriorityList(EnsureBuildingRole::class, RequireStepUp::class);

        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn () => route('home.redirect'));

        // Client IP (review fix): resolved ONCE, by nginx's realip module (serversideup TRUSTED_PROXY=local —
        // X-Forwarded-For walked right-to-left past the Docker / private ranges, i.e. the address Traefik saw) into
        // REMOTE_ADDR. Laravel must never derive the IP from X-Forwarded-For again (its right-most entry is the
        // proxy, shared by every visitor, so every IP limiter — pin-ip, invite-ip, magic-ip, login-* — would become
        // one bucket; docs/SECURITY.md "Client IP").
        // Proxies are trusted for the scheme and port only (TLS ends at Traefik); never for the host or the IP.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Fail-closed tenancy errors never leak details: they render like an unknown building (404).
        $notFound = static fn (Request $request) => $request->expectsJson()
            ? response()->json(['message' => 'Not Found'], 404)
            : response()->view('errors.404', [], 404);
        $exceptions->render(fn (MissingBuildingContext $e, Request $request) => $notFound($request));
        $exceptions->render(fn (BuildingContextAlreadySet $e, Request $request) => $notFound($request));
        $exceptions->render(fn (CoreGateClosed $e, Request $request) => $request->expectsJson()
            ? response()->json(['message' => __('gate.core.closed_short')], 409)
            : response()->view('errors.409', [], 409));

        // PII-free error summaries for the MCP `app_status` tool (round 3). Reported exceptions only; never throws.
        $exceptions->report(static function (Throwable $e): void {
            ErrorEventRecorder::record($e);
        });

        $exceptions->dontFlash(['c', 't', 'token', 'code', 'pin', 'new_pin', 'temp_code', 'signature']);

        // GlitchTip (Sentry protocol), scrubbed by App\Support\SentryScrubber (config/sentry.php).
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
