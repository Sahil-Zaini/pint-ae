# Contributing

Thanks for helping UAE developers ship compliant e-invoices.

1. Fork, then create a branch: `git checkout -b fix/emirate-codes`
2. Make your change. Keep it dependency-free and PHP 8.1 compatible.
3. Add a test in `tests/run.php`. For a new rule, add a "broken invoice" case that must trigger it.
4. Run `php tests/run.php`. Every test must pass.
5. Open a pull request that explains **which official rule or spec page** the change follows. Link to docs.peppol.eu where you can.

**Rule ids.** Use the official id (`IBR-xxx-AE`, `ALIGNED-IBRP-xxx`) when you re-implement an official rule, and `PAE-xxx` for anything else. Update `RULES.md` either way.

**No real data.** Never commit real TRNs, company names, IBANs or customer invoices. Use the dummy values in `examples/`.
