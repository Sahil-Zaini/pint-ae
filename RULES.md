# Validation rules

`pint-ae` checks documents with two kinds of rules.

| Prefix | Meaning |
|---|---|
| `IBR-xxx-AE`, `ALIGNED-IBRP-xxx` | A PHP re-implementation of the **official PINT AE rule with the same id** (PINT AE Billing 1.0.4, PDK 1.4.4). |
| `PAE-xxx` | Extra checks added by this library: arithmetic, structure, IBAN checksum and so on. |

## How it is verified

- Every billing example in `examples/xml` passes the **official PINT AE Schematron** with 0 errors and 0 warnings (`tests/official.py`, run in CI).
- The validator accepts **all 30 official PINT AE example invoices and credit notes**.
- 39 deliberately broken invoices were run through both the official Schematron and this validator. Every invoice the official rules reject is rejected here too.

The PHP validator is fast and has no dependencies, so you can run it on every save. For a final check before go-live, run the official Schematron (`tests/official.py`) or your ASP's validation.

### Where this validator is deliberately stricter

| Rule | Why |
|---|---|
| `IBR-128-AE` (emirate code) | In the official Schematron an earlier rule in the same pattern matches seller and buyer addresses first, so this assert never runs for them. The rule text clearly applies, so it is enforced here. |
| `IBR-005-AE`, `IBR-160-AE` (billing frequency) | The official Schematron checks `InvoicePeriod/DescriptionCode`, but the syntax binding and the official examples put the code in `InvoicePeriod/Description`. Both rules are checked on `Description`. |

## Official UAE rules — coverage

**130 of 132** UAE rules are implemented. The other two need no check of their own: `IBR-187-AE` is defined as `true()`, and `IBR-133-AE` is covered by `IBR-178-AE`.

