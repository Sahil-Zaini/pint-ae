<?php

declare(strict_types=1);

namespace PintAE;

/**
 * Code lists and identifiers used by PINT AE (UAE Peppol PINT, UBL 2.1).
 *
 * Every value here lives in ONE place so it can be updated when the
 * official specification changes. Values marked "verify" are mappings
 * that should be confirmed against the PINT AE version you target.
 */
final class Codes
{
    public const NS_INVOICE    = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const NS_CREDITNOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    public const NS_CAC        = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const NS_CBC        = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const CUSTOMIZATION_BILLING     = 'urn:peppol:pint:billing-1@ae-1';
    public const CUSTOMIZATION_SELFBILLING = 'urn:peppol:pint:selfbilling-1@ae-1';
    public const PROFILE_BILLING           = 'urn:peppol:bis:billing';
    public const PROFILE_SELFBILLING       = 'urn:peppol:bis:selfbilling';

    /** Peppol participant identifier scheme for UAE TINs/TRNs. */
    public const ENDPOINT_SCHEME = '0235';

    public const ACCOUNTING_CURRENCY = 'AED';
    public const STANDARD_RATE = 5.0;

    /**
     * Document type codes (UNTDID 1001).
     * [document type][self-billed?][out-of-scope?] => code
     */
    public const TYPE_CODES = [
        'invoice'     => ['normal' => '380', 'self_billed' => '389', 'out_of_scope' => '480'],
        'credit_note' => ['normal' => '381', 'self_billed' => '261', 'out_of_scope' => '81'],
    ];

    /**
     * Invoice transaction type flags (BTAE-02), 8-character binary string
     * carried in cbc:ProfileExecutionID. Position 1 is the first character.
     */
    public const TRANSACTION_FLAGS = [
        'free_trade_zone',
        'deemed_supply',
        'margin_scheme',
        'summary',
        'continuous_supply',
        'disclosed_agent',
        'ecommerce',
        'export',
    ];

    /** UAE VAT categories (UNCL5305 subset used by PINT AE). */
    public const TAX_CATEGORIES = [
        'S'  => 'Standard rated (5%)',
        'Z'  => 'Zero rated',
        'E'  => 'Exempt',
        'O'  => 'Outside the scope of VAT',
        'AE' => 'Reverse charge',
        'N'  => 'Standard rate additional VAT (margin scheme)',
    ];

    /** Categories whose line and breakdown must NOT carry a rate (ALIGNED-IBRP-E-05, O-05, O-11-AE, IBR-121-AE). */
    public const NO_RATE_CATEGORIES = ['E', 'O'];

    /** Categories that never carry VAT on the document. */
    public const ZERO_TAX_CATEGORIES = ['Z', 'E', 'O', 'AE', 'N'];

    /** Exempt lines must give an exemption reason code (IBR-167-AE). */
    public const REASON_REQUIRED = ['E'];

    /** Predefined receiver endpoints (scheme 0235) from the PINT AE BIS. */
    public const ENDPOINT_DEEMED_SUPPLY = '9900000097';
    public const ENDPOINT_NOT_SUBJECT   = '9900000098';
    public const ENDPOINT_EXPORT        = '9900000099';

    /**
     * Official PINT AE code lists (resources.zip, PINT AE Billing 1.0.4) and
     * friendly aliases that are converted to the official code on output.
     */
    public const FREQUENCIES = [
        'DLY' => 'Daily', 'WKY' => 'Weekly', 'Q15' => 'Once in 15 days', 'MTH' => 'Monthly', 'Q45' => 'Once in 45 days',
        'Q60' => 'Once in 60 days', 'QTR' => 'Quarterly', 'YRL' => 'Yearly', 'HYR' => 'Half-Yearly', 'OTH' => 'Others',
    ];
    public const FREQUENCY_ALIASES = [
        'DAILY' => 'DLY', 'WEEKLY' => 'WKY', 'FORTNIGHTLY' => 'Q15', 'BIWEEKLY' => 'Q15', 'MONTHLY' => 'MTH',
        'QUARTERLY' => 'QTR', 'YEARLY' => 'YRL', 'ANNUAL' => 'YRL', 'ANNUALLY' => 'YRL',
        'HALF_YEARLY' => 'HYR', 'HALFYEARLY' => 'HYR', 'SEMI_ANNUAL' => 'HYR', 'OTHER' => 'OTH', 'OTHERS' => 'OTH',
    ];

