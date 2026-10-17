# UBL 2.1 schemas (for tests only)

OASIS UBL 2.1 XSDs (maindoc + common), with the CCTS, XML-DSig and XAdES
modules they import. Copied from the Apache-2.0 licensed
[phax/ph-ubl](https://github.com/phax/ph-ubl) and [phax/ph-xsds](https://github.com/phax/ph-xsds)
projects; `xsd:import` elements were given local `schemaLocation`s so the set
works offline. OASIS UBL is © OASIS Open, distributed under the OASIS IPR policy.

Used by `tests/run.php` to prove every generated document is schema-valid.
