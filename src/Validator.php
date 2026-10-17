<?php

declare(strict_types=1);

namespace PintAE;

use RuntimeException;

/**
 * Business-rule validator for PINT AE documents.
 *
 * Rule ids:
 *  - IBR-xxx-AE / ALIGNED-IBRP-xxx  → re-implementation of the official PINT AE
 *    rule with that id (docs.peppol.eu/poac/ae/pint-ae/trn-invoice/rule/)
 *  - PAE-xxx                         → extra checks added by this library
 *
 * This is a fast PHP pre-check, NOT the official Schematron. Run your ASP's
 * validation (or the official Schematron) before go-live. See RULES.md.
 */
final class Validator
{
    private ValidationResult $r;

    public function validateXml(string $xml): ValidationResult
    {
        $this->r = new ValidationResult();
        try {
            $d = Reader::read($xml);
        } catch (RuntimeException $e) {
            $this->r->error('PAE-000', $e->getMessage());
            return $this->r;
        }
        if (!$this->structure($d)) {
            return $this->r;
        }
        $this->parties($d);
        $this->lines($d);
        $this->totals($d);
        $this->scenarios($d);
        $this->payment($d);
        return $this->r;
    }

    private function mods(array $d): array
    {
        return $d['modifiers'] ?? [];
    }

    private function has(array $d, string $mod): bool
    {
        return in_array($mod, $this->mods($d), true);
    }

    // ---------------------------------------------------------------- header
    private function structure(array $d): bool
    {
        $ns = ['Invoice' => Codes::NS_INVOICE, 'CreditNote' => Codes::NS_CREDITNOTE];
        if (!isset($ns[$d['_root']]) || ($d['_namespace'] ?? '') !== $ns[$d['_root']]) {
            $this->r->error('PAE-001', 'Root element must be a UBL 2.1 Invoice or CreditNote with the correct namespace.', '/');
            return false;
        }
        $cust = $d['customization'] ?? '';
        if (!in_array($cust, [Codes::CUSTOMIZATION_BILLING, Codes::CUSTOMIZATION_SELFBILLING], true)) {
            $this->r->error('ALIGNED-IBRP-001-AE', 'CustomizationID must be ' . Codes::CUSTOMIZATION_BILLING . ' or ' . Codes::CUSTOMIZATION_SELFBILLING . '.', 'cbc:CustomizationID');
        }
        if (!in_array($d['profile'] ?? '', [Codes::PROFILE_BILLING, Codes::PROFILE_SELFBILLING], true)) {
            $this->r->error('ALIGNED-IBRP-002-AE', 'ProfileID must be urn:peppol:bis:billing or urn:peppol:bis:selfbilling.', 'cbc:ProfileID');
        }
        if (empty($d['number'])) {
            $this->r->error('PAE-004', 'Document number (cbc:ID) is missing.', 'cbc:ID');
        }
        if (empty($d['uuid'])) {
            $this->r->error('IBR-193-AE', 'Unique identifier number (BTAE-07, cbc:UUID) must be provided.', 'cbc:UUID');
        } elseif (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $d['uuid'])) {
            $this->r->warning('PAE-011', 'UUID is not in the standard 8-4-4-4-12 format.', 'cbc:UUID');
        }
        if (!$this->isDate($d['issue_date'] ?? null)) {
            $this->r->error('PAE-005', 'IssueDate is missing or not YYYY-MM-DD.', 'cbc:IssueDate');
        }

        $code = (string) ($d['type_code'] ?? '');
        [$docType, $selfBilled] = Codes::describeTypeCode($code);
        if ($docType === 'unknown') {
            $this->r->error('PAE-006', "Document type code \"$code\" is not allowed (380, 381, 389, 261, 480, 81).", 'cbc:InvoiceTypeCode');
        } elseif ($docType !== $d['type']) {
            $this->r->error('PAE-006', "Type code $code does not belong in a {$d['_root']} document.", 'cbc:InvoiceTypeCode');
        } elseif ($selfBilled !== ($cust === Codes::CUSTOMIZATION_SELFBILLING)) {
            $this->r->error('PAE-006', "Type code $code and CustomizationID disagree about self-billing.", 'cbc:CustomizationID');
        }

        $cur = $d['currency'] ?? null;
        if (!$cur || !preg_match('/^[A-Z]{3}$/', $cur)) {
            $this->r->error('PAE-007', 'DocumentCurrencyCode must be a 3-letter ISO 4217 code.', 'cbc:DocumentCurrencyCode');
        }
        if (isset($d['tax_currency']) && $d['tax_currency'] !== Codes::ACCOUNTING_CURRENCY) {
            $this->r->error('IBR-140-AE', 'VAT accounting currency (TaxCurrencyCode), when present, must be AED.', 'cbc:TaxCurrencyCode');
        }
        if ($cur && $cur !== Codes::ACCOUNTING_CURRENCY) {
            if (($d['tax_currency'] ?? null) !== Codes::ACCOUNTING_CURRENCY) {
                $this->r->error('IBR-153-AE', 'Foreign-currency invoices must set TaxCurrencyCode to AED.', 'cbc:TaxCurrencyCode');
            }
            if (empty($d['exchange_rate'])) {
                $this->r->error('IBR-159-AE', 'Currency exchange rate (BTAE-04, cac:TaxExchangeRate/cbc:CalculationRate) is required when currency is not AED.', 'cac:TaxExchangeRate');
            }
            $aed = array_filter($d['_tax_totals'] ?? [], fn ($t) => ($t['currency'] ?? '') === Codes::ACCOUNTING_CURRENCY);
            if (!$aed || !isset($d['_stated_aed_total_incl_vat'])) {
                $this->r->error('IBR-175-AE', 'Foreign-currency invoices need the VAT total in AED and the AED total with VAT (BTAE-20).', 'cac:TaxTotal');
            }
        }
        if (isset($d['_exchange_rate_raw']) && preg_match('/\.(\d{7,})$/', $d['_exchange_rate_raw'])) {
            $this->r->error('IBR-002-AE', 'Currency exchange rate may have at most 6 decimals.', 'cac:TaxExchangeRate/cbc:CalculationRate');
        }

