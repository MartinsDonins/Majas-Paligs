<?php

declare(strict_types=1);

namespace App\Auth;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects guessable 6-digit PINs at creation, reset and change (MVP-SPEC A.6a "WeakPinRule").
 * Classes: not 6 digits, all-same digits, ascending/descending runs incl. wrap, repeated 2/3-digit patterns,
 * a year 1930–2015 in the first or last 4 digits, valid DDMMYY / YYMMDD dates (1930–2015), the apartment
 * number repeated, and the bundled list of the 200 most common 6-digit PINs.
 */
final class WeakPinRule implements ValidationRule
{
    /** @var array<string, true>|null */
    private static ?array $common = null;

    public function __construct(private readonly ?int $apartmentNumber = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail(__('auth.pin.format'));

            return;
        }

        $pin = (string) $value;

        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            $fail(__('auth.pin.format'));

            return;
        }

        if ($this->weakReason($pin) !== null) {
            $fail(__('auth.pin.weak'));
        }
    }

    /**
     * The deny class a PIN falls into, or null when it is acceptable.
     */
    public function weakReason(string $pin): ?string
    {
        $d = array_map('intval', str_split($pin));

        if (count(array_unique($d)) === 1) {
            return 'same_digits';
        }

        if ($this->isRun($d, 1) || $this->isRun($d, -1)) {
            return 'sequence';
        }

        if ($pin === str_repeat(substr($pin, 0, 2), 3) || $pin === str_repeat(substr($pin, 0, 3), 2)) {
            return 'repeated_pattern';
        }

        foreach ([substr($pin, 0, 4), substr($pin, 2, 4)] as $year) {
            if ((int) $year >= 1930 && (int) $year <= 2015) {
                return 'year';
            }
        }

        if ($this->isDate(substr($pin, 0, 2), substr($pin, 2, 2), substr($pin, 4, 2))      // DDMMYY
            || $this->isDate(substr($pin, 4, 2), substr($pin, 2, 2), substr($pin, 0, 2))) { // YYMMDD
            return 'date';
        }

        if ($this->apartmentNumber !== null) {
            $n = (string) $this->apartmentNumber;
            if ($pin === substr(str_repeat($n, (int) ceil(6 / strlen($n))), 0, 6)) {
                return 'apartment';
            }
        }

        if (isset(self::common()[$pin])) {
            return 'common';
        }

        return null;
    }

    /**
     * @param  list<int>  $digits
     */
    private function isRun(array $digits, int $step): bool
    {
        for ($i = 1; $i < count($digits); $i++) {
            if ($digits[$i] !== (($digits[$i - 1] + $step) % 10 + 10) % 10) {
                return false;
            }
        }

        return true;
    }

    private function isDate(string $dd, string $mm, string $yy): bool
    {
        $y = (int) $yy;
        $year = $y >= 30 ? 1900 + $y : 2000 + $y;

        if ($year < 1930 || $year > 2015) {
            return false;
        }

        return checkdate((int) $mm, (int) $dd, $year);
    }

    /**
     * @return array<string, true>
     */
    private static function common(): array
    {
        if (self::$common === null) {
            $path = resource_path('data/common-pins.txt');
            $lines = is_file($path) ? (array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            self::$common = [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if (preg_match('/^\d{6}$/', $line) === 1) {
                    self::$common[$line] = true;
                }
            }
        }

        return self::$common;
    }
}
