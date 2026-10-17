<?php
// php examples/basic.php
require __DIR__ . '/../autoload.php'; // or: require 'vendor/autoload.php';

use PintAE\PintAE;

$invoice = [
    'number'     => 'INV-1001',
    'issue_date' => '2026-10-17',
    'due_date'   => '2026-11-16',
    'currency'   => 'AED',
    'seller' => [
        'name' => 'Demo UAE Supplier LLC',
        'trn'  => '100000000000003',
        'endpoint' => ['scheme' => '0235', 'id' => '1000000000'],
        'registration' => ['type' => 'TL', 'id' => 'CN-1234567', 'authority' => 'DED'],
        'address' => ['line1' => 'Sheikh Zayed Road', 'city' => 'Dubai', 'emirate' => 'DXB', 'country' => 'AE'],
    ],
    'buyer' => [
        'name' => 'UAE Buyer LLC',
        'trn'  => '100000000000103',
        'endpoint' => ['scheme' => '0235', 'id' => '1000000001'],
        'registration' => ['type' => 'TL', 'id' => 'CN-7654321', 'authority' => 'DED'],
        'address' => ['line1' => 'Corniche Road', 'city' => 'Abu Dhabi', 'emirate' => 'AUH', 'country' => 'AE'],
    ],
    'payment' => ['means_code' => 'CREDIT_TRANSFER', 'iban' => 'AE070331234567890123456', 'terms' => 'Net 30'],
    'lines' => [
        ['name' => 'Website development', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => 4000, 'category' => 'S'],
        ['name' => 'Hosting (12 months)', 'quantity' => 12, 'unit_code' => 'MON', 'unit_price' => 50, 'category' => 'S'],
    ],
];

['xml' => $xml, 'result' => $result] = PintAE::build($invoice);

echo $result;                       // VALID — no errors, no warnings
file_put_contents(__DIR__ . '/basic-output.xml', $xml);
echo "Total incl. VAT: ", PintAE::calculate($invoice)['totals']['tax_inclusive'], " AED\n"; // 4830
