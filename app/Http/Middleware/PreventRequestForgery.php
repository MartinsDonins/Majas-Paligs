<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Base;

/**
 * CSRF protection WITHOUT the `XSRF-TOKEN` cookie (PLANNING §4.1, AC 14): Livewire uses the meta token, and a
 * readable cookie on a shared parent domain (coredigify.com) could be tossed by a sibling site.
 */
final class PreventRequestForgery extends Base
{
    /** @var bool */
    protected $addHttpCookie = false;
}
