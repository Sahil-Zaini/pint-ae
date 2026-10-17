<?php

declare(strict_types=1);

namespace PintAE;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Reads a PINT AE / UBL 2.1 Invoice or CreditNote into a plain array.
 * The array mirrors the generator's input shape and adds the amounts that
 * are *stated* in the XML (so the validator can check them).
 */
final class Reader
{
    private DOMXPath $x;

    /** @return array<string,mixed> */
    public static function read(string $xml): array
    {
        return (new self())->parse($xml);
    }

    /** @return array<string,mixed> */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new RuntimeException('Empty XML.');
        }
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // LIBXML_NONET: never fetch external resources while parsing
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || !$dom->documentElement) {
            $msg = $errors ? trim($errors[0]->message) . ' (line ' . $errors[0]->line . ')' : 'unknown error';
            throw new RuntimeException('XML is not well-formed: ' . $msg);
        }

        $root = $dom->documentElement;
        $this->x = new DOMXPath($dom);
        $this->x->registerNamespace('cac', Codes::NS_CAC);
        $this->x->registerNamespace('cbc', Codes::NS_CBC);

        $isCredit = $root->localName === 'CreditNote';
        $typeCode = $this->v($isCredit ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode', $root);
        [$docType, $selfBilled, $outOfScope] = Codes::describeTypeCode((string) $typeCode);
        $flags = $this->v('cbc:ProfileExecutionID', $root);

        $mods = $flags && preg_match('/^[01]{8}$/', $flags) ? Codes::modifiersFromFlags($flags) : [];
        if ($selfBilled) {
            $mods[] = 'self_billed';
        }
        if ($outOfScope) {
            $mods[] = 'out_of_scope';
        }

        $out = [
            '_root' => $root->localName,
            '_namespace' => $root->namespaceURI,
            'type' => $isCredit ? 'credit_note' : 'invoice',
            'type_code' => $typeCode,
            'customization' => $this->v('cbc:CustomizationID', $root),
            'profile' => $this->v('cbc:ProfileID', $root),
            'transaction_flags' => $flags,
            'modifiers' => $mods,
            'number' => $this->v('cbc:ID', $root),
            'uuid' => $this->v('cbc:UUID', $root),
            'issue_date' => $this->v('cbc:IssueDate', $root),
            'issue_time' => $this->v('cbc:IssueTime', $root),
            'tax_point_date' => $this->v('cbc:TaxPointDate', $root),
            'due_date' => $this->v('cbc:DueDate', $root) ?? $this->v('cac:PaymentMeans/cbc:PaymentDueDate', $root),
            'note' => $this->v('cbc:Note', $root),
            'currency' => $this->v('cbc:DocumentCurrencyCode', $root),
            'tax_currency' => $this->v('cbc:TaxCurrencyCode', $root),
            'buyer_reference' => $this->v('cbc:BuyerReference', $root),
        ];

        if ($p = $this->node('cac:InvoicePeriod', $root)) {
            $out['period'] = ['start' => $this->v('cbc:StartDate', $p), 'end' => $this->v('cbc:EndDate', $p),
                'frequency' => $this->v('cbc:Description', $p)];
        }
        if ($b = $this->node('cac:BillingReference/cac:InvoiceDocumentReference', $root)) {
            $out['preceding_invoice'] = ['number' => $this->v('cbc:ID', $b), 'issue_date' => $this->v('cbc:IssueDate', $b)];
        }
        if ($d = $this->node('cac:DiscrepancyResponse', $root)) {
            $out['credit_reason'] = ['code' => $this->v('cbc:ResponseCode', $d), 'text' => $this->v('cbc:Description', $d)];
        }

        $docs = [];
        foreach ($this->nodes('cac:AdditionalDocumentReference', $root) as $a) {
            $ref = array_filter(['id' => $this->v('cbc:ID', $a), 'type_code' => $this->v('cbc:DocumentTypeCode', $a),
                'type' => $this->v('cbc:DocumentType', $a), 'description' => $this->v('cbc:DocumentDescription', $a)]);
            if (($ref['type_code'] ?? null) === Codes::AED_TOTAL_DOC_TYPE || ($ref['type'] ?? null) === Codes::AED_TOTAL_DOC_TYPE) {
                $out['_stated_aed_total_incl_vat'] = $ref['description'] ?? null;
                continue;
            }
            $docs[] = $ref;
        }
        if ($docs) {
            $out['documents'] = $docs;
        }
        $out['principal_id'] = $this->v('cac:SellerSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID', $root);
        $out['beneficiary_id'] = $this->v('cac:BuyerCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID', $root);
        if ($c = $this->node('cac:ContractDocumentReference', $root)) {
            $out['contract_reference'] = $this->v('cbc:ID', $c);
            $cv = $this->v('cbc:DocumentDescription', $c);
            $out['contract_value'] = $cv !== null && is_numeric($cv) ? (float) $cv : $cv;
        }
        $customs = $this->v('cac:StatementDocumentReference/cbc:ID', $root);
        if ($x = $this->node('cac:TaxExchangeRate', $root)) {
            $out['exchange_rate'] = $this->num('cbc:CalculationRate', $x);
            $out['_exchange_rate_raw'] = $this->v('cbc:CalculationRate', $x);
        }

        $out['seller'] = $this->party($this->node('cac:AccountingSupplierParty/cac:Party', $root));
        $out['buyer'] = $this->party($this->node('cac:AccountingCustomerParty/cac:Party', $root));

        if ($dl = $this->node('cac:Delivery', $root)) {
            $out['delivery'] = array_filter([
                'date' => $this->v('cbc:ActualDeliveryDate', $dl),
                'address' => ($a = $this->node('cac:DeliveryLocation/cac:Address', $dl)) ? $this->address($a) : null,
                'terms' => ($t = $this->v('cac:DeliveryTerms/cbc:ID', $dl)) ? ['incoterms' => $t] : null,
            ]);
        }
        if ($customs !== null) {
            $out['delivery']['terms']['customs_declaration'] = $customs;
        }

        if ($pm = $this->node('cac:PaymentMeans', $root)) {
            $out['payment'] = array_filter([
                'means_code' => $this->v('cbc:PaymentMeansCode', $pm),
                'payment_id' => $this->v('cbc:PaymentID', $pm),
                'iban' => $this->v('cac:PayeeFinancialAccount/cbc:ID', $pm),
                'account_name' => $this->v('cac:PayeeFinancialAccount/cbc:Name', $pm),
                'bic' => $this->v('cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID', $pm),
                'terms' => $this->v('cac:PaymentTerms/cbc:Note', $root),
            ], fn ($v) => $v !== null);
        }

        $out['_allowance_charges'] = [];
        foreach ($this->nodes('cac:AllowanceCharge', $root) as $ac) {
            $out['_allowance_charges'][] = $this->allowanceCharge($ac, true);
        }

        $out['_tax_totals'] = [];
        foreach ($this->nodes('cac:TaxTotal', $root) as $tt) {
            $amt = $this->node('cbc:TaxAmount', $tt);
            $subs = [];
            foreach ($this->nodes('cac:TaxSubtotal', $tt) as $st) {
                $cat = $this->node('cac:TaxCategory', $st);
                $subs[] = [
                    'has_rate' => $cat ? $this->node('cbc:Percent', $cat) !== null : false,
                    'taxable' => $this->num('cbc:TaxableAmount', $st),
                    'tax' => $this->num('cbc:TaxAmount', $st),
                    'category' => $cat ? $this->v('cbc:ID', $cat) : null,
                    'rate' => $cat ? $this->num('cbc:Percent', $cat) : null,
                    'exemption_code' => $cat ? $this->v('cbc:TaxExemptionReasonCode', $cat) : null,
                    'exemption_reason' => $cat ? $this->v('cbc:TaxExemptionReason', $cat) : null,
                    'currency' => $this->attr('cbc:TaxAmount', 'currencyID', $st),
                ];
            }
            $out['_tax_totals'][] = [
                'currency' => $amt?->getAttribute('currencyID') ?: null,
                'amount' => $amt ? (float) $amt->textContent : null,
                'subtotals' => $subs,
            ];
        }

        $m = $this->node('cac:LegalMonetaryTotal', $root);
        $out['_totals'] = [
            'line_extension' => $this->num('cbc:LineExtensionAmount', $m),
            'tax_exclusive' => $this->num('cbc:TaxExclusiveAmount', $m),
            'tax_inclusive' => $this->num('cbc:TaxInclusiveAmount', $m),
            'allowances' => $this->num('cbc:AllowanceTotalAmount', $m) ?? 0.0,
            'charges' => $this->num('cbc:ChargeTotalAmount', $m) ?? 0.0,
            'prepaid' => $this->num('cbc:PrepaidAmount', $m) ?? 0.0,
            'rounding' => $this->num('cbc:PayableRoundingAmount', $m) ?? 0.0,
            'payable' => $this->num('cbc:PayableAmount', $m),
        ];

        $out['lines'] = [];
        foreach ($this->nodes($isCredit ? 'cac:CreditNoteLine' : 'cac:InvoiceLine', $root) as $ln) {
            $qtyNode = $this->node($isCredit ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity', $ln);
            $cat = $this->node('cac:Item/cac:ClassifiedTaxCategory', $ln);
            $line = [
                'id' => $this->v('cbc:ID', $ln),
                'name' => $this->v('cac:Item/cbc:Name', $ln),
                'description' => $this->v('cac:Item/cbc:Description', $ln),
                'quantity' => $qtyNode ? (float) $qtyNode->textContent : null,
                'unit_code' => $qtyNode?->getAttribute('unitCode') ?: null,
                'unit_price' => $this->num('cac:Price/cbc:PriceAmount', $ln),
                'base_quantity' => $this->num('cac:Price/cbc:BaseQuantity', $ln),
                'gross_price' => $this->num('cac:Price/cac:AllowanceCharge[cbc:ChargeIndicator=\'false\']/cbc:BaseAmount', $ln),
                'price_discount' => $this->num('cac:Price/cac:AllowanceCharge[cbc:ChargeIndicator=\'false\']/cbc:Amount', $ln),
                'category' => $cat ? $this->v('cbc:ID', $cat) : null,
                'rate' => $cat ? $this->num('cbc:Percent', $cat) : null,
                'exemption_code' => $cat ? $this->v('cbc:TaxExemptionReasonCode', $cat) : null,
                'exemption_reason' => $cat ? $this->v('cbc:TaxExemptionReason', $cat) : null,
                'item_code' => ($s = $this->node('cac:Item/cac:StandardItemIdentification/cbc:ID', $ln))
                    ? ['id' => $s->textContent, 'scheme' => $s->getAttribute('schemeID')] : null,
                'classification' => ($c = $this->node('cac:Item/cac:CommodityClassification/cbc:ItemClassificationCode', $ln))
                    ? ['code' => $c->textContent, 'scheme' => $c->getAttribute('listID')] : null,
                'rcm_type' => $this->v('cac:Item/cac:CommodityClassification/cbc:NatureCode', $ln),
                'item_type' => $this->v('cac:Item/cac:CommodityClassification/cbc:CommodityCode', $ln),
                'service_code' => $this->v("cac:Item/cac:AdditionalItemIdentification/cbc:ID[@schemeID='SAC']", $ln),
                '_has_rate' => $cat ? $this->node('cbc:Percent', $cat) !== null : false,
                '_allowance_charges' => array_map(fn ($ac) => $this->allowanceCharge($ac, false), $this->nodes('cac:AllowanceCharge', $ln)),
                '_has_sac' => $this->node('cac:Item/cac:AdditionalItemIdentification/cbc:ID', $ln) !== null,
                '_sac_scheme_ok' => $this->node("cac:Item/cac:AdditionalItemIdentification/cbc:ID[not(@schemeID='SAC')]", $ln) === null,
                '_net' => $this->num('cbc:LineExtensionAmount', $ln),
                '_gross_aed' => $this->num('cac:ItemPriceExtension/cbc:Amount', $ln),
                '_ext_currency' => $this->attr('cac:ItemPriceExtension/cbc:Amount', 'currencyID', $ln),
                '_tax_aed' => $this->num('cac:ItemPriceExtension/cac:TaxTotal/cbc:TaxAmount', $ln),
                '_has_price_base' => $this->node('cac:Price/cbc:BaseQuantity', $ln) !== null,
            ];
            $out['lines'][] = array_filter($line, fn ($v) => $v !== null);
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    /** Public, clean JSON (drops internal "_" keys unless $withStated). */
    public static function toArray(string $xml, bool $withStated = false): array
    {
        $data = self::read($xml);
        if ($withStated) {
            return $data;
        }
        $strip = function (array $a) use (&$strip): array {
            $r = [];
            foreach ($a as $k => $v) {
                if (is_string($k) && str_starts_with($k, '_')) {
                    continue;
                }
                $r[$k] = is_array($v) ? $strip($v) : $v;
            }
            return $r;
        };
        return $strip($data);
    }

    private function allowanceCharge(DOMElement $ac, bool $doc): array
    {
        $tc = $this->node('cac:TaxCategory', $ac);
        return [
            'charge' => strtolower((string) $this->v('cbc:ChargeIndicator', $ac)) === 'true',
            'amount' => $this->num('cbc:Amount', $ac),
            'base' => $this->num('cbc:BaseAmount', $ac),
            'percent' => $this->num('cbc:MultiplierFactorNumeric', $ac),
            'reason_code' => $this->v('cbc:AllowanceChargeReasonCode', $ac),
            'reason' => $this->v('cbc:AllowanceChargeReason', $ac),
            'category' => $tc ? $this->v('cbc:ID', $tc) : null,
            'rate' => $tc ? $this->num('cbc:Percent', $tc) : null,
            'has_rate' => $tc ? $this->node('cbc:Percent', $tc) !== null : false,
            'exemption_code' => $tc ? $this->v('cbc:TaxExemptionReasonCode', $tc) : null,
            'doc' => $doc,
        ];
    }

    private function party(?DOMElement $p): array
    {
        if (!$p) {
            return [];
        }
        $ep = $this->node('cbc:EndpointID', $p);
        $reg = $this->node('cac:PartyLegalEntity/cbc:CompanyID', $p);
        $out = [
            'name' => $this->v('cac:PartyLegalEntity/cbc:RegistrationName', $p),
            'trade_name' => $this->v('cac:PartyName/cbc:Name', $p),
            'id' => $this->v('cac:PartyIdentification/cbc:ID', $p),
            'trn' => $this->v("cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID", $p),
            'tin' => $this->v("cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='TIN']/cbc:CompanyID", $p),
            '_tax_scheme_count' => count($this->nodes('cac:PartyTaxScheme', $p)),
            '_vat_count' => count($this->nodes("cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']", $p)),
            'endpoint' => $ep ? ['scheme' => $ep->getAttribute('schemeID'), 'id' => trim($ep->textContent)] : null,
            'registration' => $reg ? array_filter(['type' => $reg->getAttribute('schemeAgencyID') ?: null,
                'id' => trim($reg->textContent), 'authority' => $reg->getAttribute('schemeAgencyName') ?: null]) : null,
            'address' => ($a = $this->node('cac:PostalAddress', $p)) ? $this->address($a) : null,
            'contact' => ($c = $this->node('cac:Contact', $p)) ? array_filter(['name' => $this->v('cbc:Name', $c),
                'phone' => $this->v('cbc:Telephone', $c), 'email' => $this->v('cbc:ElectronicMail', $c)]) : null,
        ];
        return array_filter($out, fn ($v) => $v !== null);
    }

    private function address(DOMElement $a): array
    {
        return array_filter([
            'line1' => $this->v('cbc:StreetName', $a),
            'line2' => $this->v('cbc:AdditionalStreetName', $a),
            'city' => $this->v('cbc:CityName', $a),
            'postal_code' => $this->v('cbc:PostalZone', $a),
            'emirate' => $this->v('cbc:CountrySubentity', $a),
            'country' => $this->v('cac:Country/cbc:IdentificationCode', $a),
        ], fn ($v) => $v !== null);
    }

    private function node(string $q, ?DOMElement $ctx): ?DOMElement
    {
        if (!$ctx) {
            return null;
        }
        $n = $this->x->query($q, $ctx);
        $el = $n ? $n->item(0) : null;
        return $el instanceof DOMElement ? $el : null;
    }

    /** @return list<DOMElement> */
    private function nodes(string $q, DOMElement $ctx): array
    {
        $r = [];
        foreach ($this->x->query($q, $ctx) ?: [] as $n) {
            if ($n instanceof DOMElement) {
                $r[] = $n;
            }
        }
        return $r;
    }

    private function v(string $q, ?DOMElement $ctx): ?string
    {
        $n = $this->node($q, $ctx);
        if (!$n) {
            return null;
        }
        $t = trim($n->textContent);
        return $t === '' ? null : $t;
    }

    private function num(string $q, ?DOMElement $ctx): ?float
    {
        $v = $this->v($q, $ctx);
        return $v === null || !is_numeric($v) ? null : (float) $v;
    }

    private function attr(string $q, string $attr, DOMElement $ctx): ?string
    {
        $n = $this->node($q, $ctx);
        return $n && $n->hasAttribute($attr) ? $n->getAttribute($attr) : null;
    }
}
