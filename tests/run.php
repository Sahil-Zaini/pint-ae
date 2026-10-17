<?php

/**
 * Zero-dependency test runner:  php tests/run.php
 *
 * 1. Every example invoice → XML that is UBL 2.1 schema-valid, rule-valid,
 *    and has the same totals as the original certification test case.
 * 2. Round-trip XML → array keeps the important fields.
 * 3. Broken invoices must trigger the expected rule ids.
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use PintAE\Calculator;
use PintAE\Money;
use PintAE\PintAE;
use PintAE\Reader;
use PintAE\Validator;

$pass = 0;
$fail = 0;
$failures = [];

function check(bool $ok, string $name, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  \033[32m✓\033[0m $name\n";
    } else {
        $fail++;
        $failures[] = "$name $detail";
        echo "  \033[31m✗ $name\033[0m $detail\n";
    }
}

function example(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__ . "/../examples/invoices/$name.json"), true, 512, JSON_THROW_ON_ERROR);
}

function schemaErrors(string $xml): array
{
    $dom = new DOMDocument();
    $dom->loadXML($xml, LIBXML_NONET);
    $xsd = __DIR__ . '/schema/maindoc/UBL-' . $dom->documentElement->localName . '-2.1.xsd';
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->schemaValidate($xsd);
    $errs = array_map(fn ($e) => trim($e->message) . ' @' . $e->line, libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? [] : ($errs ?: ['schema validation failed']);
}

/** Generate + validate and assert that $rule fires (as an error unless $warning). */
function expectRule(string $label, array $inv, string $rule, bool $warning = false, ?callable $mutateXml = null): void
{
    $xml = PintAE::generate($inv);
    if ($mutateXml) {
        $xml = $mutateXml($xml);
    }
    $res = PintAE::validate($xml);
    $hit = array_filter($warning ? $res->warnings() : $res->errors(), fn ($i) => $i['rule'] === $rule);
    check($hit !== [], "$label → $rule", $hit ? '' : '(got: ' . (implode(', ', $res->rules()) ?: 'nothing') . ')');
}

// ---------------------------------------------------------------------------
echo "\n1. The 17 example scenarios (14 certification test cases + 3 extra)\n";
$expected = json_decode((string) file_get_contents(__DIR__ . '/expected-totals.json'), true);
foreach (glob(__DIR__ . '/../examples/invoices/*.json') as $file) {
    $name = basename($file);
    $inv = json_decode((string) file_get_contents($file), true);
    ['xml' => $xml, 'result' => $res] = PintAE::build($inv);
    $schema = schemaErrors($xml);
    check($schema === [], "$name is UBL 2.1 schema-valid", implode('; ', array_slice($schema, 0, 2)));
    check($res->errors() === [], "$name passes the business rules", (string) $res);

    $t = Reader::read($xml)['_totals'];
    $e = $expected[$name];
    $same = Money::equals($t['line_extension'], $e['line_extension']) && Money::equals($t['tax_exclusive'], $e['tax_exclusive'])
        && Money::equals($t['tax_inclusive'], $e['tax_inclusive']) && Money::equals($t['payable'], $e['payable']);
    check($same, "$name totals match the expected totals", json_encode([$t, $e]));

    $committed = __DIR__ . '/../examples/xml/' . basename($file, '.json') . '.xml';
    if (is_file($committed)) {
        $strip = fn ($x) => preg_replace('#<cbc:UUID>[^<]*</cbc:UUID>#', '', $x);
        check($strip(file_get_contents($committed)) === $strip($xml), "$name matches committed examples/xml output");
    }
}

// ---------------------------------------------------------------------------
echo "\n2. Round trip and JSON\n";
$inv = example('01-standard');
$back = PintAE::toArray(PintAE::generate($inv));
check($back['number'] === 'TB-01', 'number survives round trip');
check($back['seller']['trn'] === $inv['seller']['trn'], 'seller TRN survives round trip');
check($back['buyer']['address']['emirate'] === 'AUH', 'buyer emirate survives round trip');
check(count($back['lines']) === 1 && $back['lines'][0]['category'] === 'S', 'lines survive round trip');
check(!array_key_exists('_totals', $back), 'toArray() hides internal keys');
check(json_decode(PintAE::toJson(PintAE::generate($inv)), true)['currency'] === 'AED', 'toJson() returns valid JSON');