        $flags = $d['transaction_flags'] ?? null;
        if ($flags === null || !preg_match('/^[01]{8}$/', $flags)) {
            $this->r->error('IBR-154-AE', 'Transaction type code (BTAE-02, cbc:ProfileExecutionID) must be 8 characters of 0/1.', 'cbc:ProfileExecutionID');
        }

        if ($this->isDate($d['due_date'] ?? null) && $this->isDate($d['issue_date'] ?? null) && $d['due_date'] < $d['issue_date']) {
            $this->r->error('PAE-010', 'Due date is before the issue date.', 'cbc:DueDate');
        }
        if (!empty($d['tax_point_date'])) {
            if ($d['type'] === 'credit_note') {
                $this->r->error('IBR-124-AE', 'VAT point date must not be present on credit notes.', 'cbc:TaxPointDate');
            } elseif (!empty($d['issue_date']) && $d['tax_point_date'] > $d['issue_date']) {
                $this->r->warning('IBR-141-AE', 'VAT point date should not be after the issue date.', 'cbc:TaxPointDate');
            }
        }
        $payable = $d['_totals']['payable'] ?? 0;
        $dueExempt = in_array($d['type_code'] ?? '', ['381', '81', '261'], true) || $this->has($d, 'deemed_supply');
        if ($payable > 0 && empty($d['due_date']) && !$dueExempt) {
            $this->r->error('IBR-127-AE', 'Payment due date is required when the amount due is above 0 (except credit notes and deemed supply).', 'cbc:DueDate');
        }
        return true;
    }

    // --------------------------------------------------------------- parties
    private function parties(array $d): void
    {
        $s = $d['seller'] ?? [];
        $b = $d['buyer'] ?? [];
        $oos = $this->has($d, 'out_of_scope');
        $export = $this->has($d, 'export');

        if (empty($s['name'])) {
            $this->r->error('PAE-020', 'Seller legal name is missing.', 'AccountingSupplierParty/PartyLegalEntity/RegistrationName');
        }
        if (empty($s['trn'])) {
            if (!$oos) {
                $this->r->error('IBR-134-AE', 'Seller VAT identifier (TRN) is required.', 'AccountingSupplierParty/PartyTaxScheme/CompanyID');
            }
        } elseif (!self::isTrn($s['trn'])) {
            $this->r->error('IBR-132-AE', "Seller TRN \"{$s['trn']}\" must be 15 digits, start with 1 and end with 03.", 'AccountingSupplierParty/PartyTaxScheme/CompanyID');
        }

        $this->endpoint($s, 'Seller', 'AccountingSupplierParty', true);
        $this->addr($s['address'] ?? [], 'Seller', 'AccountingSupplierParty', 'IBR-143-AE', true);
        $this->registration($s, 'Seller', 'AccountingSupplierParty', true);

        if (empty($b['name'])) {
            $this->r->error('PAE-025', 'Buyer legal name is missing.', 'AccountingCustomerParty/PartyLegalEntity/RegistrationName');
        }
        if (!empty($b['trn']) && !self::isTrn($b['trn'])) {
            $this->r->error('IBR-132-AE', "Buyer TRN \"{$b['trn']}\" must be 15 digits, start with 1 and end with 03.", 'AccountingCustomerParty/PartyTaxScheme/CompanyID');
        }
        $bep = $b['endpoint']['id'] ?? '';
        $predefined = (bool) preg_match('/^9\d{9}$/', $bep);
        if (!$export && ($b['endpoint']['scheme'] ?? '') === Codes::ENDPOINT_SCHEME && !preg_match('/^1\d{9}$/', $bep)
            && empty($b['trn']) && empty($b['id'])) {
            $this->r->error('IBR-135-AE', 'Buyer identifier or buyer VAT identifier (TRN) is required.', 'AccountingCustomerParty/PartyTaxScheme/CompanyID');
        }
        $this->addr($b['address'] ?? [], 'Buyer', 'AccountingCustomerParty', 'IBR-144-AE', false);
        $this->registration($b, 'Buyer', 'AccountingCustomerParty', false);
        if (($b['_tax_scheme_count'] ?? 0) > 1) {
            $this->r->error('IBR-179-AE', 'Buyer VAT identifier may occur only once.', 'AccountingCustomerParty/PartyTaxScheme');
        }
        if (($s['_vat_count'] ?? 0) > 1) {
            $this->r->error('ALIGNED-IBRP-SR-12', 'Seller VAT identifier may occur only once.', 'AccountingSupplierParty/PartyTaxScheme');
        }
        if (($s['_tax_scheme_count'] ?? 0) > 2 || (($s['_tax_scheme_count'] ?? 0) === 2 && ($s['_vat_count'] ?? 0) !== 1)) {
            $this->r->error('IBR-178-AE', 'Seller may have one VAT (TRN) and one TIN tax scheme at most.', 'AccountingSupplierParty/PartyTaxScheme');
        }
        if (!empty($s['tin']) && !preg_match('/^1\d{9}$/', $s['tin'])) {
            $this->r->error('IBR-148-AE', "Seller tax registration (TIN) \"{$s['tin']}\" must be 10 digits in the format 1XXXXXXXXX.", 'AccountingSupplierParty/PartyTaxScheme[TIN]/CompanyID');
        }
        if ($this->has($d, 'disclosed_agent') && empty($s['trn']) && empty($s['tin'])) {
            $this->r->error('IBR-177-AE', 'Disclosed-agent billing needs the seller TRN or TIN.', 'AccountingSupplierParty/PartyTaxScheme');
        }
        if ($oos && empty($b['registration']['id'])) {
            $this->r->error('IBR-136-AE', 'Out-of-scope documents need the buyer legal registration identifier.', 'AccountingCustomerParty/PartyLegalEntity/CompanyID');
        }
        if ($export && empty($b['endpoint'])) {
            $this->r->warning('PAE-022', 'Export buyer has no EndpointID — use the predefined endpoint ' . Codes::ENDPOINT_EXPORT . ' if they are not on Peppol.', 'AccountingCustomerParty/Party/EndpointID');
        }
    }

    private function endpoint(array $p, string $who, string $path, bool $required): void
    {
        $ep = $p['endpoint'] ?? null;
        if (!$ep) {
            if ($required) {
                $this->r->error('PAE-022', "$who electronic address (EndpointID) is missing.", "$path/Party/EndpointID");
            }
            return;
        }
        if (($ep['scheme'] ?? '') !== Codes::ENDPOINT_SCHEME) {
            $this->r->error('PAE-022', "$who EndpointID schemeID must be " . Codes::ENDPOINT_SCHEME . '.', "$path/Party/EndpointID/@schemeID");
        } elseif (!preg_match('/^[19]\d{9}$/', (string) ($ep['id'] ?? ''))) {
            $this->r->warning('PAE-022', "$who EndpointID should be the 10-digit TIN (1XXXXXXXXX), as in the official examples.", "$path/Party/EndpointID");
        }
    }

    private function registration(array $p, string $who, string $path, bool $seller): void
    {
        $reg = $p['registration'] ?? [];
        $is0235 = ($p['endpoint']['scheme'] ?? '') === Codes::ENDPOINT_SCHEME;
        // IBR-149-AE: buyers with a TIN/predefined endpoint (1… / 9…) or a TRN may omit it
        $needed = $seller ? $is0235 : ($is0235 && !preg_match('/^[19]/', (string) ($p['endpoint']['id'] ?? '')) && empty($p['trn']));
        if (empty($reg['id'])) {
            if ($needed) {
                $this->r->error($seller ? 'IBR-150-AE' : 'IBR-149-AE', "$who legal registration identifier (trade licence, Emirates ID, …) is required.", "$path/Party/PartyLegalEntity/CompanyID");
            }
            return;
        }
        $type = $reg['type'] ?? null;
        if (!$seller && !$is0235) {
            return; // IBR-180/183-AE apply to UAE (0235) buyers only
        }
        if (!$type) {
            $this->r->error($seller ? 'IBR-181-AE' : 'IBR-180-AE', "$who legal registration type (schemeAgencyID: TL, EID, PAS, CD) is required.", "$path/Party/PartyLegalEntity/CompanyID/@schemeAgencyID");
        } elseif (!in_array($type, Codes::REGISTRATION_TYPES, true)
            && ($seller || !preg_match('/^[19]/', (string) ($p['endpoint']['id'] ?? '')))) { // IBR-183-AE exempts 1…/9… buyer endpoints
            $this->r->error($seller ? 'IBR-173-AE' : 'IBR-183-AE', "$who registration type \"$type\" must be one of " . implode(', ', Codes::REGISTRATION_TYPES) . '.', "$path/Party/PartyLegalEntity/CompanyID/@schemeAgencyID");
        } elseif ($type === 'TL' && empty($reg['authority'])) {
            $this->r->error($seller ? 'IBR-172-AE' : 'IBR-101-AE', "$who trade licence needs the issuing authority name (schemeAgencyName).", "$path/Party/PartyLegalEntity/CompanyID/@schemeAgencyName");
        } elseif ($type === 'PAS') {
            if (empty($reg['authority'])) {
                $this->r->error($seller ? 'IBR-012-AE' : 'IBR-010-AE', "$who passport needs the issuing country code in schemeAgencyName.", "$path/Party/PartyLegalEntity/CompanyID/@schemeAgencyName");
            } elseif (!preg_match('/^[A-Z]{2}$/', $reg['authority'])) {
                $this->r->error($seller ? 'IBR-013-AE' : 'IBR-011-AE', "$who passport issuing country \"{$reg['authority']}\" must be an ISO 3166-1 alpha-2 code.", "$path/Party/PartyLegalEntity/CompanyID/@schemeAgencyName");
            }
        }
    }

    private function addr(array $a, string $who, string $path, string $rule, bool $seller): void
    {
        if (empty($a['country'])) {
            $this->r->error($rule, "$who country code is missing.", "$path/PostalAddress/Country");
            return;
        }
        if ($seller && $a['country'] !== 'AE') {
            $this->r->warning('PAE-023', 'Seller country is not AE.', "$path/PostalAddress/Country");
        }
        $sub = $a['country'] === 'AE' ? 'emirate (CountrySubentity)' : 'state/region (CountrySubentity)';
        foreach (['line1' => 'address line 1 (StreetName)', 'city' => 'city (CityName)', 'emirate' => $sub] as $k => $label) {
            if (empty($a[$k])) {
                $this->r->error($rule, "$who $label is missing.", "$path/PostalAddress");
            }
        }
        if ($a['country'] === 'AE' && !empty($a['emirate']) && !isset(Codes::EMIRATES[$a['emirate']])) {
            $this->r->error('IBR-128-AE', "$who emirate \"{$a['emirate']}\" must be one of " . implode(', ', array_keys(Codes::EMIRATES)) . '.', "$path/PostalAddress/CountrySubentity");
        }
    }

    // ----------------------------------------------------------------- lines
    private function lines(array $d): void
    {
        $lines = $d['lines'] ?? [];
        if (!$lines) {
            $this->r->error('PAE-040', 'Document has no lines.', 'InvoiceLine');
            return;
        }
        $fx = ($d['currency'] ?? 'AED') === Codes::ACCOUNTING_CURRENCY ? 1.0 : (float) ($d['exchange_rate'] ?? 0);
        $seen = [];
        foreach ($lines as $i => $l) {
            $p = 'line ' . ($l['id'] ?? '#' . ($i + 1));
            $id = $l['id'] ?? null;
            if ($id === null || isset($seen[$id])) {
                $this->r->error('PAE-041', $id === null ? 'Line ID is missing.' : "Line ID \"$id\" is used more than once.", $p);
            }
            $seen[(string) $id] = true;
            if (!isset($l['quantity']) || empty($l['unit_code'])) {
                $this->r->error('PAE-042', 'Line needs a quantity and unit code (e.g. C62, HUR, KGM).', $p);
            }
            if (empty($l['name'])) {
                $this->r->error('PAE-048', 'Item name is missing.', $p);
            }
            if (empty($l['description'])) {
                $this->r->error('IBR-125-AE', 'Item description is required.', $p);
            }
            if (empty($l['_has_price_base']) || !isset($l['gross_price'])) {
                $this->r->error('IBR-126-AE', 'Price needs a base quantity and item gross price (Price/AllowanceCharge/BaseAmount).', $p);
            } elseif (isset($l['unit_price']) && !Money::equals(Money::round($l['gross_price'] - ($l['price_discount'] ?? 0), 6), $l['unit_price'], 0.000001)) {
                $this->r->error('ALIGNED-IBRP-004', 'Net price must equal gross price minus price discount.', $p);
            }

            $net = $l['_net'] ?? null;
            if ($net === null) {
                $this->r->error('PAE-043', 'Line net amount (LineExtensionAmount) is missing.', $p);
            } elseif (isset($l['quantity'], $l['unit_price'])) {
                $adj = 0.0;
                foreach ($l['_allowance_charges'] ?? [] as $ac) {
                    $adj += ($ac['charge'] ? 1 : -1) * (float) ($ac['amount'] ?? 0);
                    $this->allowanceChargeRules($ac, $p);
                }
                $calc = Money::round(Money::round($l['quantity'] * $l['unit_price'] / (($l['base_quantity'] ?? 1) ?: 1)) + $adj);
                if (!Money::equals($calc, $net)) {
                    $this->r->error('IBR-147-AE', sprintf('Line net %.2f ≠ quantity × net price + charges − allowances (%.2f).', $net, $calc), $p);
                }
            }

            $cat = $l['category'] ?? null;
            if (!$cat) {
                $this->r->error('IBR-145-AE', 'Each line needs a VAT category code.', $p);
                continue;
            }
            if (!isset(Codes::TAX_CATEGORIES[$cat])) {
                $this->r->warning('IBR-139-AE', "VAT category \"$cat\" is not in the AE category list (" . implode(', ', array_keys(Codes::TAX_CATEGORIES)) . ').', $p);
                continue;
            }
            $rate = $l['rate'] ?? null;
            $hasRate = !empty($l['_has_rate']);
            switch ($cat) {
                case 'S':
                    if ($rate !== null && $rate <= 0) {
                        $this->r->error('ALIGNED-IBRP-S-05', 'Standard-rated lines need a VAT rate above zero.', $p);
                    } elseif ($rate === null || !Money::equals($rate, Codes::STANDARD_RATE)) {
                        $this->r->error('IBR-190-AE', 'Standard-rated (S) lines must use a 5.00% rate.', $p);
                    }
                    if (!empty($l['exemption_code']) || !empty($l['exemption_reason'])) {
                        $this->r->error('ALIGNED-IBRP-S-10', 'Standard-rated lines must not carry an exemption reason.', $p);
                    }
                    break;
                case 'Z':
                    if ($rate === null || !Money::equals($rate, 0)) {
                        $this->r->error('ALIGNED-IBRP-Z-05', 'Zero-rated (Z) lines must have a 0% rate.', $p);
                    }
                    break;
                case 'E':
                case 'O':
                    if ($hasRate) {
                        $this->r->error("ALIGNED-IBRP-$cat-05", "Category $cat lines must not include a VAT rate.", $p);
                    }
                    break;
                case 'AE':
                    if ($rate === null) {
                        $this->r->error('ALIGNED-IBRP-AE-05-AE', 'Reverse-charge lines must include a VAT rate.', $p);
                    }
                    if (empty($l['rcm_type'])) {
                        $this->r->error('IBR-166-AE', 'Reverse-charge items need the RCM goods/services type (BTAE-09, CommodityClassification/NatureCode).', $p);
                    }
                    if (($l['item_code']['scheme'] ?? null) !== '0160' || empty($l['item_code']['id'])) {
                        $this->r->error('IBR-174-AE', 'Reverse-charge items need a standard item identifier with scheme 0160.', $p);
                    }
                    break;
                case 'N':
                    if ($rate === null || Money::equals($rate, 0)) {
                        $this->r->warning('IBR-111-AE', 'Standard rate additional VAT (N) lines should not have a zero VAT rate.', $p);
                    }
                    if (!$this->has($d, 'margin_scheme')) {
                        $this->r->warning('IBR-116-AE', 'Category N is normally used with the margin-scheme transaction flag (XX1XXXXX).', $p);
                    }
                    break;
            }
            if ($cat === 'E' && empty($l['exemption_code'])) {
                $this->r->error('IBR-167-AE', 'Exempt lines must include a VAT exemption reason code.', $p);
            }

            // BTAE-10 / BTAE-08 (AED amounts in ItemPriceExtension)
            if (!isset($l['_gross_aed'])) {
                $this->r->error('IBR-194-AE', 'Line amount payable in AED (BTAE-10, ItemPriceExtension/Amount) must be provided.', $p);
            }
            if ($cat === 'E') {
                if (isset($l['_tax_aed'])) {
                    $this->r->error('IBR-163-AE', 'Exempt lines must not carry a line VAT amount (BTAE-08).', $p);
                }
            } elseif (!isset($l['_tax_aed'])) {
                $this->r->error('IBR-104-AE', 'Line VAT amount in AED (BTAE-08, ItemPriceExtension/TaxTotal/TaxAmount) must be provided.', $p);
            } elseif ($net !== null && $fx > 0) {
                // Official examples state BTAE-08/10 either in AED or in the invoice currency; accept both.
                $fx = ($l['_ext_currency'] ?? Codes::ACCOUNTING_CURRENCY) === ($d['currency'] ?? 'AED') ? 1.0 : $fx;
                $expTax = Money::round(Calculator::taxFor($cat, (float) ($rate ?? 0), (float) $net) * $fx);
                if (in_array($cat, ['Z', 'AE'], true) && !Money::equals($l['_tax_aed'], 0)) {
                    $this->r->error($cat === 'Z' ? 'IBR-165-AE' : 'IBR-162-AE', "Category $cat line VAT amount (BTAE-08) must be zero.", $p);
                } elseif (!Money::equals($expTax, $l['_tax_aed'], 0.02)) {
                    $this->r->error('PAE-047', sprintf('Line VAT in AED %.2f should be %.2f.', $l['_tax_aed'], $expTax), $p);
                }
                if (isset($l['_gross_aed'])) {
                    $expGross = Money::round(Money::round($net + Calculator::taxFor($cat, (float) ($rate ?? 0), (float) $net)) * $fx);
                    if (!Money::equals($expGross, $l['_gross_aed'], 0.02)) {
                        $this->r->error('PAE-049', sprintf('Line amount payable in AED %.2f should be %.2f.', $l['_gross_aed'], $expGross), $p);
                    }
                }
            }

            // Item type ↔ classification (IBR-184/185/186/188)
            $it = $l['item_type'] ?? null;
            $hs = $l['classification'] ?? null;
            if ($hs && ($hs['scheme'] ?? '') !== 'HS') {
                $this->r->error('IBR-188-AE', 'Item classification scheme (listID) must be HS.', $p);
            }
            if (in_array($it, ['G', 'B'], true) && !$hs) {
                $this->r->error($it === 'G' ? 'IBR-184-AE' : 'IBR-186-AE', 'Goods need an HS item classification code.', $p);
            }
            if (in_array($it, ['S', 'B'], true) && empty($l['service_code'])) {
                $this->r->error($it === 'S' ? 'IBR-185-AE' : 'IBR-186-AE', 'Services need a service accounting code (BTAE-17, SAC).', $p);
            }
        }
    }

    // ---------------------------------------------------------------- totals
    private function totals(array $d): void
    {
        $t = $d['_totals'] ?? [];
        $cur = $d['currency'] ?? Codes::ACCOUNTING_CURRENCY;
        foreach (['line_extension', 'tax_exclusive', 'tax_inclusive', 'payable'] as $k) {
            if (!isset($t[$k])) {
                $this->r->error('PAE-060', "LegalMonetaryTotal is missing $k.", 'cac:LegalMonetaryTotal');
                return;
            }
        }
        $lineSum = Money::round(array_sum(array_map(fn ($l) => $l['_net'] ?? 0, $d['lines'] ?? [])));
        if (!Money::equals($lineSum, $t['line_extension'])) {
            $this->r->error('PAE-060', sprintf('Sum of line nets %.2f ≠ LineExtensionAmount %.2f.', $lineSum, $t['line_extension']), 'LineExtensionAmount');
        }
        if (!Money::equals(Money::round($t['line_extension'] - $t['allowances'] + $t['charges']), $t['tax_exclusive'])) {
            $this->r->error('PAE-061', 'TaxExclusiveAmount ≠ LineExtension − allowances + charges.', 'TaxExclusiveAmount');
        }

        $main = null;
        foreach ($d['_tax_totals'] ?? [] as $tt) {
            if (($tt['currency'] ?? $cur) === $cur && $tt['subtotals']) {
                $main = $tt;
                break;
            }
        }
        if (!$main) {
            $this->r->error('PAE-064', 'TaxTotal with a VAT breakdown (TaxSubtotal) is missing.', 'cac:TaxTotal');
            return;
        }
        $subSum = Money::round(array_sum(array_column($main['subtotals'], 'tax')));
        if (!Money::equals($subSum, (float) $main['amount'])) {
            $this->r->error('PAE-064', sprintf('TaxTotal %.2f ≠ sum of TaxSubtotals %.2f.', $main['amount'], $subSum), 'cac:TaxTotal');
        }
        if (!Money::equals(Money::round($t['tax_exclusive'] + (float) $main['amount']), $t['tax_inclusive'])) {
            $this->r->error('PAE-062', sprintf('TaxInclusiveAmount %.2f ≠ TaxExclusive %.2f + VAT %.2f.', $t['tax_inclusive'], $t['tax_exclusive'], $main['amount']), 'TaxInclusiveAmount');
        }
        if (!Money::equals(Money::round($t['tax_inclusive'] - $t['prepaid'] + $t['rounding']), $t['payable'])) {
            $this->r->error('PAE-063', 'PayableAmount ≠ TaxInclusive − Prepaid + Rounding.', 'PayableAmount');
        }

        $key = fn ($cat, $rate) => Calculator::groupKey((string) $cat, $rate);
        $groups = [];
        foreach ($d['lines'] ?? [] as $l) {
            if (isset($l['category'])) {
                $k = $key($l['category'], $l['rate'] ?? 0);
                $groups[$k] = ($groups[$k] ?? 0) + ($l['_net'] ?? 0);
            }
        }
        $allowSum = $chargeSum = 0.0;
        foreach ($d['_allowance_charges'] ?? [] as $ac) {
            $this->allowanceChargeRules($ac, 'document ' . ($ac['charge'] ? 'charge' : 'allowance'));
            $amt = (float) ($ac['amount'] ?? 0);
            $ac['charge'] ? $chargeSum += $amt : $allowSum += $amt;
            if (!empty($ac['category'])) {
                $k = $key($ac['category'], $ac['rate'] ?? 0);
                $groups[$k] = ($groups[$k] ?? 0) + ($ac['charge'] ? $amt : -$amt);
            }
        }
        if (!Money::equals(Money::round($allowSum), $t['allowances']) || !Money::equals(Money::round($chargeSum), $t['charges'])) {
            $this->r->error('PAE-069', 'AllowanceTotalAmount / ChargeTotalAmount do not match the document-level allowances and charges.', 'cac:LegalMonetaryTotal');
        }
        $subs = [];
        foreach ($main['subtotals'] as $s) {
            if ($s['taxable'] === null) {
                $this->r->error('ALIGNED-IBRP-045', 'Each VAT breakdown needs a taxable amount.', 'cac:TaxSubtotal/cbc:TaxableAmount');
            }
            if ($s['tax'] === null) {
                $this->r->error('ALIGNED-IBRP-046', 'Each VAT breakdown needs a VAT amount.', 'cac:TaxSubtotal/cbc:TaxAmount');
            }
            if (empty($s['category'])) {
                $this->r->error('ALIGNED-IBRP-047', 'Each VAT breakdown needs a VAT category code.', 'cac:TaxSubtotal/cac:TaxCategory/cbc:ID');
                continue;
            }
            $cat = (string) $s['category'];
            $k = $key($cat, $s['rate']);
            if (isset($subs[$k]) && in_array($cat, ['E', 'O', 'Z'], true)) {
                $this->r->error("ALIGNED-IBRP-$cat-01", "There must be exactly one VAT breakdown for category $cat.", 'cac:TaxSubtotal');
            }
            $subs[$k] ??= ['taxable' => 0.0, 'tax' => 0.0, 'category' => $cat, 'rate' => $s['rate'], 'has_rate' => false, 'reason' => false];
            $subs[$k]['taxable'] += (float) $s['taxable'];
            $subs[$k]['tax'] += (float) $s['tax'];
            $subs[$k]['has_rate'] = $subs[$k]['has_rate'] || !empty($s['has_rate']);
            $subs[$k]['reason'] = $subs[$k]['reason'] || !empty($s['exemption_code']) || !empty($s['exemption_reason']);
        }
        foreach ($subs as $k => $s) {
            $cat = $s['category'];
            if (in_array($cat, ['E', 'O'], true) && $s['has_rate']) {
                $this->r->error($cat === 'E' ? 'IBR-121-AE' : 'ALIGNED-IBRP-O-11-AE', "VAT breakdown for $cat must not provide a rate.", 'cac:TaxSubtotal/cac:TaxCategory');
            }
            if ($cat === 'Z' && $s['has_rate'] && !Money::equals((float) $s['rate'], 0)) {
                $this->r->error('IBR-120-AE', 'Zero-rated VAT breakdown must have a 0% rate.', 'cac:TaxSubtotal/cac:TaxCategory');
            }
            if (!in_array($cat, ['E', 'O'], true) && !$s['has_rate']) {
                $this->r->error('IBR-119-AE', "VAT breakdown for $cat needs a rate.", 'cac:TaxSubtotal/cac:TaxCategory');
            }
            if ($cat === 'S' && $s['reason']) {
                $this->r->error('ALIGNED-IBRP-S-10', 'Standard-rate VAT breakdown must not carry an exemption reason.', 'cac:TaxSubtotal/cac:TaxCategory');
            }
            if (!isset($groups[$k])) {
                $this->r->error('PAE-067', "VAT breakdown $k has no matching lines.", 'cac:TaxSubtotal');
                continue;
            }
            if (!Money::equals(Money::round($groups[$k]), $s['taxable'])) {
                $rule = ['S' => 'ALIGNED-IBRP-S-08', 'Z' => 'ALIGNED-IBRP-Z-08', 'E' => 'ALIGNED-IBRP-E-08', 'O' => 'ALIGNED-IBRP-O-08', 'AE' => 'ALIGNED-IBRP-AE-08-AE', 'N' => 'IBR-102-AE'][$cat] ?? 'PAE-065';
                $this->r->error($rule, sprintf('Taxable amount for %s is %.2f but lines add up to %.2f.', $k, $s['taxable'], $groups[$k]), 'cac:TaxSubtotal');
            }
            $exp = Calculator::taxFor($cat, (float) ($s['rate'] ?? 0), (float) $s['taxable']);
            if (!Money::equals($exp, $s['tax'])) {
                $rule = ['S' => 'ALIGNED-IBRP-S-09', 'Z' => 'ALIGNED-IBRP-Z-09', 'E' => 'ALIGNED-IBRP-E-09', 'O' => 'ALIGNED-IBRP-O-09', 'AE' => 'ALIGNED-IBRP-AE-09-AE', 'N' => 'IBR-108-AE'][$cat] ?? 'PAE-066';
                $this->r->error($rule, sprintf('VAT for %s is %.2f but should be %.2f.', $k, $s['tax'], $exp), 'cac:TaxSubtotal');
            }
        }
        foreach (array_diff_key($groups, $subs) as $k => $_) {
            $cat = explode('@', (string) $k)[0];
            $rule = ['S' => 'ALIGNED-IBRP-S-01', 'Z' => 'ALIGNED-IBRP-Z-01', 'E' => 'ALIGNED-IBRP-E-01', 'O' => 'ALIGNED-IBRP-O-01', 'AE' => 'ALIGNED-IBRP-AE-01-AE', 'N' => 'IBR-105-AE'][$cat] ?? 'PAE-067';
            $this->r->error($rule, "Lines use $k but there is no matching VAT breakdown.", 'cac:TaxSubtotal');
        }

        if (isset($d['_stated_aed_total_incl_vat'])) {
            $fx = $cur === Codes::ACCOUNTING_CURRENCY ? 1.0 : (float) ($d['exchange_rate'] ?? 0);
            $stated = (float) preg_replace('/[^0-9.\-]/', '', (string) $d['_stated_aed_total_incl_vat']);
            if ($fx > 0 && !Money::equals($stated, Money::round($t['tax_inclusive'] * $fx), 0.02)) {
                $this->r->error('PAE-068', 'BTAE-20 total with VAT in AED does not match TaxInclusiveAmount × exchange rate.', 'AdditionalDocumentReference');
            }
        }
    }

    // ------------------------------------------------------------- scenarios
    private function scenarios(array $d): void
    {
        $cats = array_values(array_unique(array_filter(array_column($d['lines'] ?? [], 'category'))));
        $oos = $this->has($d, 'out_of_scope');

        if ($d['type'] === 'credit_note') {
            $reason = $d['credit_reason']['code'] ?? null;
            if (!$reason) {
                $this->r->error('IBR-158-AE', 'Credit notes must include a credit note reason code (BTAE-03, DiscrepancyResponse/ResponseCode).', 'cac:DiscrepancyResponse');
            }
            if ($reason && !isset(Codes::CREDIT_REASONS[$reason])) {
                $this->r->error('IBR-001-AE', "Credit note reason \"$reason\" must be one of " . implode(', ', array_keys(Codes::CREDIT_REASONS)) . '.', 'cac:DiscrepancyResponse/cbc:ResponseCode');
            }
            if (in_array($d['type_code'] ?? '', ['381', '81'], true)) {
                $isVd = $reason === Codes::CREDIT_REASON_VOLUME_DISCOUNT;
                if (!$isVd && empty($d['preceding_invoice']['number'])) {
                    $this->r->error('IBR-055-AE', 'Credit note must reference the preceding invoice (BillingReference).', 'cac:BillingReference');
                } elseif ($isVd && !empty($d['preceding_invoice']['number'])) {
                    $this->r->error('IBR-055-AE', 'A volume-discount (VD) credit note must not reference a single preceding invoice.', 'cac:BillingReference');
                }
            }
        }
        $freq = $d['period']['frequency'] ?? null;
        if ($freq !== null && !isset(Codes::FREQUENCIES[$freq])) {
            $this->r->error('IBR-005-AE', "Frequency of billing \"$freq\" must be one of " . implode(', ', array_keys(Codes::FREQUENCIES)) . '.', 'cac:InvoicePeriod/cbc:Description');
        }
        if ($freq === 'OTH' && empty($d['note'])) {
            $this->r->error('IBR-160-AE', 'Frequency "OTH" (others) needs the actual frequency in the invoice note.', 'cbc:Note');
        }
        foreach ($d['lines'] ?? [] as $l) {
            if (!empty($l['rcm_type']) && !isset(Codes::RCM_TYPES[$l['rcm_type']])) {
                $this->r->error('IBR-006-AE', "RCM goods/services type \"{$l['rcm_type']}\" must be one of " . implode(', ', array_keys(Codes::RCM_TYPES)) . '.', 'line ' . ($l['id'] ?? '?'));
            }
            if (!empty($l['_has_sac']) && empty($l['_sac_scheme_ok'])) {
                $this->r->error('IBR-189-AE', 'Service accounting code (BTAE-17) must use schemeID "SAC".', 'line ' . ($l['id'] ?? '?'));
            }
            if (!empty($l['item_type']) && !isset(Codes::ITEM_TYPES[$l['item_type']])) {
                $this->r->error('PAE-050', "Item type \"{$l['item_type']}\" must be G, S or B.", 'line ' . ($l['id'] ?? '?'));
            }
        }
        if ($this->has($d, 'summary') && (!$this->isDate($d['period']['start'] ?? null) || !$this->isDate($d['period']['end'] ?? null))) {
            $this->r->error('IBR-138-AE', 'Summary invoices need an invoicing period (start and end date).', 'cac:InvoicePeriod');
        }
        if ($this->has($d, 'continuous_supply') && empty($d['period'])) {
            $this->r->warning('PAE-081', 'Continuous-supply invoices should state the invoicing period.', 'cac:InvoicePeriod');
        }
        if (!empty($d['period']['start']) && !empty($d['period']['end']) && $d['period']['end'] < $d['period']['start']) {
            $this->r->error('PAE-081', 'Invoice period end date is before the start date.', 'cac:InvoicePeriod');
        }
        if ($this->has($d, 'disclosed_agent')) {
            if (empty($d['principal_id'])) {
                $this->r->error('IBR-137-AE', 'Disclosed-agent billing must give the Principal ID (BTAE-14, SellerSupplierParty).', 'cac:SellerSupplierParty');
            } elseif ($d['principal_id'] === ($d['seller']['trn'] ?? null)) {
                $this->r->error('IBR-176-AE', 'Principal ID must differ from the seller TRN.', 'cac:SellerSupplierParty');
            }
        }
        if ($this->has($d, 'free_trade_zone') && empty($d['beneficiary_id'])) {
            $this->r->error('IBR-007-AE', 'Free-zone supply (1XXXXXXX) requires the Beneficiary ID (BTAE-01, BuyerCustomerParty).', 'cac:BuyerCustomerParty');
        }
        if ($this->has($d, 'ecommerce')) {
            $a = $d['delivery']['address'] ?? [];
            if (empty($a['line1']) || empty($a['city']) || empty($a['emirate'])) {
                $this->r->error('IBR-142-AE', 'E-commerce supplies need deliver-to address line 1, city and emirate.', 'cac:Delivery/cac:DeliveryLocation/cac:Address');
            }
        }
        if ($this->has($d, 'export')) {
            if (($d['buyer']['address']['country'] ?? 'AE') === 'AE') {
                $this->r->warning('PAE-084', 'Export flag is set but the buyer address is in the UAE.', 'AccountingCustomerParty/PostalAddress/Country');
            }
            $a = $d['delivery']['address'] ?? null;
            if ($a && ($a['country'] ?? 'AE') !== 'AE' && (empty($a['line1']) || empty($a['city']) || empty($a['emirate']))) {
                $this->r->error('IBR-152-AE', 'Exports delivered outside the UAE need deliver-to address line 1, city and subdivision.', 'cac:Delivery/cac:DeliveryLocation/cac:Address');
            }
            if (empty($d['delivery']['terms']['incoterms'])) {
                $this->r->warning('IBR-196-AE', 'Incoterms (BTAE-22, Delivery/DeliveryTerms/ID) should be provided for exports.', 'cac:Delivery/cac:DeliveryTerms');
            }
        }
        if (in_array('AE', $cats, true) && empty($d['buyer']['trn'])) {
            $this->r->error('IBR-103-AE', 'Reverse-charge invoices require the buyer VAT identifier (TRN).', 'AccountingCustomerParty/PartyTaxScheme/CompanyID');
        }
        if ($oos) {
            if (array_diff($cats, ['E', 'O', 'Z'])) {
                $this->r->error('IBR-122-AE', 'Out-of-scope documents may only use categories E, O or Z.', 'InvoiceLine');
            }
            if (array_intersect($this->mods($d), ['summary', 'deemed_supply', 'margin_scheme'])) {
                $this->r->error('IBR-157-AE', 'Out-of-scope documents cannot be summary, deemed-supply or margin-scheme.', 'cbc:ProfileExecutionID');
            }
        } elseif ($cats && !array_diff($cats, ['E', 'O'])) {
            $this->r->warning('IBR-151-AE', 'Tax invoices/credit notes should not use only E and/or O lines — consider type 480/81.', 'cbc:InvoiceTypeCode');
        }
    }

    // --------------------------------------------------------------- payment
    private function payment(array $d): void
    {
        $p = $d['payment'] ?? null;
        $exempt = $d['type'] === 'credit_note' || $this->has($d, 'deemed_supply');
        if (!$p || empty($p['means_code'])) {
            if (!$exempt) {
                $this->r->error('IBR-191-AE', 'Payment means type code is required (except credit notes and deemed supply).', 'cac:PaymentMeans/cbc:PaymentMeansCode');
            }
            return;
        }
        if (!isset(Codes::PAYMENT_MEANS[$p['means_code']])) {
            $this->r->warning('PAE-090', "Payment means code \"{$p['means_code']}\" is not in the AE payment means list (" . implode(', ', array_keys(Codes::PAYMENT_MEANS)) . ').', 'cac:PaymentMeans/cbc:PaymentMeansCode');
        }
        if (in_array($p['means_code'], ['30', '58'], true) && empty($p['iban'])) {
            $this->r->error('IBR-192-AE', 'Credit transfer requires the payment account identifier (IBAN).', 'cac:PayeeFinancialAccount/cbc:ID');
        }
        if (!empty($p['iban']) && preg_match('/^[A-Z]{2}\d{2}/', $p['iban']) && !self::isIban($p['iban'])) {
            $this->r->warning('PAE-091', "IBAN \"{$p['iban']}\" fails the checksum (UAE IBANs are 23 characters).", 'cac:PayeeFinancialAccount/cbc:ID');
        }
    }

    // ------------------------------------------------- allowances & charges
    private function allowanceChargeRules(array $ac, string $where): void
    {
        $kind = $ac['charge'] ? 'charge' : 'allowance';
        if (($ac['percent'] !== null) !== ($ac['base'] !== null)) {
            $this->r->error($ac['charge'] ? 'ALIGNED-IBRP-058' : 'ALIGNED-IBRP-057', "A $kind percentage and base amount must be given together.", $where);
        }
        if ($ac['percent'] !== null && $ac['base'] !== null && $ac['amount'] !== null
            && !Money::equals(Money::round($ac['base'] * $ac['percent'] / 100), (float) $ac['amount'])) {
            $this->r->error($ac['charge'] ? 'IBR-146-AE' : 'IBR-131-AE', ucfirst($kind) . ' amount must equal base × percentage ÷ 100.', $where);
        }
        if (empty($ac['reason']) && empty($ac['reason_code'])) {
            $this->r->error('PAE-070', ucfirst($kind) . ' needs a reason or reason code.', $where);
        }
        if (!$ac['doc']) {
            return;
        }
        $cat = $ac['category'] ?? null;
        if (!$cat) {
            $this->r->error($ac['charge'] ? 'ALIGNED-IBRP-037' : 'ALIGNED-IBRP-032', "Document-level $kind needs a VAT category.", $where);
            return;
        }
        $n = $ac['charge'] ? '07' : '06';
        $rate = $ac['rate'];
        switch ($cat) {
            case 'S':
                if ($rate === null || $rate <= 0) {
                    $this->r->error("ALIGNED-IBRP-S-$n", "Standard-rated $kind needs a VAT rate above zero.", $where);
                } elseif (!Money::equals($rate, Codes::STANDARD_RATE)) {
                    $this->r->error('IBR-190-AE', "Standard-rated $kind must use 5.00%.", $where);
                }
                break;
            case 'Z':
            case 'E':
                if ($rate === null || !Money::equals($rate, 0)) {
                    $this->r->error("ALIGNED-IBRP-$cat-$n", "Category $cat $kind must have a 0% rate.", $where);
                }
                if ($cat === 'E' && empty($ac['reason_code'])) {
                    $this->r->error($ac['charge'] ? 'IBR-169-AE' : 'IBR-168-AE', "Exempt $kind must include a reason code.", $where);
                }
                break;
            case 'AE':
                if ($rate === null || !Money::equals($rate, 0)) {
                    $this->r->error("ALIGNED-IBRP-AE-$n", "Reverse-charge $kind must have a 0% rate.", $where);
                }
                break;
            case 'O':
                if (!empty($ac['has_rate'])) {
                    $this->r->error("ALIGNED-IBRP-O-$n", "Category O $kind must not include a rate.", $where);
                }
                break;
            case 'N':
                $this->r->error($ac['charge'] ? 'IBR-114-AE' : 'IBR-115-AE', "Document-level $kind cannot use category N.", $where);
                break;
        }
    }

    // --------------------------------------------------------------- helpers
    private function isDate(?string $v): bool
    {
        if (!$v || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** UAE TRN: 15 digits, starts with 1, ends with 03 (IBR-132-AE). */
    public static function isTrn(string $trn): bool
    {
        return (bool) preg_match('/^1\d{12}03$/', $trn);
    }

    public static function isIban(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }
        if (str_starts_with($iban, 'AE') && strlen($iban) !== 23) {
            return false;
        }
        $moved = substr($iban, 4) . substr($iban, 0, 4);
        $num = '';
        foreach (str_split($moved) as $ch) {
            $num .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $mod = 0;
        foreach (str_split($num, 7) as $chunk) {
            $mod = (int) (($mod . $chunk) % 97);
        }
        return $mod === 1;
    }
}
