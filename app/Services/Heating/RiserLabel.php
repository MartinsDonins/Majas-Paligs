<?php

declare(strict_types=1);

namespace App\Services\Heating;

/**
 * Human label of a riser code for residents: "H5-1-KITCHEN" → "Kolonna 1 · Virtuve", "HWC5-K" → "Dvieļu
 * žāvētāji · kreisā puse", "CW5-L" → "Aukstais ūdens · labā puse". Unknown patterns fall back to the code.
 */
final class RiserLabel
{
    public static function for(string $code, ?string $locale = null): string
    {
        if (preg_match('/^H\d+-(\d+)-([A-Z0-9_]+)$/', $code, $m) === 1) {
            return __('heating.riser.radiator', ['n' => $m[1], 'room' => self::room(strtolower($m[2]), $locale)], $locale);
        }

        if (preg_match('/^(HWC|CW|HW)\d+-([KL])$/', $code, $m) === 1) {
            $side = $m[2] === 'K' ? __('enum.side.left', [], $locale) : __('enum.side.right', [], $locale);

            return __('heating.riser.side_'.strtolower($m[1]), ['side' => mb_strtolower($side)], $locale);
        }

        return $code;
    }

    public static function room(string $roomCode, ?string $locale = null): string
    {
        if (preg_match('/^room(\d+)$/', $roomCode, $m) === 1) {
            return __('heating.room.room', ['n' => $m[1]], $locale);
        }

        $key = 'heating.room.'.$roomCode;
        $label = __($key, [], $locale);

        return $label === $key ? $roomCode : $label;
    }
}
