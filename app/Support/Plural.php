<?php

declare(strict_types=1);

namespace App\Support;

/** Русское склонение после числа: 1 сделка, 3 сделки, 11 сделок. */
final class Plural
{
    public static function choose(int $number, string $one, string $few, string $many): string
    {
        $n = abs($number) % 100;
        $last = $n % 10;

        if ($n > 10 && $n < 20) {
            return $many;
        }

        if ($last === 1) {
            return $one;
        }

        if ($last > 1 && $last < 5) {
            return $few;
        }

        return $many;
    }
}
