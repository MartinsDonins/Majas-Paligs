<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\StepUpService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `step-up` / `step-up:mfa` — elevated actions need a confirmation not older than 2 h (PLANNING §4.5 item 4).
 * `mfa` additionally requires the second factor (TOTP), e.g. for the platform panel; users holding
 * building_admin / parking_officer (or platform_admin) always need TOTP (StepUpService::requiresMfa).
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

        $mfa = $level === 'mfa' || $this->stepUp->requiresMfa($user);
        $fresh = $mfa ? $this->stepUp->hasFreshMfa($user) : $this->stepUp->hasFresh($user);

        if ($fresh) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $request->session()->put('mp.step_up.intended', $request->getRequestUri());
            $request->session()->put('mp.step_up.level', $mfa ? 'mfa' : 'any');

            return redirect()->route('step-up');
        }

        abort(403, __('auth.step_up.required'));
    }
}
