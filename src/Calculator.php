<?php

declare(strict_types=1);

namespace PintAE;

use InvalidArgumentException;

/**
 * Calculates line amounts, allowances/charges, VAT breakdown and totals.
 *
 * Rules (matching the official PINT AE examples and rules):
 *  - line net = round(quantity × net price ÷ base quantity + line charges − line allowances)   IBR-147-AE
 *  - allowance/charge amount = round(base × percent ÷ 100) when only a percent is given           IBR-131/146-AE
 *  - VAT breakdown: one group per category (S and N also split by rate);
 *    taxable = line nets + document charges − document allowances of that group
 *  - VAT of a group = round(taxable × rate ÷ 100) for S; 0 for Z, E, O, AE, N
 *  - line VAT (BTAE-08) and line amount payable (BTAE-10) are given in AED
 */
final class Calculator
{
    /**
     * @param array<string,mixed> $inv
     * @return array{lines:list<array<string,mixed>>,allowances:list<array<string,mixed>>,charges:list<array<string,mixed>>,subtotals:list<array<string,mixed>>,totals:array<string,float>}
     */
    public static function calculate(array $inv): array
    {
        $fx = strtoupper($inv['currency'] ?? 'AED') === Codes::ACCOUNTING_CURRENCY
            ? 1.0 : (float) ($inv['exchange_rate'] ?? 0);
        if ($fx <= 0) {
            throw new InvalidArgumentException('"exchange_rate" (AED per 1 unit of invoice currency) is required when currency is not AED.');
        }

        $groups = [];
        $add = function (string $cat, float $rate, float $amount, array $src) use (&$groups): void {
            $key = self::groupKey($cat, $rate);
            if (!isset($groups[$key])) {
                $groups[$key] = ['category' => $cat, 'rate' => $rate, 'taxable' => 0.0, 'exemption_code' => null, 'exemption_reason' => null, '_rate_set' => false];
            }
            // breakdown rate follows the lines (AE lines may carry 5% while allowances carry 0%)
            if (!empty($src['_is_line']) && !$groups[$key]['_rate_set']) {
                $groups[$key]['rate'] = $rate;
                $groups[$key]['_rate_set'] = true;
            }
            $groups[$key]['taxable'] += $amount;
            $groups[$key]['exemption_code'] ??= $src['exemption_code'] ?? null;
            $groups[$key]['exemption_reason'] ??= $src['exemption_reason'] ?? null;
        };

        $lines = [];
        $lineSum = 0.0;
        foreach ((array) ($inv['lines'] ?? []) as $i => $l) {
            if (!is_array($l)) {
                throw new InvalidArgumentException("Line #$i must be an object.");
            }
            $l['id'] = (string) ($l['id'] ?? ($i + 1));
            $l['category'] = self::category($l['category'] ?? 'S');
            $l['rate'] = (float) ($l['rate'] ?? ($l['category'] === 'S' ? Codes::STANDARD_RATE : 0));
            $l['quantity'] = (float) ($l['quantity'] ?? 1);
            $l['unit_price'] = (float) ($l['unit_price'] ?? 0);
            $l['gross_price'] = (float) ($l['gross_price'] ?? $l['unit_price']);
            $l['price_discount'] = Money::round($l['gross_price'] - $l['unit_price'], 6);
            $l['base_quantity'] = (float) ($l['base_quantity'] ?? 1) ?: 1.0;

            $base = Money::round($l['quantity'] * $l['unit_price'] / $l['base_quantity']);
            $l['allowances'] = self::adjustments($l['allowances'] ?? (isset($l['allowance']) ? [['amount' => $l['allowance']]] : []), $base, false, null);
            $l['charges'] = self::adjustments($l['charges'] ?? (isset($l['charge']) ? [['amount' => $l['charge']]] : []), $base, true, null);
            $l['net'] = Money::round($base + array_sum(array_column($l['charges'], 'amount')) - array_sum(array_column($l['allowances'], 'amount')));
            $l['tax'] = self::taxFor($l['category'], $l['rate'], $l['net']);
            $l['gross'] = Money::round($l['net'] + $l['tax']);
            $l['tax_aed'] = Money::round($l['tax'] * $fx);
            $l['gross_aed'] = Money::round($l['gross'] * $fx);
            $lines[] = $l;
            $lineSum += $l['net'];
            $add($l['category'], $l['rate'], $l['net'], $l + ['_is_line' => true]);
        }

        $lineExt = Money::round($lineSum);
        $docAllow = self::adjustments((array) ($inv['allowances'] ?? []), $lineExt, false, 'S');
        $docCharge = self::adjustments((array) ($inv['charges'] ?? []), $lineExt, true, 'S');
        foreach ($docAllow as $a) {
            $add($a['category'], $a['rate'], -$a['amount'], $a);
        }
        foreach ($docCharge as $c) {
            $add($c['category'], $c['rate'], $c['amount'], $c);
        }

        $subtotals = [];
        $tax = 0.0;
        foreach ($groups as $g) {
            unset($g['_rate_set']);
            $g['taxable'] = Money::round($g['taxable']);
            $g['tax'] = self::taxFor($g['category'], $g['rate'], $g['taxable']);
            $tax += $g['tax'];
            $subtotals[] = $g;
        }

        $allow = Money::round(array_sum(array_column($docAllow, 'amount')));
        $charge = Money::round(array_sum(array_column($docCharge, 'amount')));
        $taxExcl = Money::round($lineExt - $allow + $charge);
        $tax = Money::round($tax);
        $taxIncl = Money::round($taxExcl + $tax);
        $prepaid = Money::round($inv['prepaid'] ?? 0);
        $rounding = Money::round($inv['rounding'] ?? 0);

        return [
            'lines' => $lines,
            'allowances' => $docAllow,
            'charges' => $docCharge,
            'subtotals' => $subtotals,
            'totals' => [
                'line_extension' => $lineExt,
                'allowances' => $allow,
                'charges' => $charge,
                'tax_exclusive' => $taxExcl,
                'tax' => $tax,
                'tax_inclusive' => $taxIncl,
                'prepaid' => $prepaid,
                'rounding' => $rounding,
                'payable' => Money::round($taxIncl - $prepaid + $rounding),
                'exchange_rate' => $fx,
                'tax_aed' => Money::round($tax * $fx),
                'tax_inclusive_aed' => Money::round($taxIncl * $fx),
            ],
        ];
    }