$inv = example('10-disclosed-agent');
check(PintAE::toArray(PintAE::generate($inv))['principal_id'] === '100000000000203', 'principal id (BTAE-14) round trip');
$inv = example('07-free-trade-zone');
check(PintAE::toArray(PintAE::generate($inv))['beneficiary_id'] === '100000000000103', 'beneficiary id (BTAE-01) round trip');
$inv = example('14-reverse-charge');
check(PintAE::toArray(PintAE::generate($inv))['lines'][0]['rcm_type'] === 'DL8.48.8.2', 'RCM alias ELECTRONIC_DEVICES → DL8.48.8.2');
$inv = example('05-export');
check(PintAE::toArray(PintAE::generate($inv))['buyer']['endpoint']['id'] === '9900000099', 'export buyer gets predefined endpoint 9900000099');
check(PintAE::toArray(PintAE::generate(example('02-credit-note')))['credit_reason']['code'] === 'DL8.61.1.D', 'credit reason alias GOODS_OR_SERVICES_RETURNED → DL8.61.1.D');
check(PintAE::toArray(PintAE::generate(example('09-continuous-supply')))['period']['frequency'] === 'MTH', 'frequency alias MONTHLY → MTH');
$ac = PintAE::calculate(example('15-allowances-and-charges'));
check(Money::equals($ac['lines'][0]['net'], 12160.0), 'line allowance 5% of 12,800 → net 12,160');
check(Money::equals($ac['totals']['tax_exclusive'], 12935.0) && Money::equals($ac['totals']['tax'], 646.75), 'document allowance and charge flow into the VAT breakdown');
$tinInv = example('01-standard'); $tinInv['seller']['tin'] = '1000000000';
['result' => $tinRes] = PintAE::build($tinInv);
check($tinRes->errors() === [], 'seller TIN (second PartyTaxScheme) is valid', (string) $tinRes);

// ---------------------------------------------------------------------------
echo "\n3. Calculator\n";
$c = Calculator::calculate(['lines' => [
    ['quantity' => 3, 'unit_price' => 33.335, 'category' => 'S'],
    ['quantity' => 1, 'unit_price' => 0.10, 'category' => 'S'],
    ['quantity' => 2, 'unit_price' => 50, 'category' => 'Z', 'rate' => 0],
]]);
check(Money::equals($c['lines'][0]['net'], 100.01), 'line net rounds half-up (3 × 33.335 = 100.01)');
check(count($c['subtotals']) === 2, 'VAT breakdown groups by category');
check(Money::equals($c['subtotals'][0]['tax'], 5.01), 'breakdown VAT computed on group total (100.11 × 5%)');
check(Money::equals($c['totals']['tax_inclusive'], 205.12), 'tax inclusive total');
$usd = example('01-standard');
$usd['currency'] = 'USD';
$usd['exchange_rate'] = 3.6725;
['xml' => $xml, 'result' => $res] = PintAE::build($usd);
check($res->errors() === [] && schemaErrors($xml) === [], 'USD invoice with exchange rate is valid', (string) $res);
check(str_contains($xml, '<cbc:TaxAmount currencyID="AED">91.81</cbc:TaxAmount>'), 'USD invoice carries VAT total in AED (25 × 3.6725)');
check(str_contains($xml, '<cbc:CalculationRate>3.6725</cbc:CalculationRate>'), 'exchange rate in TaxExchangeRate (BTAE-04)');
try {
    unset($usd['exchange_rate']);
    PintAE::generate($usd);
    check(false, 'missing exchange rate throws');
} catch (InvalidArgumentException) {
    check(true, 'missing exchange rate throws');
}
check(Validator::isTrn('100000000000003') && !Validator::isTrn('100000000000001') && !Validator::isTrn('20000000000003'), 'TRN format 1…03 (15 digits)');
check(Validator::isIban('AE070331234567890123456') && !Validator::isIban('AE070331234567890123457'), 'IBAN mod-97 check');

