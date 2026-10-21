<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\StepUpService;
use App\Models\User;
use App\Support\SafeReturn;
use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * `step-up` / `step-up:mfa` — elevated actions need a fresh confirmation (PLANNING §4.5 item 4; 2 h idle, sliding
 * while the person works here, 12 h at most — StepUpService::hasFresh).
 * `mfa` additionally requires a second factor (passkey, TOTP or a trusted device), e.g. for the platform panel; users
 * holding building_admin / parking_officer (or platform_admin) always need it (StepUpService::requiresMfa).
 * `strong` = a passkey or TOTP proven in this session (a trusted device does not count) — dangerous actions.
 */
final class RequireStepUp
{
    public function __construct(private readonly StepUpService $stepUp) {}

    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(404);
        }

        $needed = match (true) {
            $level === 'strong' => 'strong',
            $level === 'mfa' || $this->stepUp->requiresMfa($user) => 'mfa',
            default => 'any',
        };
        $fresh = $this->stepUp->hasFreshLevel($user, $needed);

        if ($fresh) {
            $this->stepUp->touch($user);

            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $request->session()->put(StepUpService::INTENDED_KEY, $this->intended($request));
            $request->session()->put(StepUpService::LEVEL_KEY, $needed);

            return redirect()->route('step-up');
        }

        abort(403, __('auth.step_up.required'));
    }

    /**
     * Where to come back after the step-up. On a /livewire/update this middleware sees Livewire's fake request (the
     * page path WITHOUT its query, e.g. /platform/generator instead of /platform/generator?building=3); the page's
     * own GET is the session's previous URL, so prefer it when it is the same page.
     */
    private function intended(Request $request): string
    {
        $uri = SafeReturn::path($request->getRequestUri()) ?? '/';

        if (Livewire::isLivewireRequest()) {
            $previous = SafeReturn::path($request->session()->previousUrl());
            if ($previous !== null && explode('?', $previous, 2)[0] === explode('?', $uri, 2)[0]) {
                return $previous;
            }
        }

        return $uri;
    }
}
