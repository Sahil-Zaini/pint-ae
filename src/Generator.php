<?php

declare(strict_types=1);

namespace PintAE;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Builds a PINT AE (UBL 2.1) Invoice or CreditNote from a plain PHP array.
 * Line amounts, VAT breakdown, AED amounts and document totals are calculated.
 *
 * Element order follows the UBL 2.1 XSD sequence; UAE fields (BTAE-xx) use the
 * syntax bindings published at docs.peppol.eu/poac/ae/pint-ae.
 */
final class Generator
{
    private DOMDocument $doc;

    /** @var array<string,mixed> */
    private array $options;

    /**
     * Options:
     *  - format (bool, default true)        pretty-print the XML
     *  - customization / profile (string)   override the PINT AE identifiers
     *  - auto_endpoints (bool, default true) use the predefined receiver endpoint
     *    (9900000099 exports, 9900000097 deemed supply) when the buyer has none
     *  - auto_uuid (bool, default true)     generate the UUID (BTAE-07) if missing
     *
     * @param array<string,mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = $options + ['format' => true, 'auto_endpoints' => true, 'auto_uuid' => true];
    }

    /** @param array<string,mixed> $inv */
    public function generate(array $inv): string
    {
        if (empty($inv['number'])) {
            throw new InvalidArgumentException('Invoice "number" is required.');
        }
        if (empty($inv['lines']) || !is_array($inv['lines'])) {
            throw new InvalidArgumentException('Invoice needs at least one line in "lines".');
        }

        $calc = Calculator::calculate($inv);
        $isCredit = ($inv['type'] ?? 'invoice') === 'credit_note';
        $mods = array_values((array) ($inv['modifiers'] ?? []));
        $selfBilled = in_array('self_billed', $mods, true) || !empty($inv['self_billed']);
        $outOfScope = in_array('out_of_scope', $mods, true);
        $currency = strtoupper($inv['currency'] ?? Codes::ACCOUNTING_CURRENCY);
        $foreign = $currency !== Codes::ACCOUNTING_CURRENCY;
        $flags = $inv['transaction_flags'] ?? Codes::flagsFromModifiers($mods);

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = (bool) $this->options['format'];
        $root = $this->doc->createElementNS($isCredit ? Codes::NS_CREDITNOTE : Codes::NS_INVOICE, $isCredit ? 'CreditNote' : 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', Codes::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', Codes::NS_CBC);
        $this->doc->appendChild($root);

        // ---- header ------------------------------------------------------
        $this->cbc($root, 'CustomizationID', $this->options['customization']
            ?? ($selfBilled ? Codes::CUSTOMIZATION_SELFBILLING : Codes::CUSTOMIZATION_BILLING));
        $this->cbc($root, 'ProfileID', $this->options['profile']
            ?? ($selfBilled ? Codes::PROFILE_SELFBILLING : Codes::PROFILE_BILLING));
        $this->cbc($root, 'ProfileExecutionID', $flags);                       // BTAE-02
        $this->cbc($root, 'ID', (string) $inv['number']);
        $uuid = $inv['uuid'] ?? ($this->options['auto_uuid'] ? self::uuid4() : null);
        $this->cbc($root, 'UUID', $uuid);                                       // BTAE-07
        $this->cbc($root, 'IssueDate', $inv['issue_date'] ?? date('Y-m-d'));
        $this->cbc($root, 'IssueTime', $inv['issue_time'] ?? null);
        if (!$isCredit) {
            $this->cbc($root, 'DueDate', $inv['due_date'] ?? null);
            $this->cbc($root, 'InvoiceTypeCode', $inv['type_code'] ?? Codes::typeCode('invoice', $selfBilled, $outOfScope));
        } else {
            $this->cbc($root, 'CreditNoteTypeCode', $inv['type_code'] ?? Codes::typeCode('credit_note', $selfBilled, $outOfScope));
        }
        $this->cbc($root, 'Note', $inv['note'] ?? null);
        $this->cbc($root, 'DocumentCurrencyCode', $currency);
        if ($foreign) {
            $this->cbc($root, 'TaxCurrencyCode', Codes::ACCOUNTING_CURRENCY);
        }
        $this->cbc($root, 'BuyerReference', $inv['buyer_reference'] ?? null);

        if (!empty($inv['period'])) {
            $p = $this->cac($root, 'InvoicePeriod');
            $this->cbc($p, 'StartDate', $inv['period']['start'] ?? null);
            $this->cbc($p, 'EndDate', $inv['period']['end'] ?? null);
            $this->cbc($p, 'Description', Codes::alias(Codes::FREQUENCY_ALIASES, $inv['period']['frequency'] ?? null)); // BTAE-06
        }

        if ($isCredit && !empty($inv['credit_reason'])) {
            $d = $this->cac($root, 'DiscrepancyResponse');
            $this->cbc($d, 'ResponseCode', Codes::alias(Codes::CREDIT_REASON_ALIASES, $inv['credit_reason']['code'] ?? null)); // BTAE-03
            $this->cbc($d, 'Description', $inv['credit_reason']['text'] ?? null);
        }

        if (!empty($inv['order_reference'])) {
            $this->cbc($this->cac($root, 'OrderReference'), 'ID', (string) $inv['order_reference']);
        }
        if (!empty($inv['preceding_invoice']['number'])) {
            $b = $this->cac($this->cac($root, 'BillingReference'), 'InvoiceDocumentReference');
            $this->cbc($b, 'ID', (string) $inv['preceding_invoice']['number']);
            $this->cbc($b, 'IssueDate', $inv['preceding_invoice']['issue_date'] ?? null);
        }

        // Document references — sequence differs between Invoice and CreditNote.
        $customs = $inv['delivery']['terms']['customs_declaration'] ?? ($inv['customs_reference'] ?? null);
        $refs = [
            'statement' => fn () => $customs ? $this->cbc($this->cac($root, 'StatementDocumentReference'), 'ID', (string) $customs) : null, // BTAE-21
            'contract' => function () use ($root, $inv) {                                                                                   // BTAE-05
                if (!isset($inv['contract_value']) && empty($inv['contract_reference'])) {
                    return;
                }
                $c = $this->cac($root, 'ContractDocumentReference');
                $this->cbc($c, 'ID', (string) ($inv['contract_reference'] ?? 'contract'));
                $this->cbc($c, 'DocumentDescription', isset($inv['contract_value']) ? Money::fmt($inv['contract_value']) : null);
            },
            'additional' => function () use ($root, $inv, $calc, $foreign) {
                // BTAE-20 total incl. VAT in AED (required for foreign currency, harmless otherwise)
                if ($foreign || ($inv['include_aed_total'] ?? true)) {
                    $a = $this->cac($root, 'AdditionalDocumentReference');
                    $this->cbc($a, 'ID', 'BTAE-20');
                    $this->cbc($a, 'DocumentTypeCode', Codes::AED_TOTAL_DOC_TYPE);
                    $this->cbc($a, 'DocumentDescription', Money::fmt($calc['totals']['tax_inclusive_aed']));
                }
                foreach ((array) ($inv['documents'] ?? []) as $doc) {
                    if (empty($doc['id'])) {
                        continue;
                    }
                    $a = $this->cac($root, 'AdditionalDocumentReference');
                    $this->cbc($a, 'ID', (string) $doc['id']);
                    $this->cbc($a, 'DocumentTypeCode', $doc['type_code'] ?? null);
                    $this->cbc($a, 'DocumentType', $doc['type'] ?? null);
                    $this->cbc($a, 'DocumentDescription', isset($doc['description']) ? (string) $doc['description'] : null);
                }
            },
        ];
        foreach ($isCredit ? ['contract', 'additional', 'statement'] : ['statement', 'contract', 'additional'] as $k) {
            $refs[$k]();
        }

        // ---- parties -----------------------------------------------------
        $buyer = $inv['buyer'] ?? [];
        if ($this->options['auto_endpoints'] && empty($buyer['endpoint']['id'])) {
            if (in_array('export', $mods, true) && ($buyer['address']['country'] ?? 'AE') !== 'AE') {
                $buyer['endpoint'] = ['scheme' => Codes::ENDPOINT_SCHEME, 'id' => Codes::ENDPOINT_EXPORT];
            } elseif (in_array('deemed_supply', $mods, true)) {
                $buyer['endpoint'] = ['scheme' => Codes::ENDPOINT_SCHEME, 'id' => Codes::ENDPOINT_DEEMED_SUPPLY];
            }
        }
        $this->party($this->cac($root, 'AccountingSupplierParty'), $inv['seller'] ?? []);
        $this->party($this->cac($root, 'AccountingCustomerParty'), $buyer);
        if (!empty($inv['beneficiary_id'])) {                                     // BTAE-01
            $this->cbc($this->cac($this->cac($this->cac($root, 'BuyerCustomerParty'), 'Party'), 'PartyIdentification'), 'ID', (string) $inv['beneficiary_id']);
        }
        if (!empty($inv['principal_id'])) {                                       // BTAE-14
            $this->cbc($this->cac($this->cac($this->cac($root, 'SellerSupplierParty'), 'Party'), 'PartyIdentification'), 'ID', (string) $inv['principal_id']);
        }

        if (!empty($inv['delivery'])) {
            $dv = $inv['delivery'];
            $d = $this->cac($root, 'Delivery');
            $this->cbc($d, 'ActualDeliveryDate', $dv['date'] ?? null);
            if (!empty($dv['address'])) {
                $this->address($this->cac($this->cac($d, 'DeliveryLocation'), 'Address'), $dv['address']);
            }
            if (!empty($dv['terms']['incoterms'])) {
                $this->cbc($this->cac($d, 'DeliveryTerms'), 'ID', $dv['terms']['incoterms']); // BTAE-22
            }
        }

        // ---- payment -----------------------------------------------------
        if (!empty($inv['payment'])) {
            $pay = $inv['payment'];
            $code = Codes::paymentMeans($pay['means_code'] ?? '30');
            $pm = $this->cac($root, 'PaymentMeans');
            $c = $this->cbc($pm, 'PaymentMeansCode', $code);
            if ($c && isset(Codes::PAYMENT_MEANS[$code])) {
                $c->setAttribute('name', Codes::PAYMENT_MEANS[$code]);
            }
            if ($isCredit) {
                $this->cbc($pm, 'PaymentDueDate', $inv['due_date'] ?? null);
            }
            $this->cbc($pm, 'PaymentID', $pay['payment_id'] ?? null);
            if (!empty($pay['iban'])) {
                $acc = $this->cac($pm, 'PayeeFinancialAccount');
                $this->cbc($acc, 'ID', str_replace(' ', '', (string) $pay['iban']));
                $this->cbc($acc, 'Name', $pay['account_name'] ?? null);
                if (!empty($pay['bic'])) {
                    $this->cbc($this->cac($acc, 'FinancialInstitutionBranch'), 'ID', $pay['bic']);
                }
            }
            if (!empty($pay['terms'])) {
                $this->cbc($this->cac($root, 'PaymentTerms'), 'Note', $pay['terms']);
            }
        }
        if (!$isCredit) {
            $this->docAllowanceCharges($root, $calc, $currency);
        }

        if ($foreign) {                                                          // BTAE-04
            $x = $this->cac($root, 'TaxExchangeRate');
            $this->cbc($x, 'SourceCurrencyCode', $currency);
            $this->cbc($x, 'TargetCurrencyCode', Codes::ACCOUNTING_CURRENCY);
            $this->cbc($x, 'CalculationRate', Money::qty($calc['totals']['exchange_rate']));
            $this->cbc($x, 'MathematicOperatorCode', 'Multiply');
        }
        if ($isCredit) {
            $this->docAllowanceCharges($root, $calc, $currency);
        }

        // ---- totals ------------------------------------------------------
        $this->taxTotal($root, $calc, $currency, false);
        if ($foreign) {
            $this->taxTotal($root, $calc, Codes::ACCOUNTING_CURRENCY, true);
        }
        $t = $calc['totals'];
        $m = $this->cac($root, 'LegalMonetaryTotal');
        $this->amount($m, 'LineExtensionAmount', $t['line_extension'], $currency);
        $this->amount($m, 'TaxExclusiveAmount', $t['tax_exclusive'], $currency);
        $this->amount($m, 'TaxInclusiveAmount', $t['tax_inclusive'], $currency);
        $this->amount($m, 'AllowanceTotalAmount', $t['allowances'], $currency);
        $this->amount($m, 'ChargeTotalAmount', $t['charges'], $currency);
        if ($t['prepaid'] > 0) {
            $this->amount($m, 'PrepaidAmount', $t['prepaid'], $currency);
        }
        if ($t['rounding'] != 0.0) {
            $this->amount($m, 'PayableRoundingAmount', $t['rounding'], $currency);
        }
        $this->amount($m, 'PayableAmount', $t['payable'], $currency);

        foreach ($calc['lines'] as $line) {
            $this->line($root, $line, $currency, $isCredit);
        }

        return (string) $this->doc->saveXML();
    }

