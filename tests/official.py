"""
Validate the generated examples with the OFFICIAL PINT AE Schematron.

    pip install saxonche
    python3 tests/official.py                 # downloads resources.zip from docs.peppol.eu
    python3 tests/official.py path/to/resources.zip

Exit code 1 if any billing example has a fatal error.

Self-billing documents (type 389 / 261) follow the separate PINT AE
Self-Billing specification, so the Billing Schematron is not run on them.
"""
import glob
import io
import shutil
import subprocess
import os
import re
import sys
import urllib.request
import zipfile

from saxonche import PySaxonProcessor

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
URL = "https://docs.peppol.eu/poac/ae/pint-ae/resources.zip"
CACHE = os.path.join(HERE, ".cache", "pint-ae-resources")


def resources(arg):
    if arg and os.path.isdir(arg):
        return arg
    if not os.path.isdir(CACHE):
        data = open(arg, "rb").read() if arg else urllib.request.urlopen(URL, timeout=60).read()
        zipfile.ZipFile(io.BytesIO(data)).extractall(CACHE)
    return CACHE


def main():
    res = resources(sys.argv[1] if len(sys.argv) > 1 else None)
    proc = PySaxonProcessor(license=False)
    xslt = proc.new_xslt30_processor()
    compiled = {}

    def sheet(kind, name):
        key = (kind, name)
        if key not in compiled:
            compiled[key] = xslt.compile_stylesheet(stylesheet_file=f"{res}/trn-{kind}/schematron/{name}.xslt")
        return compiled[key]

    fatal_total, checked, skipped = 0, 0, []
    for path in sorted(glob.glob(os.path.join(ROOT, "examples", "xml", "*.xml"))):
        name = os.path.basename(path)
        data = open(path, encoding="utf-8").read()
        if re.search(r"<cbc:(Invoice|CreditNote)TypeCode>(389|261)<", data):
            skipped.append(name)
            continue
        kind = "creditnote" if "<CreditNote" in data[:600] else "invoice"
        issues = []
        for rules in ("PINT-UBL-validation-preprocessed", "PINT-jurisdiction-aligned-rules"):
            svrl = sheet(kind, rules).transform_to_string(xdm_node=proc.parse_xml(xml_text=data))
            for m in re.finditer(r"<svrl:failed-assert(.*?)</svrl:failed-assert>", svrl, re.S):
                attrs = m.group(1)
                flag = (re.search(r'flag="([^"]+)"', attrs) or [None, "?"])[1]
                rid = (re.search(r'id="([^"]+)"', attrs) or [None, "?"])[1]
                text = re.sub(r"\s+", " ", (re.search(r"<svrl:text>(.*?)</svrl:text>", attrs, re.S) or [None, ""])[1])
                issues.append((flag, rid, text.strip()))
        fatal = [i for i in issues if i[0] == "fatal"]
        fatal_total += len(fatal)
        checked += 1
        mark = "OK  " if not fatal else "FAIL"
        print(f"{mark} {name}: {len(fatal)} fatal, {len(issues) - len(fatal)} warning")
        for flag, rid, text in issues:
            print(f"       [{flag}] {rid}: {text[:150]}")

    print(f"\n{checked} documents checked with the official PINT AE Schematron, {fatal_total} fatal error(s).")
    if skipped:
        print("Not checked (PINT AE Self-Billing spec): " + ", ".join(skipped))
    # Reverse check: the library must accept every official example.
    rejected = 0
    if shutil.which("php"):
        examples = sorted(glob.glob(f"{res}/trn-*/example/*.xml"))
        for ex in examples:
            r = subprocess.run(["php", os.path.join(ROOT, "bin", "pint-ae"), "validate", ex], capture_output=True, text=True)
            if r.returncode != 0:
                rejected += 1
                print(f"REJECTED official example {os.path.basename(ex)}:\n{r.stdout}")
        print(f"pint-ae accepted {len(examples) - rejected} of {len(examples)} official PINT AE examples.")
    sys.exit(1 if fatal_total or rejected else 0)


if __name__ == "__main__":
    main()