// ---------------------------------------------------------------------------
echo "\n4. Broken invoices are caught\n";
$std = example('01-standard');

$x = $std; $x['seller']['trn'] = '123456789012345';
expectRule('bad seller TRN', $x, 'IBR-132-AE');
$x = $std; $x['buyer']['address']['emirate'] = 'DUBAI';
expectRule('emirate name instead of code', $x, 'IBR-128-AE');
$x = $std; unset($x['seller']['address']['line1']);
expectRule('seller without street', $x, 'IBR-143-AE');
$x = $std; $x['lines'][0]['rate'] = 10;
expectRule('standard rate 10%', $x, 'IBR-190-AE');
expectRule('VAT subtotal tampered', $std, 'ALIGNED-IBRP-S-09', false,
    fn ($xml) => preg_replace('#(<cac:TaxSubtotal>\s*<cbc:TaxableAmount[^>]*>[^<]*</cbc:TaxableAmount>\s*<cbc:TaxAmount currencyID="AED">)25.00#', '${1}20.00', $xml, 1));
expectRule('payable tampered', $std, 'PAE-063', false,
    fn ($xml) => str_replace('<cbc:PayableAmount currencyID="AED">525.00', '<cbc:PayableAmount currencyID="AED">520.00', $xml));
expectRule('UUID removed', $std, 'IBR-193-AE', false, fn ($xml) => preg_replace('#<cbc:UUID>[^<]*</cbc:UUID>#', '', $xml));
$x = $std; $x['transaction_flags'] = '0101';
expectRule('short transaction flags', $x, 'IBR-154-AE');
$x = $std; $x['payment']['iban'] = 'AE070331234567890123457';
expectRule('IBAN checksum', $x, 'PAE-091', true);
$x = $std; unset($x['payment']['iban']);
expectRule('credit transfer without IBAN', $x, 'IBR-192-AE');
$x = $std; unset($x['payment']);
expectRule('invoice without payment means', $x, 'IBR-191-AE');
$x = $std; unset($x['seller']['registration']);
expectRule('seller without trade licence', $x, 'IBR-150-AE');
$x = $std; unset($x['seller']['registration']['authority']);
expectRule('trade licence without authority', $x, 'IBR-172-AE');
expectRule('wrong CustomizationID', $std, 'ALIGNED-IBRP-001-AE', false,
    fn ($xml) => str_replace('billing-1@ae-1', 'billing-1@sg-1', $xml));

$x = example('02-credit-note'); unset($x['credit_reason']);
expectRule('credit note without reason', $x, 'IBR-158-AE');
$x = example('02-credit-note'); unset($x['preceding_invoice']);
expectRule('credit note without original invoice', $x, 'IBR-055-AE');
$x = example('07-free-trade-zone'); unset($x['beneficiary_id']);
expectRule('free zone without beneficiary', $x, 'IBR-007-AE');
$x = example('10-disclosed-agent'); unset($x['principal_id']);
expectRule('disclosed agent without principal', $x, 'IBR-137-AE');
$x = example('10-disclosed-agent'); $x['principal_id'] = $x['seller']['trn'];
expectRule('principal equals seller', $x, 'IBR-176-AE');
$x = example('13-summary'); unset($x['period']);
expectRule('summary without period', $x, 'IBR-138-AE');
$x = example('11-ecommerce'); unset($x['delivery']['address']['line1']);
expectRule('e-commerce without deliver-to street', $x, 'IBR-142-AE');
$x = example('14-reverse-charge'); unset($x['buyer']['trn']);
expectRule('reverse charge without buyer TRN', $x, 'IBR-103-AE');
$x = example('14-reverse-charge'); unset($x['lines'][0]['rcm_type']);
expectRule('reverse charge without RCM type', $x, 'IBR-166-AE');
$x = example('06-out-of-scope'); $x['lines'][0]['category'] = 'S'; $x['lines'][0]['rate'] = 5;
unset($x['lines'][0]['exemption_code'], $x['lines'][0]['exemption_reason']);
expectRule('out-of-scope document with S line', $x, 'IBR-122-AE');
$x = $std; $x['lines'][0]['category'] = 'E'; $x['lines'][0]['rate'] = 0;
expectRule('exempt line without exemption code', $x, 'IBR-167-AE');
expectRule('rate added to O line', example('06-out-of-scope'), 'ALIGNED-IBRP-O-05', false,
    fn ($xml) => preg_replace('#(<cac:ClassifiedTaxCategory>\s*<cbc:ID>O</cbc:ID>)#', '${1}<cbc:Percent>0</cbc:Percent>', $xml));