    /** @param array<string,mixed> $p */
    private function party(DOMElement $wrap, array $p): void
    {
        $party = $this->cac($wrap, 'Party');
        if (!empty($p['endpoint']['id'])) {
            $this->cbc($party, 'EndpointID', (string) $p['endpoint']['id'])
                ?->setAttribute('schemeID', (string) ($p['endpoint']['scheme'] ?? Codes::ENDPOINT_SCHEME));
        }
        if (!empty($p['id'])) {
            $this->cbc($this->cac($party, 'PartyIdentification'), 'ID', (string) $p['id']);
        }
        if (!empty($p['trade_name'])) {
            $this->cbc($this->cac($party, 'PartyName'), 'Name', $p['trade_name']);
        }
        $this->address($this->cac($party, 'PostalAddress'), $p['address'] ?? []);
        if (!empty($p['trn'])) {
            $ts = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($ts, 'CompanyID', (string) $p['trn']);
            $this->cbc($this->cac($ts, 'TaxScheme'), 'ID', 'VAT');
        }
        if (!empty($p['tin'])) {                                                 // ibt-032 seller tax registration (TIN)
            $ts = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($ts, 'CompanyID', (string) $p['tin']);
            $this->cbc($this->cac($ts, 'TaxScheme'), 'ID', 'TIN');
        }
        $le = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($le, 'RegistrationName', $p['name'] ?? '');
        if (!empty($p['registration']['id'])) {
            $c = $this->cbc($le, 'CompanyID', (string) $p['registration']['id']);
            if ($c && !empty($p['registration']['type'])) {
                $c->setAttribute('schemeAgencyID', $p['registration']['type']);     // BTAE-15 / BTAE-16
            }
            if ($c && !empty($p['registration']['authority'])) {
                $c->setAttribute('schemeAgencyName', $p['registration']['authority']); // BTAE-12 / BTAE-11
            }
        }
        if (!empty($p['contact'])) {
            $c = $this->cac($party, 'Contact');
            $this->cbc($c, 'Name', $p['contact']['name'] ?? null);
            $this->cbc($c, 'Telephone', $p['contact']['phone'] ?? null);
            $this->cbc($c, 'ElectronicMail', $p['contact']['email'] ?? null);
        }
    }

