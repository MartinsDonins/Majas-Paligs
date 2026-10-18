<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Count-dependent UI copy without the "{0}|{1}|[2,*]" interval trick (review fix, native-speaker copy): the 0 and 1
 * cases are separate SENTENCES (`<key>.zero`, `<key>.one`, e.g. "Jūs esat pirmais…"), everything else is a plain
 * locale plural string in `<key>.other`, so Laravel's LV / RU plural rules pick the grammatical form
 * (lv: 0 · 21 dzīvoklis · 5 dzīvokļi; ru: 21 квартира · 3 квартиры · 5 квартир).
 */
final class Copy
{
    /**
     * @param  array<string, mixed>  $replace
     */
    public static function count(string $key, int $count, array $replace = []): string
    {
        $replace += ['count' => $count];

        if ($count === 0 && Lang::has($key.'.zero')) {
            return (string) __($key.'.zero', $replace);
        }

        if ($count === 1 && Lang::has($key.'.one')) {
            return (string) __($key.'.one', $replace);
        }

        return trans_choice($key.'.other', $count, $replace);
    }
}
