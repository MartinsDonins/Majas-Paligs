<?php

declare(strict_types=1);

namespace App\Services\Documents;

use InvalidArgumentException;

/**
 * Parser for the template markers (ROUND2-SPEC §3.4):
 *   `<!-- req:<key> -->` … `<!-- /req -->`   locked block (rendered, never editable),
 *   `<!-- edit:<key> -->` … `<!-- /edit -->` editable block,
 *   text outside markers                     locked.
 * Markers never nest. Keys: lower-case letters, digits, `_` and `-`.
 */
final class TemplateMarkup
{
    private const MARKER = '/<!--\s*(req|edit):([a-z0-9_-]{1,40})\s*-->(.*?)<!--\s*\/\1\s*-->/s';

    public const PLACEHOLDER = '/\{([a-z_]+\.[a-z_]+)\}/';

    /**
     * @return list<array{type: 'text'|'req'|'edit', key: string, text: string}>
     *
     * @throws InvalidArgumentException when a marker is unbalanced or nested
     */
    public static function parse(string $body): array
    {
        $segments = [];
        $offset = 0;
        $n = 0;

        if (preg_match_all(self::MARKER, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new InvalidArgumentException('markers_broken');
        }

        foreach ($matches as $m) {
            [$whole, $start] = $m[0];
            if ($start > $offset) {
                $segments[] = ['type' => 'text', 'key' => 'text-'.(++$n), 'text' => substr($body, $offset, $start - $offset)];
            }
            $inner = $m[3][0];
            if (preg_match('/<!--\s*\/?(req|edit)\b/', $inner) === 1) {
                throw new InvalidArgumentException('markers_broken');
            }
            $segments[] = ['type' => $m[1][0], 'key' => $m[2][0], 'text' => $inner];
            $offset = $start + strlen($whole);
        }

        if ($offset < strlen($body)) {
            $segments[] = ['type' => 'text', 'key' => 'text-'.(++$n), 'text' => substr($body, $offset)];
        }

        // A stray opening / closing marker left in plain text means the structure is broken.
        foreach ($segments as $s) {
            if ($s['type'] === 'text' && preg_match('/<!--\s*\/?(req|edit)\b/', $s['text']) === 1) {
                throw new InvalidArgumentException('markers_broken');
            }
        }

        $keys = array_map(static fn (array $s): string => $s['type'].':'.$s['key'], array_filter($segments, static fn (array $s): bool => $s['type'] !== 'text'));
        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('markers_broken');
        }

        return $segments;
    }

    /**
     * Rebuild a body from segments (inverse of parse()).
     *
     * @param  list<array{type: string, key: string, text: string}>  $segments
     */
    public static function assemble(array $segments): string
    {
        return implode('', array_map(static fn (array $s): string => match ($s['type']) {
            'text' => $s['text'],
            default => '<!-- '.$s['type'].':'.$s['key'].' -->'.$s['text'].'<!-- /'.$s['type'].' -->',
        }, $segments));
    }

    /**
     * Editable blocks as key => text (trimmed of the surrounding newlines the template layout adds).
     *
     * @return array<string, string>
     */
    public static function editable(string $body): array
    {
        $out = [];
        foreach (self::parse($body) as $s) {
            if ($s['type'] === 'edit') {
                $out[$s['key']] = trim($s['text'], "\n");
            }
        }

        return $out;
    }

    /**
     * Replace the content of editable blocks; unknown keys are ignored (a locked block can never be written here).
     *
     * @param  array<string, string>  $values
     */
    public static function withEditable(string $body, array $values): string
    {
        $segments = self::parse($body);

        foreach ($segments as $i => $s) {
            if ($s['type'] === 'edit' && array_key_exists($s['key'], $values)) {
                $text = str_replace("\r\n", "\n", (string) $values[$s['key']]);
                $segments[$i]['text'] = "\n".trim($text, "\n")."\n";
            }
        }

        return self::assemble($segments);
    }

    /**
     * Everything that must equal the master: text + req blocks, with each editable block emptied.
     */
    public static function skeleton(string $body): string
    {
        return self::assemble(array_map(
            static fn (array $s): array => $s['type'] === 'edit' ? [...$s, 'text' => ''] : $s,
            self::parse($body),
        ));
    }

    /**
     * Remove all markers (for rendering).
     */
    public static function strip(string $body): string
    {
        return implode('', array_map(static fn (array $s): string => $s['text'], self::parse($body)));
    }

    /**
     * @return list<string> placeholder names used in a text ("controller.name", …)
     */
    public static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $m);

        return array_values(array_unique($m[1]));
    }
}