    /**
     * Normalise allowances or charges. Each item: amount | percent (+ base),
     * reason, reason_code; document-level items also category/rate/exemption_code.
     *
     * @param list<array<string,mixed>|float|int> $items
     * @return list<array<string,mixed>>
     */
    private static function adjustments(array $items, float $defaultBase, bool $isCharge, ?string $defaultCategory): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                $it = ['amount' => $it];
            }
            $base = isset($it['base']) ? (float) $it['base'] : null;
            if (isset($it['percent'])) {
                $base ??= $defaultBase;
                $amount = isset($it['amount']) ? (float) $it['amount'] : $base * (float) $it['percent'] / 100;
            } else {
                $amount = (float) ($it['amount'] ?? 0);
            }
            $row = [
                'charge' => $isCharge,
                'amount' => Money::round($amount),
                'base' => $base !== null ? Money::round($base) : null,
                'percent' => isset($it['percent']) ? (float) $it['percent'] : null,
                'reason_code' => $it['reason_code'] ?? (isset($it['reason']) ? null : ($isCharge ? null : '95')),
                'reason' => $it['reason'] ?? ($isCharge ? 'Charge' : 'Discount'),
            ];
            if ($defaultCategory !== null) {
                $row['category'] = self::category($it['category'] ?? $defaultCategory);
                $row['rate'] = (float) ($it['rate'] ?? ($row['category'] === 'S' ? Codes::STANDARD_RATE : 0));
                $row['exemption_code'] = $it['exemption_code'] ?? null;
                $row['exemption_reason'] = $it['exemption_reason'] ?? null;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** One breakdown per category; S and N are also split by rate. */
    public static function groupKey(string $category, float|int|null $rate): string
    {
        return in_array($category, ['S', 'N'], true) ? $category . '@' . Money::qty($rate ?? 0) : $category;
    }

    /** Upper-case and fold the Greek capital Nu (Ν, used in the official code list) to Latin N. */
    public static function category(string $c): string
    {
        $c = strtoupper(trim($c));
        return $c === "\u{039D}" ? 'N' : $c;
    }

    public static function taxFor(string $category, float $rate, float $taxable): float
    {
        if (in_array($category, Codes::ZERO_TAX_CATEGORIES, true)) {
            return 0.0;
        }
        return Money::round($taxable * $rate / 100);
    }
}