| Rule | Status |
|---|---|
| `ALIGNED-IBRP-001-AE` | ✅ implemented |
| `ALIGNED-IBRP-002-AE` | ✅ implemented |
| `ALIGNED-IBRP-004` | ✅ implemented |
| `ALIGNED-IBRP-032` | ✅ implemented |
| `ALIGNED-IBRP-037` | ✅ implemented |
| `ALIGNED-IBRP-045` | ✅ implemented |
| `ALIGNED-IBRP-046` | ✅ implemented |
| `ALIGNED-IBRP-047` | ✅ implemented |
| `ALIGNED-IBRP-048` | ✅ implemented as `IBR-119-AE` (same check) |
| `ALIGNED-IBRP-057` | ✅ implemented |
| `ALIGNED-IBRP-058` | ✅ implemented |
| `ALIGNED-IBRP-AE-01-AE` | ✅ implemented |
| `ALIGNED-IBRP-AE-05-AE` | ✅ implemented |
| `ALIGNED-IBRP-AE-06` | ✅ implemented |
| `ALIGNED-IBRP-AE-07` | ✅ implemented |
| `ALIGNED-IBRP-AE-08-AE` | ✅ implemented |
| `ALIGNED-IBRP-AE-09-AE` | ✅ implemented |
| `ALIGNED-IBRP-E-01` | ✅ implemented |
| `ALIGNED-IBRP-E-05` | ✅ implemented |
| `ALIGNED-IBRP-E-06` | ✅ implemented |
| `ALIGNED-IBRP-E-07` | ✅ implemented |
| `ALIGNED-IBRP-E-08` | ✅ implemented |
| `ALIGNED-IBRP-E-09` | ✅ implemented |
| `ALIGNED-IBRP-O-01` | ✅ implemented |
| `ALIGNED-IBRP-O-05` | ✅ implemented |
| `ALIGNED-IBRP-O-06` | ✅ implemented |
| `ALIGNED-IBRP-O-07` | ✅ implemented |
| `ALIGNED-IBRP-O-08` | ✅ implemented |
| `ALIGNED-IBRP-O-09` | ✅ implemented |
| `ALIGNED-IBRP-O-11-AE` | ✅ implemented |
| `ALIGNED-IBRP-S-01` | ✅ implemented |
| `ALIGNED-IBRP-S-05` | ✅ implemented |
| `ALIGNED-IBRP-S-06` | ✅ implemented |
| `ALIGNED-IBRP-S-07` | ✅ implemented |
| `ALIGNED-IBRP-S-08` | ✅ implemented |
| `ALIGNED-IBRP-S-09` | ✅ implemented |
| `ALIGNED-IBRP-S-10` | ✅ implemented |
| `ALIGNED-IBRP-SR-12` | ✅ implemented |
| `ALIGNED-IBRP-Z-01` | ✅ implemented |
| `ALIGNED-IBRP-Z-05` | ✅ implemented |
| `ALIGNED-IBRP-Z-06` | ✅ implemented |
| `ALIGNED-IBRP-Z-07` | ✅ implemented |
| `ALIGNED-IBRP-Z-08` | ✅ implemented |
| `ALIGNED-IBRP-Z-09` | ✅ implemented |
| `IBR-001-AE` | ✅ implemented |
| `IBR-002-AE` | ✅ implemented |
| `IBR-005-AE` | ✅ implemented |
| `IBR-006-AE` | ✅ implemented |
| `IBR-007-AE` | ✅ implemented |
| `IBR-010-AE` | ✅ implemented |
| `IBR-011-AE` | ✅ implemented |
| `IBR-012-AE` | ✅ implemented |
| `IBR-013-AE` | ✅ implemented |
| `IBR-055-AE` | ✅ implemented |
| `IBR-101-AE` | ✅ implemented |
| `IBR-102-AE` | ✅ implemented |
| `IBR-103-AE` | ✅ implemented |
| `IBR-104-AE` | ✅ implemented |
| `IBR-105-AE` | ✅ implemented |
| `IBR-108-AE` | ✅ implemented |
| `IBR-111-AE` | ✅ implemented |
| `IBR-114-AE` | ✅ implemented |
| `IBR-115-AE` | ✅ implemented |
| `IBR-116-AE` | ✅ implemented |
| `IBR-119-AE` | ✅ implemented |
| `IBR-120-AE` | ✅ implemented |
| `IBR-121-AE` | ✅ implemented |
| `IBR-122-AE` | ✅ implemented |
| `IBR-123-AE` | built in — the generator always writes exactly one ClassifiedTaxCategory per line |
| `IBR-124-AE` | ✅ implemented |
| `IBR-125-AE` | ✅ implemented |
| `IBR-126-AE` | ✅ implemented |
| `IBR-127-AE` | ✅ implemented |
| `IBR-128-AE` | ✅ implemented |
| `IBR-131-AE` | ✅ implemented |
| `IBR-132-AE` | ✅ implemented |
| `IBR-133-AE` | n/a — only relevant when a TIN tax scheme is used; covered by IBR-178-AE |
| `IBR-134-AE` | ✅ implemented |
| `IBR-135-AE` | ✅ implemented |
| `IBR-136-AE` | ✅ implemented |
| `IBR-137-AE` | ✅ implemented |
| `IBR-138-AE` | ✅ implemented |
| `IBR-139-AE` | ✅ implemented |
| `IBR-140-AE` | ✅ implemented |
| `IBR-141-AE` | ✅ implemented (warning) |
| `IBR-142-AE` | ✅ implemented |
| `IBR-143-AE` | ✅ implemented |
| `IBR-144-AE` | ✅ implemented |
| `IBR-145-AE` | ✅ implemented |
| `IBR-146-AE` | ✅ implemented |
| `IBR-147-AE` | ✅ implemented |
| `IBR-148-AE` | ✅ implemented |
| `IBR-149-AE` | ✅ implemented |
| `IBR-150-AE` | ✅ implemented |
| `IBR-151-AE` | ✅ implemented |
| `IBR-152-AE` | ✅ implemented |
| `IBR-153-AE` | ✅ implemented |
| `IBR-154-AE` | ✅ implemented |
| `IBR-157-AE` | ✅ implemented |
| `IBR-158-AE` | ✅ implemented |
| `IBR-159-AE` | ✅ implemented |
| `IBR-160-AE` | ✅ implemented |
| `IBR-162-AE` | ✅ implemented |
| `IBR-163-AE` | ✅ implemented |
| `IBR-165-AE` | ✅ implemented |
| `IBR-166-AE` | ✅ implemented |
| `IBR-167-AE` | ✅ implemented |
| `IBR-168-AE` | ✅ implemented |
| `IBR-169-AE` | ✅ implemented |
| `IBR-172-AE` | ✅ implemented |
| `IBR-173-AE` | ✅ implemented |
| `IBR-174-AE` | ✅ implemented |
| `IBR-175-AE` | ✅ implemented |
| `IBR-176-AE` | ✅ implemented |
| `IBR-177-AE` | ✅ implemented |
| `IBR-178-AE` | ✅ implemented |
| `IBR-179-AE` | ✅ implemented |
| `IBR-180-AE` | ✅ implemented |
| `IBR-181-AE` | ✅ implemented |
| `IBR-183-AE` | ✅ implemented |
| `IBR-184-AE` | ✅ implemented |
| `IBR-185-AE` | ✅ implemented |
| `IBR-186-AE` | ✅ implemented |
| `IBR-187-AE` | n/a — the official rule is `true()` (no check) |
| `IBR-188-AE` | ✅ implemented |
| `IBR-189-AE` | ✅ implemented |
| `IBR-190-AE` | ✅ implemented |
| `IBR-191-AE` | ✅ implemented |
| `IBR-192-AE` | ✅ implemented |
| `IBR-193-AE` | ✅ implemented |
| `IBR-194-AE` | ✅ implemented |
| `IBR-196-AE` | ✅ implemented |

