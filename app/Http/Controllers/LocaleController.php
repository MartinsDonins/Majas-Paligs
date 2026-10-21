<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Support\SafeReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /locale/{lv|ru}: remembered per user (users.locale) and per device (cookie, 1 year), then back to the page
 * the toggle was pressed on. The form posts that page as `return` (SafeReturn: same-origin page paths only); the
 * session's previous URL is only a fallback — it used to be /sw.js after a service-worker update check.
 */
final class LocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $value = Locale::from($locale);
        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['locale' => $value->value])->save();
        }

        $target = SafeReturn::path($request->input('return'))
            ?? SafeReturn::path(url()->previous())
            ?? '/';

        return redirect($target)->withCookie(SetLocale::cookie($value->value));
    }
}