    /** Reasons for credit note (BTAE-03). */
    public const CREDIT_REASONS = [
        'DL8.61.1.A' => 'The supply was cancelled',
        'DL8.61.1.B' => 'The tax treatment changed due to a change in the nature of the supply',
        'DL8.61.1.C' => 'The agreed consideration was altered (e.g. bad debt, price change)',
        'DL8.61.1.D' => 'The goods or services were returned in full or in part',
        'DL8.61.1.E' => 'Tax was charged or applied in error',
        'VD'         => 'Volume discount',
    ];
    public const CREDIT_REASON_ALIASES = [
        'SUPPLY_CANCELLED' => 'DL8.61.1.A', 'CANCELLED' => 'DL8.61.1.A', 'CANCELLATION' => 'DL8.61.1.A',
        'TAX_TREATMENT_CHANGED' => 'DL8.61.1.B', 'NATURE_CHANGED' => 'DL8.61.1.B',
        'CONSIDERATION_ALTERED' => 'DL8.61.1.C', 'PRICE_CHANGE' => 'DL8.61.1.C', 'PRICE_ADJUSTMENT' => 'DL8.61.1.C', 'BAD_DEBT' => 'DL8.61.1.C',
        'GOODS_OR_SERVICES_RETURNED' => 'DL8.61.1.D', 'RETURNED' => 'DL8.61.1.D', 'RETURN' => 'DL8.61.1.D', 'GOODS_RETURNED' => 'DL8.61.1.D',
        'TAX_ERROR' => 'DL8.61.1.E', 'TAX_CHARGED_IN_ERROR' => 'DL8.61.1.E', 'ERROR' => 'DL8.61.1.E',
        'VOLUME_DISCOUNT' => 'VD',
    ];

    /** Goods and services subject to reverse charge (BTAE-09). */
    public const RCM_TYPES = [
        'DL8.48.8.2' => 'Electronic devices', 'DL8.48.8.1' => 'Gold and diamonds', 'DL8.48.3.1' => 'Crude or refined oil',
        'DL8.48.3.2' => 'Unprocessed or processed natural gas', 'DL8.48.3.3' => 'Pure hydrocarbons',
    ];
    public const RCM_ALIASES = [
        'ELECTRONIC_DEVICES' => 'DL8.48.8.2', 'ELECTRONICS' => 'DL8.48.8.2', 'GOLD_AND_DIAMONDS' => 'DL8.48.8.1', 'GOLD' => 'DL8.48.8.1',
        'DIAMONDS' => 'DL8.48.8.1', 'CRUDE_OIL' => 'DL8.48.3.1', 'OIL' => 'DL8.48.3.1', 'NATURAL_GAS' => 'DL8.48.3.2',
        'GAS' => 'DL8.48.3.2', 'HYDROCARBONS' => 'DL8.48.3.3',
    ];

    /** Reasons for exemption (category E). Official examples also use VATEX-AE-* codes. */
    public const EXEMPTION_REASONS = [
        'DL8.46.1' => 'Certain financial services', 'DL8.46.2' => 'Supply of residential units (lease or sale)',
        'DL8.46.3' => 'Bare land', 'DL8.46.4' => 'Local passenger transport',
    ];

    public const ITEM_TYPES = ['G' => 'Goods', 'S' => 'Services', 'B' => 'Both'];
    public const ITEM_TYPE_ALIASES = ['GOODS' => 'G', 'SERVICES' => 'S', 'SERVICE' => 'S', 'BOTH' => 'B'];

