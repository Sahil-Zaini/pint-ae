# Changelog

## 1.0.0 — 2026-10-17

First public release, checked against the official PINT AE Billing 1.0.4 resources.

- `PintAE::generate()` builds PINT AE Invoice / CreditNote XML (UBL 2.1) from a plain array, with all totals, the VAT breakdown and AED amounts calculated.
- `PintAE::validate()` checks 130 of the 132 official UAE rules under their real ids, plus 35 library checks.
- `PintAE::toJson()` / `toArray()` read PINT AE XML back into JSON.
- 17 scenarios: standard, credit note, volume-discount credit note, self-billed invoice and credit note, export, out of scope, free zone, deemed supply, continuous supply, disclosed agent, e-commerce, margin scheme, summary, reverse charge, discounts and charges, foreign currency.
- Line-level and document-level allowances and charges, by amount or by percentage.
- Official code lists for credit note reasons, billing frequency, reverse-charge goods, exemption reasons, item types and payment means, with friendly aliases.
- Seller TIN, passport registration with issuing country, and predefined endpoints for exports and deemed supply.
- Every billing example passes the official PINT AE Schematron with 0 errors, and the validator accepts all 30 official example documents (`tests/official.py`, run in CI).
- Command-line tool `bin/pint-ae` and a no-database PHP playground in `demo/`.
- Zero dependencies, PHP 8.1+.