    /** @param array<string,mixed> $a */
    private function address(DOMElement $el, array $a): void
    {
        $this->cbc($el, 'StreetName', $a['line1'] ?? null);
        $this->cbc($el, 'AdditionalStreetName', $a['line2'] ?? null);
        $this->cbc($el, 'CityName', $a['city'] ?? null);
        $this->cbc($el, 'PostalZone', $a['postal_code'] ?? null);
        $this->cbc($el, 'CountrySubentity', $a['emirate'] ?? $a['subdivision'] ?? $a['state'] ?? null);
        $this->cbc($this->cac($el, 'Country'), 'IdentificationCode', strtoupper($a['country'] ?? 'AE'));
    }

    private function taxTotal(DOMElement $root, array $calc, string $currency, bool $aed): void
    {
        $tt = $this->cac($root, 'TaxTotal');
        $this->amount($tt, 'TaxAmount', $aed ? $calc['totals']['tax_aed'] : $calc['totals']['tax'], $currency);
        if ($aed) {
            return; // AED TaxTotal carries the amount only
        }
        foreach ($calc['subtotals'] as $s) {
            $st = $this->cac($tt, 'TaxSubtotal');
            $this->amount($st, 'TaxableAmount', $s['taxable'], $currency);
            $this->amount($st, 'TaxAmount', $s['tax'], $currency);
            $this->taxCategory($this->cac($st, 'TaxCategory'), $s);
        }
    }

