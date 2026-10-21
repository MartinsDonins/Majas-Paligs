<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Old panel URLs → the /admin area (ROUND2-SPEC §1.1, D3): `/manage` → `/admin`, `/manage/{rest}` →
 * `/admin/b/{rest}`, `/platform[/{rest}]` → `/admin/platform[/{rest}]`. Resource and page slugs are unchanged, so every
 * deep link maps 1:1. GET/HEAD answer 301 with the query string kept; any other method answers 308 (method and body
 * preserved, no query echo). None of these URLs ever carries a secret.
 */
final class LegacyPanelRedirect
{
    public function __invoke(Request $request): RedirectResponse
    {
        // By name: the route default {panel} and the URI part {rest} would otherwise bind positionally.
        $panel = (string) $request->route('panel');
        $rest = trim((string) $request->route('rest'), '/');

        // The decoded path is echoed into Location: no control characters or backslashes (header / open-redirect tricks).
        abort_if(preg_match('/[\x00-\x1F\x7F\\\\]/', $rest) === 1, 404);

        $target = match ($panel) {
            'platform' => '/admin/platform'.($rest !== '' ? '/'.$rest : ''),
            default => $rest !== '' ? '/admin/b/'.$rest : '/admin',
        };

        $readOnly = $request->isMethod('GET') || $request->isMethod('HEAD');
        $query = $request->getQueryString();

        if ($readOnly && $query !== null && $query !== '') {
            $target .= '?'.$query;
        }

        return redirect($target, $readOnly ? 301 : 308);
    }
}