    /** Credit note reason that does not need a preceding invoice (IBR-055-AE). */
    public const CREDIT_REASON_VOLUME_DISCOUNT = 'VD';

    public static function alias(array $map, ?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return $map[strtoupper($v)] ?? $v;
    }

    /** Emirate codes for cbc:CountrySubentity. */
    public const EMIRATES = [
        'AUH' => 'Abu Dhabi',
        'DXB' => 'Dubai',
        'SHJ' => 'Sharjah',
        'AJM' => 'Ajman',
        'UAQ' => 'Umm Al Quwain',
        'RAK' => 'Ras Al Khaimah',
        'FUJ' => 'Fujairah',
    ];

    /** Legal registration identifier types (CL is accepted by IBR-183-AE as well). */
    public const REGISTRATION_TYPES = ['TL', 'EID', 'PAS', 'CD', 'CL'];

    /** Payment means used in AE (UNCL4461 subset from the official code list). */
    public const PAYMENT_MEANS = [
        '1'  => 'Instrument not defined',
        '10' => 'In cash',
        '20' => 'Cheque',
        '21' => "Banker's draft",
        '30' => 'Credit transfer',
        '49' => 'Direct debit',
        '54' => 'Credit card',
        '55' => 'Debit card',
        '68' => 'Online payment service',
    ];

    public const PAYMENT_ALIASES = [
        'CASH' => '10', 'CHEQUE' => '20', 'CHECK' => '20', 'BANKERS_DRAFT' => '21', 'DRAFT' => '21',
        'CREDIT_TRANSFER' => '30', 'BANK_TRANSFER' => '30', 'TRANSFER' => '30', 'DIRECT_DEBIT' => '49',
        'CARD' => '54', 'CREDIT_CARD' => '54', 'DEBIT_CARD' => '55', 'ONLINE' => '68', 'ONLINE_PAYMENT' => '68',
    ];

    /** BTAE-20 is carried as AdditionalDocumentReference with this document type code. */
    public const AED_TOTAL_DOC_TYPE = 'aedtotal-incl-vat';

    public static function typeCode(string $docType, bool $selfBilled, bool $outOfScope): string
    {
        $set = self::TYPE_CODES[$docType] ?? self::TYPE_CODES['invoice'];
        if ($selfBilled) {
            return $set['self_billed'];
        }
        return $outOfScope ? $set['out_of_scope'] : $set['normal'];
    }

    /** @return array{0:string,1:bool,2:bool} [docType, selfBilled, outOfScope] */
    public static function describeTypeCode(string $code): array
    {
        foreach (self::TYPE_CODES as $doc => $set) {
            foreach ($set as $kind => $c) {
                if ($c === $code) {
                    return [$doc, $kind === 'self_billed', $kind === 'out_of_scope'];
                }
            }
        }
        return ['unknown', false, false];
    }

    /** @param string[] $modifiers */
    public static function flagsFromModifiers(array $modifiers): string
    {
        $alias = ['continuous' => 'continuous_supply', 'margin' => 'margin_scheme', 'agent' => 'disclosed_agent',
                  'free_zone' => 'free_trade_zone', 'e_commerce' => 'ecommerce', 'exports' => 'export'];
        $mods = array_map(fn ($m) => $alias[$m] ?? $m, $modifiers);
        $out = '';
        foreach (self::TRANSACTION_FLAGS as $flag) {
            $out .= in_array($flag, $mods, true) ? '1' : '0';
        }
        return $out;
    }

    /** @return string[] */
    public static function modifiersFromFlags(string $flags): array
    {
        $mods = [];
        foreach (self::TRANSACTION_FLAGS as $i => $flag) {
            if (($flags[$i] ?? '0') === '1') {
                $mods[] = $flag;
            }
        }
        return $mods;
    }

    public static function paymentMeans(string|int|null $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $code = strtoupper((string) $code);
        return self::PAYMENT_ALIASES[$code] ?? $code;
    }
}
