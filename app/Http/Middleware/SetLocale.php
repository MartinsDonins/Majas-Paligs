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
 *
 * `mp_locale` alone does not say WHO picked the language: a first visit stores the Accept-Language guess, a team
 * invitation stores its own language. Only the LV/RU toggle (LocaleController) also sets `mp_locale_chosen`, so
 * deviceChose() tells a person's own choice apart from a guess (Karen R2: an RU invitation opened on an LV phone).
 */
final class SetLocale
{
    public const COOKIE = 'mp_locale';

    /** Set only by the LV/RU toggle: the device language is the person's own choice, not a detected guess. */
    public const CHOSEN_COOKIE = 'mp_locale_chosen';

    public function handle(Request $request, Closure $next): Response
    {
        $supported = (array) config('app.locales', ['lv', 'ru']);
        $user = $request->hasSession() ? $request->user() : null;
        $cookie = $request->cookie(self::COOKIE);

        $locale = $user instanceof User ? $user->locale->value : $cookie;
        $fromBrowser = false;

        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            // First visit (no account, no device choice yet): the browser's language list decides (ru, ru-RU,
            // "en-GB,ru;q=0.8" → ru), anything else gets the default LV.
            $detected = self::fromAcceptLanguage($request, $supported);
            $locale = $detected ?? (string) config('app.default_locale', 'lv');
            $fromBrowser = $detected !== null;
        }

        app()->setLocale($locale);
        CarbonImmutable::setLocale($locale);

        // A signed-in user's cookie follows users.locale; a first-visit guest's detected language is remembered on
        // the device, so it survives the login (LoginCompleter prefers the device cookie) and later visits.
        if (($user instanceof User && $cookie !== $locale) || ($fromBrowser && $request->hasSession())) {
            Cookie::queue(self::cookie($locale));
        }

        $response = $next($request);

        if ($fromBrowser) {
            $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Accept-Language', ', '));
        }

        return $response;
    }

    /**
     * The first supported language in the browser's Accept-Language list (quality order), or null.
     *
     * @param  array<int, mixed>  $supported
     */
    public static function fromAcceptLanguage(Request $request, array $supported): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', str_replace('-', '_', $language), 2)[0]);
            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return null;
    }

    /**
     * Whether the person picked this device's language with the LV/RU toggle (a detected or invitation-set
     * `mp_locale` is not a choice).
     */
    public static function deviceChose(Request $request): bool
    {
        return $request->cookie(self::CHOSEN_COOKIE) === '1'
            && in_array($request->cookie(self::COOKIE), (array) config('app.locales', ['lv', 'ru']), true);
    }

    /**
     * The "chosen with the toggle" marker next to the language cookie (same lifetime and flags).
     */
    public static function chosenCookie(): SymfonyCookie
    {
        return cookie(self::CHOSEN_COOKIE, '1', 60 * 24 * 365, httpOnly: true, sameSite: 'lax');
    }

    /**
     * The device language cookie (1 year, HttpOnly, Lax). Not a secret: the value is checked against app.locales.
     */
    public static function cookie(string $locale): SymfonyCookie
    {
        return cookie(self::COOKIE, $locale, 60 * 24 * 365, httpOnly: true, sameSite: 'lax');
    }
}
