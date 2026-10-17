<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UI locale (LV default, RU): the user's saved locale → the `mp_locale` cookie → lv. Also the senior
 * "Lielais teksts" mode and theme preference for guests (cookies), shared with the views.
 */
final class SetLocale
{
    public const COOKIE = 'mp_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $supported = (array) config('app.locales', ['lv', 'ru']);
        $user = $request->user();

        $locale = $user instanceof User ? $user->locale->value : $request->cookie(self::COOKIE);

        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            $locale = (string) config('app.fallback_locale', 'lv');
        }

        app()->setLocale($locale);
        CarbonImmutable::setLocale($locale);

        return $next($request);
    }
}
