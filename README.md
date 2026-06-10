# EveryRecall — cross-database product recall checker

One search screens official recall records from the US (openFDA food/drug/
device enforcement, CPSC SaferProducts, USDA FSIS, NHTSA), Canada (Health
Canada / CFIA / Transport Canada open-data registry), and the UK (OPSS,
Food Standards Agency).

## Architecture
- **Live at query time:** openFDA ×3, CPSC, NHTSA (fast APIs, generous limits).
  openFDA returns HTTP 404 for *zero matches* — recall_lib.php treats that as
  the clean verdict, not an error.
- **Local daily indexes:** data/{hc,uk_opss,uk_fsa,fsis}.ndjson, searched by
  streaming (flat memory even at 33k+ rows), written newest-first.
- **Client-triggered ingestion (no cron):** every page fires
  `navigator.sendBeacon('/refresh')` at most once per browser per day.
  ingest.php exits in microseconds unless data is >24h old; then exactly one
  request wins a non-blocking flock, releases its visitor via
  fastcgi_finish_request, downloads each source, and **atomically replaces**
  each index (tmp + rename). A failed source never clobbers the previous good
  index, and a too-small download is rejected outright.

## Deploy
1. Upload everything; ensure `data/` is writable by PHP (chmod 775/777
   depending on host). `.htaccess` already denies all direct access to it.
2. Set the final domain in recall_lib.php (SITE_ORIGIN), .htaccess,
   index.html, robots.txt, llms.txt if not everyrecall.org.
3. Visit the homepage once — your own beacon performs the first ingest
   (first run takes ~60–90s in the background; check /api?status=1).
4. FSIS note: their CDN rejects datacenter IPs (this build sandbox got 403)
   but accepts normal web hosts. If it still fails on your host, the error is
   recorded in meta.json and everything else keeps working.
5. Search Console + Bing Webmaster Tools + Brave: submit /sitemap.xml.

## SEO/GEO decisions baked in
- Answer-first titles with verdict tags on brand pages; honest dates only
  (dateModified = newest matching record, sitemap lastmod = real ingest date).
- Unknown brand slugs render noindexed 404s.
- robots.txt explicitly welcomes AI crawlers; llms.txt describes the verdict
  semantics so assistants summarize correctly.
- Brand pages are legitimate programmatic SEO: each is a genuinely different
  live cross-database query, never a template swap.

## Adding brand pages
Append to brands.json (slug/display/aliases/category). brand.php and
sitemap.php pick new entries up automatically — no other step.
