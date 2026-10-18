<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\RememberCookieGuard;
use App\Http\Middleware\EnsureBuildingRole;
use App\Http\Middleware\EnsureMember;
use App\Http\Middleware\EnsureModule;
use App\Http\Middleware\EnsurePinChanged;
use App\Http\Middleware\RequireStepUp;
use App\Http\Middleware\SetCurrentBuilding;
use App\Models;
use App\Policies;
use App\Support\Identifiers;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\CurrentBuildingState;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-unit-of-work tenant context (flushed by queue workers between jobs).
        $this->app->scoped(CurrentBuildingState::class);
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // The tenant context never outlives a request (the test client re-uses the application instance).
        $this->app->terminating(static function (): void {
            CurrentBuilding::endRequest();
        });

        // Admin panel strings live in lang/admin/{lv,ru}.json (same parity check as the resident strings).
        $this->app->make('translator')->addJsonPath(lang_path('admin'));

        // Explicit binding: full-page Livewire components do not type-hint {building}, so implicit binding
        // would not run. Building is not a tenant model, so this lookup needs no context.
        Route::bind('building', static fn (string $value): Models\Building => Models\Building::query()
            ->where('slug', $value)->firstOrFail());

        $this->registerAuthGuard();
        $this->registerRateLimiters();
        $this->registerLivewire();
        $this->registerPolicies();
    }

    private function registerPolicies(): void
    {
        Gate::policy(Models\Building::class, Policies\BuildingPolicy::class);
        Gate::policy(Models\Apartment::class, Policies\ApartmentPolicy::class);
        Gate::policy(Models\ApartmentMembership::class, Policies\ApartmentMembershipPolicy::class);
        Gate::policy(Models\BuildingRole::class, Policies\BuildingRolePolicy::class);
        Gate::policy(Models\ApartmentInvite::class, Policies\InvitePolicy::class);
        Gate::policy(Models\AuditEntry::class, Policies\AuditEntryPolicy::class);
        Gate::policy(Models\User::class, Policies\UserPolicy::class);

        // Package B — heating & outages (MVP-SPEC B.5).
        Gate::policy(Models\HeatingReport::class, Policies\HeatingReportPolicy::class);
        Gate::policy(Models\SurveyRound::class, Policies\SurveyRoundPolicy::class);
        Gate::policy(Models\Incident::class, Policies\IncidentPolicy::class);
        Gate::policy(Models\ManagerReport::class, Policies\ManagerReportPolicy::class);
        Gate::policy(Models\RiserCheck::class, Policies\RiserCheckPolicy::class);
        Gate::policy(Models\RiserDispute::class, Policies\RiserDisputePolicy::class);

        foreach ([Models\BuildingSeries::class, Models\SeriesSectionLayout::class, Models\SeriesApartmentType::class,
            Models\SeriesRoom::class, Models\SeriesRiser::class, Models\LegalNotice::class, Models\PrivacyNoticeVersion::class,
            Models\Organization::class] as $platformModel) {
            Gate::policy($platformModel, Policies\SeriesPolicy::class);
        }

        foreach ([Models\Section::class, Models\Riser::class, Models\Room::class, Models\LandParcel::class, Models\RiserLink::class] as $structure) {
            Gate::policy($structure, Policies\StructurePolicy::class);
        }

        // Invite letters are not a model ability: InvitePolicy::print via a gate name.
        Gate::define('print-invites', static fn (Models\User $user): bool => Gate::forUser($user)->allows('print', Models\ApartmentInvite::class));
    }

    private function registerAuthGuard(): void
    {
        // Session guard whose remember cookie is `__Host-mp_remember` (PLANNING §4.1, AC 14).
        Auth::extend('mp-session', static function (Application $app, string $name, array $config): SessionGuard {
            $guard = new RememberCookieGuard(
                $name,
                Auth::createUserProvider($config['provider'] ?? null),
                $app['session.store'],
                $app['request'],
            );
            $guard->setCookieJar($app['cookie']);
            $guard->setDispatcher($app['events']);
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));
            $guard->setRememberDuration((int) config('auth.remember_minutes', 60 * 24 * 180));

            return $guard;
        });
    }

    private function registerRateLimiters(): void
    {
        $ip = static fn (Request $r): string => Identifiers::ipBucket((string) $r->ip());

        RateLimiter::for('locale', static fn (Request $r) => Limit::perMinute(30)->by('locale:'.$ip($r)));
        RateLimiter::for('login-confirm', static fn (Request $r) => Limit::perMinutes(15, 20)->by('login-confirm:'.$ip($r)));
        RateLimiter::for('login-code', static fn (Request $r) => [
            Limit::perMinutes(15, 10)->by('login-code:'.$ip($r)),
        ]);

        // /livewire/update: 120/min per session (MVP-SPEC §0).
        RateLimiter::for('livewire', static fn (Request $r) => Limit::perMinute((int) config('majas.livewire_throttle_per_minute', 120))
            ->by('lw:'.($r->hasSession() ? $r->session()->getId() : $ip($r))));
    }

    private function registerLivewire(): void
    {
        Livewire::setUpdateRoute(static fn (array $handle, string $path) => Route::post($path, $handle)
            ->middleware(['web', 'throttle:livewire']));

        // Re-run tenancy, membership, role and step-up checks on every /livewire/update (PLANNING §4.2).
        Livewire::addPersistentMiddleware([
            SetCurrentBuilding::class,
            EnsureMember::class,
            EnsureModule::class,
            EnsureBuildingRole::class,
            RequireStepUp::class,
            EnsurePinChanged::class,
        ]);
    }
}
