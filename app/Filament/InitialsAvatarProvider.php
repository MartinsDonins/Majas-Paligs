<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Local initials avatar (inline SVG data: URI) for both panels. Filament's default UiAvatarsProvider loads
 * https://ui-avatars.com — blocked by our CSP (img-src 'self' data: blob:), so every panel page showed a broken
 * image, and it would send the user's name to a third party (QA 2026-10-20).
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));
        $letter = mb_strtoupper(mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $name), 0, 1)) ?: '?';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="32" fill="#0B4F55"/>'
            .'<text x="32" y="41" font-family="Inter, system-ui, sans-serif" font-size="28" font-weight="700" fill="#FFFFFF" text-anchor="middle">'
            .htmlspecialchars($letter, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
