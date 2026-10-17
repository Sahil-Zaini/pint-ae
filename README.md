<div align="center">

# pint-ae

**Generate and validate UAE e-invoices (PINT AE) in plain PHP.**

Invoice array in → Peppol UBL 2.1 XML out. Totals, VAT, AED amounts and the official UAE rules are handled for you.
Zero dependencies. Runs on cheap shared hosting.

[![tests](https://github.com/sahilzaini2007-pixel/pint-ae/actions/workflows/ci.yml/badge.svg)](https://github.com/sahilzaini2007-pixel/pint-ae/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![license](https://img.shields.io/badge/license-MIT-green)
![official schematron](https://img.shields.io/badge/official%20PINT%20AE%20Schematron-0%20errors-0f6b4f)
![rules](https://img.shields.io/badge/UAE%20rules-130%2F132-0f6b4f)

</div>

---

UAE e-invoicing becomes mandatory from **January 2027** for businesses with turnover of AED 50M or more, and from **July 2027** for everyone else. Every invoice must be sent as **PINT AE**, a UAE flavour of Peppol UBL 2.1 XML, through an accredited service provider (ASP).

Most ERPs and CRMs in the region are written in PHP, but there was no simple open-source way to produce that XML. `pint-ae` fills that gap.

```php
$xml    = PintAE::generate($invoice);   // PINT AE XML string
$result = PintAE::validate($xml);       // errors + warnings with official rule ids
$json   = PintAE::toJson($xml);         // read any PINT AE XML back to JSON
```

## Why trust it

- Every billing example the library generates passes the **official PINT AE Schematron** (Billing 1.0.4) with **0 errors and 0 warnings**. CI runs this check on every push.
- The built-in validator accepts **all 30 official PINT AE example documents**. It also rejects every one of 39 broken invoices that the official Schematron rejects.
- Every document is valid against the **OASIS UBL 2.1 XSD**.
- 134 automated tests, including the 14 test cases from a real ASP certification run.

## Features

- **17 ready scenarios.** Standard, credit note, self-billed invoice and credit note, export, out of scope, free zone, deemed supply, continuous supply, disclosed agent, e-commerce, margin scheme, summary, reverse charge, discounts and charges, foreign currency, and volume-discount credit note.
- **Calculates everything.** Line net, line and document discounts and charges, VAT per category, AED amounts (BTAE-08/10/20), document totals and foreign-currency conversion.
- **Validates.** 130 of the 132 official UAE rules under their real ids (`IBR-132-AE`, `ALIGNED-IBRP-S-09`, …), plus 35 extra checks. See [RULES.md](RULES.md).
- **Official code lists built in.** Credit note reasons, billing frequency, reverse-charge goods, exemption reasons, item types and payment means. Friendly aliases like `MONTHLY` or `GOODS_OR_SERVICES_RETURNED` are converted to the official codes for you.
- **UAE-aware.** Emirate codes, TRN format (15 digits, `1…03`), TIN, trade licence plus issuing authority, passport plus issuing country, the 8-flag transaction type, and the predefined Peppol endpoints for exports (`9900000099`) and deemed supply (`9900000097`).
- **Reads XML too.** Parse any PINT AE invoice into a clean array or JSON.
- **CLI and playground.** `bin/pint-ae` for scripts and CI, plus a single-file PHP playground you can drop on any host.
- **No dependencies.** Needs only `ext-dom`. Works with or without Composer.

## Install

```bash
composer require zainidevelopers/pint-ae
```

**No Composer** (for example on shared hosting): upload the folder and use

```php
require __DIR__ . '/pint-ae/autoload.php';
```

## Quick start

```php
use PintAE\PintAE;

$invoice = [
    'number'     => 'INV-1001',
    'issue_date' => '2026-10-17',
    'due_date'   => '2026-11-16',
    'currency'   => 'AED',
    'seller' => [
        'name' => 'Demo UAE Supplier LLC',
        'trn'  => '100000000000003',
        'endpoint'     => ['scheme' => '0235', 'id' => '1000000000'],      // 10-digit TIN
        'registration' => ['type' => 'TL', 'id' => 'CN-1234567', 'authority' => 'DED'],
        'address' => ['line1' => 'Sheikh Zayed Road', 'city' => 'Dubai', 'emirate' => 'DXB', 'country' => 'AE'],
    ],
    'buyer' => [
        'name' => 'UAE Buyer LLC',
        'trn'  => '100000000000103',
        'endpoint'     => ['scheme' => '0235', 'id' => '1000000010'],
        'registration' => ['type' => 'TL', 'id' => 'CN-7654321', 'authority' => 'DED'],
        'address' => ['line1' => 'Corniche Road', 'city' => 'Abu Dhabi', 'emirate' => 'AUH', 'country' => 'AE'],
    ],
    'payment' => ['means_code' => 'CREDIT_TRANSFER', 'iban' => 'AE070331234567890123456'],
    'lines' => [
        ['name' => 'Website development', 'quantity' => 1,  'unit_code' => 'C62', 'unit_price' => 4000, 'category' => 'S'],
        ['name' => 'Hosting (12 months)', 'quantity' => 12, 'unit_code' => 'MON', 'unit_price' => 50,   'category' => 'S'],
    ],
];

['xml' => $xml, 'result' => $result] = PintAE::build($invoice);   // generate + validate

if (!$result->isValid()) {
    foreach ($result->errors() as $e) {
        echo "[{$e['rule']}] {$e['message']}\n";
    }
}
```

The `examples/invoices/` folder has a ready-made input for each of the 17 scenarios, and `examples/xml/` has the XML each one produces.

## Scenarios

Add the scenario to `modifiers`. The library then sets the transaction flags (BTAE-02), type code and CustomizationID for you.

| Scenario | How | Type code | Flags |
|---|---|---|---|
| Standard tax invoice | `modifiers: []` | 380 | `00000000` |
| Credit note | `type: credit_note` + `credit_reason` + `preceding_invoice` | 381 | `00000000` |
| Volume-discount credit note | `credit_reason.code: VD`, no `preceding_invoice` | 381 | `00000000` |
| Self-billed invoice | `['self_billed']` | 389 | `00000000` |
| Self-billed credit note | `type: credit_note`, `['self_billed']` | 261 | `00000000` |
| Out of scope | `['out_of_scope']`, lines `E`/`O`/`Z` | 480 / 81 | `00000000` |
| Free trade zone | `['free_trade_zone']` + `beneficiary_id` | 380 | `10000000` |
| Deemed supply | `['deemed_supply']` | 380 | `01000000` |
| Margin scheme | `['margin_scheme']`, lines `category: N` | 380 | `00100000` |
| Summary invoice | `['summary']` + `period` | 380 | `00010000` |
| Continuous supply | `['continuous_supply']` + `period` | 380 | `00001000` |
| Disclosed agent | `['disclosed_agent']` + `principal_id` | 380 | `00000100` |
| E-commerce | `['ecommerce']` + `delivery.address` | 380 | `00000010` |
| Export | `['export']`, lines `category: Z` | 380 | `00000001` |
| Reverse charge | lines `category: AE` + `rcm_type` + `item_code` (0160) | 380 | `00000000` |
| Discounts and charges | `allowances` / `charges` on lines or the document | any | any |
| Foreign currency | `currency: USD` + `exchange_rate` | any | any |

Flags combine. For example `['free_trade_zone', 'export']` gives `10000001`.

## Input reference

<details>
<summary><strong>Document fields</strong></summary>

| Field | Notes |
|---|---|
| `type` | `invoice` (default) or `credit_note` |
| `modifiers` | see the scenarios table |
| `number` | required |
| `uuid` | BTAE-07. Generated for you if missing. |
| `issue_date`, `due_date`, `issue_time` | `YYYY-MM-DD`. `due_date` is required when an amount is due (IBR-127-AE). |
| `currency` | ISO 4217. Default `AED`. |
| `exchange_rate` | AED per 1 unit of `currency` (BTAE-04). Required when currency is not AED. |
| `note`, `buyer_reference`, `order_reference` | optional |
| `period` | `{start, end, frequency}`. `frequency` is BTAE-06: `DLY`, `WKY`, `Q15`, `MTH`, `Q45`, `Q60`, `QTR`, `YRL`, `HYR` or `OTH`. Aliases such as `MONTHLY` also work. |
| `preceding_invoice` | `{number, issue_date}`. Credit notes. |
| `credit_reason` | `{code, text}`. BTAE-03. See the code lists below. |
| `beneficiary_id` | BTAE-01. Free zone. |
| `principal_id` | BTAE-14. Disclosed agent. |
| `contract_value`, `contract_reference` | BTAE-05 |
| `delivery` | `{date, address{…}, terms{incoterms, customs_declaration}}` (BTAE-22 / BTAE-21) |
| `payment` | `{means_code, payment_id, iban, account_name, bic, terms}` |
| `allowances`, `charges` | document-level: `[{amount \| percent, base, reason, reason_code, category, rate, exemption_code}]` |
| `prepaid`, `rounding` | optional amounts |
| `documents` | extra `AdditionalDocumentReference`s: `[{id, type_code, type, description}]` |
| `include_aed_total` | default `true`. Writes BTAE-20 (total with VAT in AED). |
</details>

<details>
<summary><strong>Party fields (<code>seller</code>, <code>buyer</code>)</strong></summary>

| Field | Notes |
|---|---|
| `name` | legal name |
| `trn` | VAT number: 15 digits, starting with `1` and ending with `03` |
| `tin` | seller only: 10-digit tax registration number `1XXXXXXXXX` |
| `endpoint` | `{scheme: '0235', id: TIN}`, the Peppol address |
| `registration` | `{type: TL\|EID\|PAS\|CD, id, authority}`. For `TL`, `authority` is the issuing authority. For `PAS`, it is the issuing country, e.g. `GB`. |
| `address` | `{line1, line2, city, postal_code, emirate, country}`. In the UAE `emirate` is one of `AUH DXB SHJ AJM UAQ RAK FUJ`. Abroad, use the state or region code (`subdivision` also works). |
| `contact` | `{name, phone, email}` |
| `trade_name`, `id` | optional |
</details>

<details>
<summary><strong>Line fields</strong></summary>

| Field | Notes |
|---|---|
| `id` | defaults to 1, 2, 3… |
| `name`, `description` | description defaults to name (IBR-125-AE) |
| `quantity`, `unit_code` | UN/ECE Rec 20, for example `C62`, `HUR`, `KGM` |
| `unit_price` | net price |
| `gross_price` | defaults to `unit_price`. The difference is sent as the price discount. |
| `base_quantity` | default 1 |
| `category` | `S` (5%), `Z`, `E`, `O`, `AE` (reverse charge), `N` (margin scheme) |
| `rate` | defaults to 5 for `S` and 0 otherwise |
| `exemption_code`, `exemption_reason` | required for `E`, e.g. `DL8.46.2` |
| `allowances`, `charges` | `[{amount \| percent, base, reason, reason_code}]`. The shortcuts `allowance: 50` and `charge: 20` also work. |
| `item_code` | `{id, scheme: '0160'}` |
| `classification` | `{code, scheme: 'HS'}` |
| `item_type` | BTAE-13: `G`, `S` or `B` |
| `service_code` | BTAE-17 (SAC) |
| `rcm_type` | BTAE-09 for reverse charge, for example `DL8.48.8.2` |
</details>

<details>
<summary><strong>Code lists and aliases</strong></summary>

| List | Official codes | Aliases you can use |
|---|---|---|
| Credit note reason | `DL8.61.1.A` cancelled · `B` tax treatment changed · `C` consideration altered · `D` goods/services returned · `E` tax charged in error · `VD` volume discount | `SUPPLY_CANCELLED`, `CONSIDERATION_ALTERED`, `GOODS_OR_SERVICES_RETURNED`, `TAX_ERROR`, `VOLUME_DISCOUNT` … |
| Billing frequency | `DLY WKY Q15 MTH Q45 Q60 QTR YRL HYR OTH` | `DAILY`, `WEEKLY`, `MONTHLY`, `QUARTERLY`, `YEARLY`, `HALF_YEARLY`, `OTHERS` |
| Reverse-charge goods | `DL8.48.8.2` electronics · `DL8.48.8.1` gold & diamonds · `DL8.48.3.1` oil · `DL8.48.3.2` natural gas · `DL8.48.3.3` hydrocarbons | `ELECTRONIC_DEVICES`, `GOLD`, `CRUDE_OIL`, `NATURAL_GAS`, `HYDROCARBONS` |
| Exemption reason | `DL8.46.1` financial services · `DL8.46.2` residential units · `DL8.46.3` bare land · `DL8.46.4` local passenger transport | |
| Item type | `G` goods · `S` services · `B` both | `GOODS`, `SERVICES`, `BOTH` |
| Payment means | `1 10 20 21 30 49 54 55 68` | `CASH`, `CHEQUE`, `CREDIT_TRANSFER`, `DIRECT_DEBIT`, `CREDIT_CARD`, `DEBIT_CARD`, `ONLINE` |

These are all in `src/Codes.php`, taken from the official PINT AE resources (Billing 1.0.4).
</details>

## Command line

```bash
vendor/bin/pint-ae generate invoice.json out.xml    # build + validate
vendor/bin/pint-ae validate out.xml                 # human-readable report
vendor/bin/pint-ae validate out.xml --json          # machine-readable
vendor/bin/pint-ae to-json out.xml                  # XML → JSON
vendor/bin/pint-ae totals invoice.json              # just the maths
```

The exit code is `0` when valid and `1` when there are validation errors, so it can gate a CI pipeline.

## Playground

Upload the folder to any PHP 8.1+ host and open `/demo/`. You can paste JSON to get XML, or paste XML to get a validation report. It writes no files and needs no database.

## Where the UAE fields go

| Term | XML |
|---|---|
| BTAE-01 Beneficiary ID | `cac:BuyerCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID` |
| BTAE-02 Transaction type | `cbc:ProfileExecutionID` |
| BTAE-03 Credit note reason | `cac:DiscrepancyResponse/cbc:ResponseCode` |
| BTAE-04 Exchange rate | `cac:TaxExchangeRate/cbc:CalculationRate` |
| BTAE-05 Contract value | `cac:ContractDocumentReference/cbc:DocumentDescription` |
| BTAE-06 Billing frequency | `cac:InvoicePeriod/cbc:Description` |
| BTAE-07 Unique identifier | `cbc:UUID` |
| BTAE-08 Line VAT in AED | `cac:InvoiceLine/cac:ItemPriceExtension/cac:TaxTotal/cbc:TaxAmount` |
| BTAE-09 RCM goods type | `cac:Item/cac:CommodityClassification/cbc:NatureCode` |
| BTAE-10 Line amount in AED | `cac:InvoiceLine/cac:ItemPriceExtension/cbc:Amount` |
| BTAE-11/12 Authority name | `cac:PartyLegalEntity/cbc:CompanyID/@schemeAgencyName` |
| BTAE-13 Item type | `cac:Item/cac:CommodityClassification/cbc:CommodityCode` |
| BTAE-14 Principal ID | `cac:SellerSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID` |
| BTAE-15/16 Registration type | `cac:PartyLegalEntity/cbc:CompanyID/@schemeAgencyID` |
| BTAE-17 Service accounting code | `cac:Item/cac:AdditionalItemIdentification/cbc:ID[@schemeID='SAC']` |
| BTAE-20 Total incl. VAT in AED | `cac:AdditionalDocumentReference[cbc:DocumentTypeCode='aedtotal-incl-vat']/cbc:DocumentDescription` |
| BTAE-21 Customs reference | `cac:StatementDocumentReference/cbc:ID` |
| BTAE-22 Incoterms | `cac:Delivery/cac:DeliveryTerms/cbc:ID` |

The source for every path is the [PINT AE semantic model](https://docs.peppol.eu/poac/ae/pint-ae/trn-invoice/semantic-model/) and the official example files.

## Tests

```bash
php tests/run.php                  # 134 checks, no dependencies
pip install saxonche
python3 tests/official.py          # official PINT AE Schematron (downloads resources.zip)
```

`tests/run.php` checks every scenario against the UBL 2.1 XSD (bundled in `tests/schema`), the business rules and the expected totals. It also runs 41 deliberately broken invoices that must each trigger the right rule.

`tests/official.py` runs the official Schematron on the generated examples, then makes sure the library accepts all 30 official example documents. Self-billing documents (389 / 261) belong to the separate PINT AE Self-Billing spec, so the Billing Schematron is not run on them.

## What this is not

- **Not an ASP.** It builds and checks the XML. Sending it to the FTA must go through an MoF-accredited service provider under the 5-corner model.
- **Not legal or tax advice.** You still decide the VAT treatment. The library only encodes it correctly.

## Roadmap

- [ ] Run the PINT AE Self-Billing Schematron in CI
- [ ] PDF rendering of the invoice (English + Arabic)
- [ ] npm / TypeScript port
- [ ] Laravel service provider

## Contributing

Pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md). Please never commit real TRNs or customer data.

## License

MIT © Sahil Zaini / Zaini Developers

<sub>Designed &amp; Developed by Sahil Zaini / Zaini Developers · Powered by YCS Trust</sub>