    private function taxCategory(DOMElement $el, array $c): void
    {
        $this->cbc($el, 'ID', $c['category']);
        if (!in_array($c['category'], Codes::NO_RATE_CATEGORIES, true)) {
            $this->cbc($el, 'Percent', Money::qty($c['rate']));
        }
        if ($c['category'] !== 'S') { // ALIGNED-IBRP-S-10: no exemption reason on S
            $this->cbc($el, 'TaxExemptionReasonCode', $c['exemption_code'] ?? null);
            $this->cbc($el, 'TaxExemptionReason', $c['exemption_reason'] ?? null);
        }
        $this->cbc($this->cac($el, 'TaxScheme'), 'ID', 'VAT');
    }

    private function line(DOMElement $root, array $l, string $currency, bool $isCredit): void
    {
        $el = $this->cac($root, $isCredit ? 'CreditNoteLine' : 'InvoiceLine');
        $this->cbc($el, 'ID', (string) $l['id']);
        $this->cbc($el, 'Note', $l['note'] ?? null);
        $this->cbc($el, $isCredit ? 'CreditedQuantity' : 'InvoicedQuantity', Money::qty($l['quantity']))
            ?->setAttribute('unitCode', $l['unit_code'] ?? 'C62');
        $this->amount($el, 'LineExtensionAmount', $l['net'], $currency);
        foreach (array_merge($l['allowances'], $l['charges']) as $ac) {
            $this->allowanceCharge($el, $ac, $currency, null);
        }

        $item = $this->cac($el, 'Item');
        $this->cbc($item, 'Description', $l['description'] ?? ($l['name'] ?? null)); // IBR-125-AE
        $this->cbc($item, 'Name', $l['name'] ?? ($l['description'] ?? 'Item'));
        if (!empty($l['seller_item_id'])) {
            $this->cbc($this->cac($item, 'SellersItemIdentification'), 'ID', (string) $l['seller_item_id']);
        }
        if (!empty($l['item_code']['id'])) {
            $this->cbc($this->cac($item, 'StandardItemIdentification'), 'ID', (string) $l['item_code']['id'])
                ?->setAttribute('schemeID', (string) ($l['item_code']['scheme'] ?? '0160'));
        }
        if (!empty($l['service_code'])) {                                        // BTAE-17
            $this->cbc($this->cac($item, 'AdditionalItemIdentification'), 'ID', (string) $l['service_code'])
                ?->setAttribute('schemeID', 'SAC');
        }
        $rcm = Codes::alias(Codes::RCM_ALIASES, $l['rcm_type'] ?? null);
        $itemType = Codes::alias(Codes::ITEM_TYPE_ALIASES, $l['item_type'] ?? null);
        if ($rcm || $itemType || !empty($l['classification']['code'])) {
            $cc = $this->cac($item, 'CommodityClassification');
            $this->cbc($cc, 'NatureCode', $rcm);                                  // BTAE-09
            $this->cbc($cc, 'CommodityCode', $itemType);                          // BTAE-13
            if (!empty($l['classification']['code'])) {
                $this->cbc($cc, 'ItemClassificationCode', (string) $l['classification']['code'])
                    ?->setAttribute('listID', (string) ($l['classification']['scheme'] ?? 'HS'));
            }
        }
        $this->taxCategory($this->cac($item, 'ClassifiedTaxCategory'), $l);      // IBG-30

        $price = $this->cac($el, 'Price');
        $this->amount($price, 'PriceAmount', $l['unit_price'], $currency, 6);
        $this->cbc($price, 'BaseQuantity', Money::qty($l['base_quantity']))      // IBR-126-AE
            ?->setAttribute('unitCode', $l['unit_code'] ?? 'C62');
        $ac = $this->cac($price, 'AllowanceCharge');                            // gross price (ibt-148)
        $this->cbc($ac, 'ChargeIndicator', 'false');
        $this->amount($ac, 'Amount', $l['price_discount'], $currency, 6);
        $this->amount($ac, 'BaseAmount', $l['gross_price'], $currency, 6);

        $ext = $this->cac($el, 'ItemPriceExtension');
        $this->amount($ext, 'Amount', $l['gross_aed'], Codes::ACCOUNTING_CURRENCY);  // BTAE-10
        if ($l['category'] !== 'E') {                                               // IBR-163-AE
            $this->amount($this->cac($ext, 'TaxTotal'), 'TaxAmount', $l['tax_aed'], Codes::ACCOUNTING_CURRENCY); // BTAE-08
        }
    }

