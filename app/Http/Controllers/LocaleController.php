<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /locale/{lv|ru}: remembered per user (users.locale) and per device (cookie, 1 year).
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

        $back = url()->previous();
        $target = str_starts_with($back, url('/')) ? $back : url('/');

        return redirect($target)->withCookie(cookie(SetLocale::COOKIE, $value->value, 60 * 24 * 365, httpOnly: true, sameSite: 'lax'));
    }
}
