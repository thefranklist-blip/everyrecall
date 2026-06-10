#!/usr/bin/env python3
"""
fetch_fsis.py — pull the Akamai-protected USDA FSIS recall feed using a
real browser TLS/HTTP2 fingerprint, normalize it, and write it where the
PHP ingester expects it (data/fsis_manual.json).

Why this exists: FSIS wraps its recall API in Akamai bot protection that
fingerprints the TLS handshake (JA3) and HTTP/2 frame order. Server-side
curl/PHP get a 403 regardless of User-Agent. curl-impersonate (via the
curl_cffi package) replicates Chrome's exact fingerprint and sails through.

Run on the VPS once a day from cron:
    0 4 * * *  /usr/bin/python3 /path/to/site/fetch_fsis.py >> /var/log/fsis.log 2>&1

Requires:  pip install curl_cffi   (no system browser needed)

The PHP ingester (ingest.php) reads data/fsis_manual.json automatically,
so this script is the ONLY FSIS-specific moving part. Everything else in
the daily refresh stays client-beacon triggered.
"""
import json, os, sys, tempfile

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data")
DST      = os.path.join(DATA_DIR, "fsis_manual.json")
URL      = "https://www.fsis.usda.gov/fsis/api/recall/v/1"


def main() -> int:
    try:
        from curl_cffi import requests as creq
    except ImportError:
        sys.stderr.write("curl_cffi not installed. Run: pip install curl_cffi\n")
        return 2

    try:
        r = creq.get(URL, impersonate="chrome", timeout=60)
    except Exception as e:                       # network / TLS failure
        sys.stderr.write(f"fetch failed: {e}\n")
        return 1
    if r.status_code != 200:
        sys.stderr.write(f"unexpected status {r.status_code}\n")
        return 1

    try:
        raw = r.json()
    except Exception as e:
        sys.stderr.write(f"bad json: {e}\n")
        return 1
    if not isinstance(raw, list) or len(raw) < 50:
        sys.stderr.write(f"suspiciously few records ({len(raw) if isinstance(raw, list) else 'n/a'}); not writing\n")
        return 1

    os.makedirs(DATA_DIR, exist_ok=True)
    # Slim each record to only the fields ingest.php reads. The raw feed is
    # ~11 MB — field_summary alone is 8 MB of HTML press-release bodies. The
    # ingester only uses summary as a 300-char fallback when product_items is
    # empty, so we keep a short text-only snippet. Result: ~1 MB committed
    # file = a light repo and a fast cPanel pull.
    import re as _re
    keep = ("field_title", "field_recall_date", "field_recall_number",
            "field_recall_classification", "field_recall_reason",
            "field_establishment", "field_recall_url", "field_product_items")

    def snippet(html_text: str) -> str:
        txt = _re.sub(r"<[^>]+>", " ", str(html_text))
        txt = _re.sub(r"\s+", " ", txt).strip()
        return txt[:300]

    slim = []
    for rec in raw:
        row = {k: rec.get(k, "") for k in keep if k in rec}
        # only carry a summary snippet when there are no product items
        if not rec.get("field_product_items") and rec.get("field_summary"):
            row["field_summary"] = snippet(rec["field_summary"])
        slim.append(row)

    fd, tmp = tempfile.mkstemp(dir=DATA_DIR, suffix=".tmp")
    with os.fdopen(fd, "w") as f:
        json.dump(slim, f, separators=(",", ":"))
    os.replace(tmp, DST)                          # atomic
    sz = os.path.getsize(DST)
    print(f"wrote {len(slim)} FSIS records to {DST} ({sz // 1024} KB)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