    private function docAllowanceCharges(DOMElement $root, array $calc, string $currency): void
    {
        foreach (array_merge($calc['allowances'], $calc['charges']) as $ac) {
            $this->allowanceCharge($root, $ac, $currency, $ac);
        }
    }

    /** UBL AllowanceCharge; $tax is set for document-level items (they carry a TaxCategory). */
    private function allowanceCharge(DOMElement $parent, array $ac, string $currency, ?array $tax): void
    {
        $el = $this->cac($parent, 'AllowanceCharge');
        $this->cbc($el, 'ChargeIndicator', $ac['charge'] ? 'true' : 'false');
        $this->cbc($el, 'AllowanceChargeReasonCode', isset($ac['reason_code']) ? (string) $ac['reason_code'] : null);
        $this->cbc($el, 'AllowanceChargeReason', $ac['reason'] ?? null);
        if ($ac['percent'] !== null) {
            $this->cbc($el, 'MultiplierFactorNumeric', Money::qty($ac['percent']));
        }
        $this->amount($el, 'Amount', $ac['amount'], $currency);
        if ($ac['base'] !== null) {
            $this->amount($el, 'BaseAmount', $ac['base'], $currency);
        }
        if ($tax !== null) {
            $tc = $this->cac($el, 'TaxCategory');
            $this->cbc($tc, 'ID', $tax['category']);
            if ($tax['category'] !== 'O') { // ALIGNED-IBRP-O-06/07: no rate on O
                $this->cbc($tc, 'Percent', Money::qty($tax['rate']));
            }
            if ($tax['category'] !== 'S') {
                $this->cbc($tc, 'TaxExemptionReasonCode', $tax['exemption_code'] ?? null);
                $this->cbc($tc, 'TaxExemptionReason', $tax['exemption_reason'] ?? null);
            }
            $this->cbc($this->cac($tc, 'TaxScheme'), 'ID', 'VAT');
        }
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ---- DOM helpers -------------------------------------------------------

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        $el = $this->doc->createElementNS(Codes::NS_CAC, 'cac:' . $name);
        $parent->appendChild($el);
        return $el;
    }

    private function cbc(DOMElement $parent, string $name, ?string $value): ?DOMElement
    {
        if ($value === null || $value === '') {
            return null;
        }
        $el = $this->doc->createElementNS(Codes::NS_CBC, 'cbc:' . $name);
        $el->appendChild($this->doc->createTextNode($value));
        $parent->appendChild($el);
        return $el;
    }

    private function amount(DOMElement $parent, string $name, float $value, string $currency, int $dp = 2): void
    {
        $v = $dp === 2 ? Money::fmt($value) : Money::qty($value);
        $this->cbc($parent, $name, $v)?->setAttribute('currencyID', $currency);
    }
}