expectRule('line VAT in AED removed', $std, 'IBR-104-AE', false,
    fn ($xml) => preg_replace('#<cac:ItemPriceExtension>(\s*<cbc:Amount[^>]*>[^<]*</cbc:Amount>)\s*<cac:TaxTotal>.*?</cac:TaxTotal>#s', '<cac:ItemPriceExtension>$1', $xml));
expectRule('not well-formed XML', $std, 'PAE-000', false, fn ($xml) => substr($xml, 0, 400));
$x = example('09-continuous-supply'); $x['period']['frequency'] = 'EVERY_MOON';
expectRule('unknown billing frequency', $x, 'IBR-005-AE');
$x = example('02-credit-note'); $x['credit_reason']['code'] = 'CUSTOMER_UNHAPPY';
expectRule('unknown credit note reason', $x, 'IBR-001-AE');
$x = example('17-volume-discount-credit-note'); $x['preceding_invoice'] = ['number' => 'TB-01', 'issue_date' => '2026-09-29'];
expectRule('volume-discount credit note with one invoice reference', $x, 'IBR-055-AE');
$x = example('14-reverse-charge'); $x['lines'][0]['rcm_type'] = 'LAPTOPS';
expectRule('unknown RCM goods type', $x, 'IBR-006-AE');
$x = example('09-continuous-supply'); $x['period']['frequency'] = 'OTH'; unset($x['note']);
expectRule('frequency OTH without a note', $x, 'IBR-160-AE');
$x = example('15-allowances-and-charges'); $x['allowances'][0]['category'] = 'N'; $x['allowances'][0]['rate'] = 5;
expectRule('document allowance with category N', $x, 'IBR-115-AE');
$x = example('15-allowances-and-charges'); $x['charges'][0]['rate'] = 10;
expectRule('document charge at 10%', $x, 'IBR-190-AE');
$x = example('01-standard'); $x['lines'][0]['category'] = 'E'; $x['lines'][0]['rate'] = 0; $x['lines'][0]['exemption_code'] = 'DL8.46.2';
$x['allowances'] = [['amount' => 10, 'reason' => 'Goodwill', 'category' => 'E', 'rate' => 0]];
expectRule('exempt document allowance without reason code', $x, 'IBR-168-AE');
expectRule('allowance percent tampered', example('15-allowances-and-charges'), 'IBR-131-AE', false,
    fn ($xml) => str_replace('<cbc:MultiplierFactorNumeric>5</cbc:MultiplierFactorNumeric>', '<cbc:MultiplierFactorNumeric>6</cbc:MultiplierFactorNumeric>', $xml));
expectRule('line allowance removed from XML', example('15-allowances-and-charges'), 'IBR-147-AE', false,
    fn ($xml) => preg_replace('#(<cbc:LineExtensionAmount[^>]*>12160.00</cbc:LineExtensionAmount>)\s*<cac:AllowanceCharge>.*?</cac:AllowanceCharge>#s', '$1', $xml, 1));
$x = example('01-standard'); $x['seller']['registration'] = ['type' => 'PAS', 'id' => 'P1234567'];
expectRule('passport without issuing country', $x, 'IBR-012-AE');
$x = example('01-standard'); $x['seller']['tin'] = '123';
expectRule('bad seller TIN', $x, 'IBR-148-AE');
$x = example('05-export'); unset($x['buyer']['address']['emirate']);
expectRule('foreign buyer without state/region', $x, 'IBR-144-AE');

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('─', 60) . "\n";
printf("%d passed, %d failed\n", $pass, $fail);
if ($fail) {
    echo "\nFailures:\n - " . implode("\n - ", $failures) . "\n";
}
exit($fail ? 1 : 0);