## Library rules (`PAE-xxx`)

| Rule | Checks |
|---|---|
| `PAE-000` | XML is not well-formed (parse error). |
| `PAE-001` | Root element must be a UBL 2.1 Invoice or CreditNote with the correct namespace. |
| `PAE-004` | Document number (cbc:ID) is missing. |
| `PAE-005` | IssueDate is missing or not YYYY-MM-DD. |
| `PAE-006` | Document type code must be 380, 381, 389, 261, 480 or 81 and must match the document and CustomizationID. |
| `PAE-007` | DocumentCurrencyCode must be a 3-letter ISO 4217 code. |
| `PAE-010` | Due date is before the issue date. |
| `PAE-011` | UUID is not in the standard 8-4-4-4-12 format. |
| `PAE-020` | Seller legal name is missing. |
| `PAE-022` | Seller EndpointID must use scheme 0235 and should be the 10-digit TIN; warns when an export buyer has no endpoint (use 9900000099). |
| `PAE-023` | Seller country is not AE. |
| `PAE-025` | Buyer legal name is missing. |
| `PAE-040` | Document has no lines. |
| `PAE-041` | Line IDs must be present and unique. |
| `PAE-042` | Line needs a quantity and unit code (e.g. C62, HUR, KGM). |
| `PAE-043` | Line net amount (LineExtensionAmount) is missing. |
| `PAE-047` | Line VAT amount (BTAE-08) must equal line net × rate. |
| `PAE-048` | Item name is missing. |
| `PAE-049` | Line amount payable (BTAE-10) must equal line net + line VAT. |
| `PAE-050` | Item type (BTAE-13) must be G, S or B. |
| `PAE-060` | LegalMonetaryTotal amounts are present and LineExtensionAmount equals the sum of line nets. |
| `PAE-061` | TaxExclusiveAmount ≠ LineExtension − allowances + charges. |
| `PAE-062` | TaxInclusiveAmount = TaxExclusiveAmount + VAT total. |
| `PAE-063` | PayableAmount ≠ TaxInclusive − Prepaid + Rounding. |
| `PAE-064` | TaxTotal with a VAT breakdown (TaxSubtotal) is missing. |
| `PAE-065` | VAT breakdown taxable amount must equal the lines (and allowances/charges) of that category. |
| `PAE-066` | VAT breakdown amount must equal taxable × rate. |
| `PAE-067` | Every VAT breakdown has matching lines, and every line category has a breakdown. |
| `PAE-068` | BTAE-20 total with VAT in AED does not match TaxInclusiveAmount × exchange rate. |
| `PAE-069` | AllowanceTotalAmount / ChargeTotalAmount do not match the document-level allowances and charges. |
| `PAE-070` | Allowance or charge needs a reason or reason code. |
| `PAE-081` | Continuous-supply invoices should state the invoicing period. |
| `PAE-084` | Export flag is set but the buyer address is in the UAE. |
| `PAE-090` | Payment means code should be in the AE payment means list (1, 10, 20, 21, 30, 49, 54, 55, 68). Warning. |
| `PAE-091` | IBAN fails the mod-97 checksum, or a UAE IBAN is not 23 characters. Warning. |
