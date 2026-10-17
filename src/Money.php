<?php

declare(strict_types=1);

namespace PintAE;

/** Small helpers for 2-decimal invoice arithmetic. */
final class Money
{
    public const TOLERANCE = 0.01;

    public static function round(float|int|string|null $v, int $dp = 2): float
    {
        $v = (float) ($v ?? 0);
        // pre-round at 9 dp to remove binary float noise (e.g. 2.675 -> 2.67499999)
        return round(round($v, 9), $dp, PHP_ROUND_HALF_UP);
    }

    public static function fmt(float|int|string|null $v, int $dp = 2): string
    {
        return number_format(self::round($v, $dp), $dp, '.', '');
    }

    /** Format a quantity/price without forcing trailing zeros beyond 2 dp. */
    public static function qty(float|int|string|null $v): string
    {
        $v = (float) ($v ?? 0);
        $s = rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    public static function equals(float $a, float $b, float $tol = self::TOLERANCE): bool
    {
        return abs($a - $b) <= $tol + 1e-9;
    }
}
