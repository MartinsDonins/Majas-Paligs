<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * UI locale (LV default, RU): the user's saved locale → the `mp_locale` cookie → app.locale (lv). Also the senior
 * "Lielais teksts" mode and theme preference for guests (cookies), shared with the views.
 *
 * Runs BEFORE SubstituteBindings (bootstrap/app.php priority) so a 404 from an unknown building / record is in the
 * visitor's language, and session-less routes (404 fallback, PWA files) read the cookie only. For a signed-in user
 * the cookie is kept equal to users.locale, so those session-less pages follow the account language too.
 */
final class SetLocale
{
    public const COOKIE = 'mp_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $supported = (array) config('app.locales', ['lv', 'ru']);
        $user = $request->hasSession() ? $request->user() : null;
        $cookie = $request->cookie(self::COOKIE);

        $locale = $user instanceof User ? $user->locale->value : $cookie;

        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            $locale = (string) config('app.locale', 'lv');
        }

        app()->setLocale($locale);
        CarbonImmutable::setLocale($locale);

        if ($user instanceof User && $cookie !== $locale) {
            Cookie::queue(self::cookie($locale));
        }

        return $next($request);
    }

    /**
     * The device language cookie (1 year, HttpOnly, Lax). Not a secret: the value is checked against app.locales.
     */
    public static function cookie(string $locale): SymfonyCookie
    {
        return cookie(self::COOKIE, $locale, 60 * 24 * 365, httpOnly: true, sameSite: 'lax');
    }
}
