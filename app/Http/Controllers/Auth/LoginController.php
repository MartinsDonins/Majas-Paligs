<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\LoginCompleter;
use App\Auth\MagicLinkService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Magic-link confirm page + POST, 6-digit code login and logout (MVP-SPEC A.6, AUTH-001).
 * GET /login/confirm never changes state; the token arrives in the URL fragment and is copied into the POST
 * body by Alpine, then cleared from the address bar.
 */
final class LoginController
{
    public function __construct(
        private readonly MagicLinkService $magic,
        private readonly LoginCompleter $completer,
    ) {}

    public function confirmPage(): View
    {
        return view('auth.confirm');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $token = (string) $request->input('t', '');
        $user = $this->magic->consumeToken($token);

        if ($user === null) {
            return redirect()->route('login')->with('status', 'link_invalid');
        }

        return $this->completer->login($request, $user, remember: $request->boolean('remember', true), method: 'email_link');
    }

    public function code(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $user = $this->magic->consumeCode((string) $data['email'], (string) $data['code']);

        if ($user === null) {
            return redirect()->route('login')
                ->with('status', 'code_invalid')
                ->with('login_email', (string) $data['email'])
                ->withErrors(['code' => __('auth.email.code_invalid')]);
        }

        return $this->completer->login($request, $user, remember: $request->boolean('remember', true), method: 'email_code');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }
}
