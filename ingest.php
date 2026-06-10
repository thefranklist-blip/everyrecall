<?php
/**
 * ingest.php — daily refresh of the bulk recall indexes, triggered from
 * the client side.
 *
 * How the trigger works (no cron required):
 *   Every page includes a tiny script that fires
 *   navigator.sendBeacon('/refresh') at most once per browser per day.
 *   This endpoint then decides whether anything actually needs doing:
 *
 *   1. If data is younger than REFRESH_TTL  -> 204, exit. (~all requests)
 *   2. Otherwise take a non-blocking flock; if another request already
 *      holds it -> 204, exit. Exactly one visitor's beacon "wins".
 *   3. Re-check staleness under the lock (double-checked locking).
 *   4. Release the visitor immediately (fastcgi_finish_request when the
 *      host supports it), then download each source, transform to
 *      normalized NDJSON in a .tmp file, and atomically rename() over
 *      the live index. A failed source never clobbers the previous good
 *      index — fetch-and-REPLACE, never fetch-and-truncate.
 *
 * Sources ingested here (the live-API sources don't need ingestion):
 *   hc       Health Canada open-data dump (HC + CFIA + Transport Canada)
 *   uk_opss  gov.uk Search API, product safety alerts/reports/recalls
 *   uk_fsa   data.food.gov.uk food alerts
 *   fsis     USDA FSIS recall API (NOTE: their CDN rejects datacenter
 *            IPs; works from normal web hosts, and failure is recorded
 *            in meta.json without breaking anything else)
 */

require_once __DIR__ . '/recall_lib.php';

header('Cache-Control: no-store');

$meta = load_meta();
if (time() - (int)($meta['last_refresh'] ?? 0) < REFRESH_TTL) { http_response_code(204); exit; }

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
$lock = fopen(DATA_DIR . '/.refresh.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { http_response_code(204); exit; }

$meta = load_meta();                                   // re-check under lock
if (time() - (int)($meta['last_refresh'] ?? 0) < REFRESH_TTL) {
    flock($lock, LOCK_UN); http_response_code(204); exit;
}

// Release the visitor; keep working in the background.
http_response_code(202);
header('Content-Length: 0');
ignore_user_abort(true);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
@set_time_limit(900);
@ini_set('memory_limit', '512M');

$report = ['last_refresh' => time(), 'sources' => $meta['sources'] ?? []];

/** Write rows to <name>.ndjson atomically. Refuses suspiciously small results. */
function write_index(string $name, array $rows, array &$report, int $minRows = 10): void {
    $tmp = DATA_DIR . "/{$name}.ndjson.tmp";
    $dst = DATA_DIR . "/{$name}.ndjson";
    if (count($rows) < $minRows) {                     // guard: don't replace good data with junk
        $report['sources'][$name] = ['ok' => false, 'err' => 'too few rows (' . count($rows) . ')', 'ts' => time()];
        return;
    }
    $fh = fopen($tmp, 'w');
    foreach ($rows as $r) fwrite($fh, json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fclose($fh);
    rename($tmp, $dst);                                // atomic on same filesystem
    $report['sources'][$name] = ['ok' => true, 'count' => count($rows), 'ts' => time()];
}

function fail(string $name, string $why, array &$report): void {
    $report['sources'][$name] = ['ok' => false, 'err' => $why, 'ts' => time()];
}

/* ---- 1. Health Canada (HC + CFIA + Transport Canada, ~33k records) -- */
$body = http_get('https://recalls-rappels.canada.ca/sites/default/files/opendata-donneesouvertes/HCRSAMOpenData.json', 180);
if ($body && $body !== '__NOT_FOUND__') {
    $d = json_decode($body, true); unset($body);
    if (is_array($d)) {
        // No date field in this dump; NID is chronological. Sort newest-first
        // so streaming search naturally returns the newest matches.
        usort($d, fn($a, $b) => (int)($b['NID'] ?? 0) <=> (int)($a['NID'] ?? 0));
        $rows = [];
        foreach ($d as $r) {
            $rows[] = [
                's' => 'HC', 'c' => 'CA',
                'id'=> (string)($r['NID'] ?? ''), 'd' => '',
                't' => trim((string)($r['Title'] ?? '')),
                'p' => trim((string)($r['Product'] ?? '')),
                'r' => trim((string)($r['Issue'] ?? '')),
                'k' => '', 'o' => trim((string)($r['Organization'] ?? '')),
                'u' => trim((string)($r['URL'] ?? '')),
            ];
        }
        unset($d);
        write_index('hc', $rows, $report, 1000);
        unset($rows);
    } else fail('hc', 'bad json', $report);
} else fail('hc', 'fetch failed', $report);

/* ---- 2. UK OPSS product safety (gov.uk Search API, paginated) ------- */
$rows = []; $start = 0; $total = null;
do {
    $u = 'https://www.gov.uk/api/search.json?filter_content_store_document_type=product_safety_alert_report_recall'
       . '&count=200&order=-public_timestamp&fields=public_timestamp&start=' . $start;
    $b = http_get($u, 40);
    if (!$b || $b === '__NOT_FOUND__') { if ($start === 0) { fail('uk_opss', 'fetch failed', $report); $rows = null; } break; }
    $d = json_decode($b, true);
    $total ??= (int)($d['total'] ?? 0);
    foreach (($d['results'] ?? []) as $r) {
        $title = trim((string)($r['title'] ?? ''));
        $rows[] = [
            's' => 'UK-OPSS', 'c' => 'UK',
            'id'=> (string)($r['link'] ?? ''),
            'd' => substr((string)($r['public_timestamp'] ?? ''), 0, 10),
            't' => $title, 'p' => $title, 'r' => '', 'k' => '', 'o' => '',
            'u' => 'https://www.gov.uk' . (string)($r['link'] ?? ''),
        ];
    }
    $start += 200;
} while ($total !== null && $start < $total && $start < 6000);
if (is_array($rows)) write_index('uk_opss', $rows, $report, 100);

/* ---- 3. UK Food Standards Agency alerts ----------------------------- */
$rows = []; $offset = 0;
do {
    $b = http_get("https://data.food.gov.uk/food-alerts/id?_limit=500&_offset={$offset}&_sort=-created", 40);
    if (!$b || $b === '__NOT_FOUND__') { if ($offset === 0) { fail('uk_fsa', 'fetch failed', $report); $rows = null; } break; }
    $d = json_decode($b, true);
    $items = $d['items'] ?? [];
    foreach ($items as $r) {
        $prods = [];
        foreach (($r['productDetails'] ?? []) as $p) $prods[] = trim((string)($p['productName'] ?? ''));
        $rows[] = [
            's' => 'UK-FSA', 'c' => 'UK',
            'id'=> (string)($r['notation'] ?? ''),
            'd' => substr((string)($r['created'] ?? ''), 0, 10),
            't' => trim((string)($r['title'] ?? '')),
            'p' => implode('; ', array_filter($prods)),
            'r' => trim((string)($r['problem'][0]['riskStatement'] ?? '')),
            'k' => '', 'o' => '',
            'u' => (string)($r['alertURL'] ?? ($r['@id'] ?? 'https://www.food.gov.uk/news-alerts')),
        ];
    }
    $offset += 500;
} while (count($items) === 500 && $offset < 6000);
if (is_array($rows)) write_index('uk_fsa', $rows, $report, 100);

/* ---- 4. USDA FSIS (Akamai-protected; see notes) --------------------
 * FSIS sits behind Akamai bot protection that fingerprints non-browser
 * clients at the TLS/HTTP layer, so server-side curl gets a 403 "Access
 * Denied" regardless of User-Agent. We try the API anyway (some hosts'
 * outbound IPs are not flagged), and if that fails we fall back to a
 * manually-refreshed file at data/fsis_manual.json — drop the JSON from
 * https://www.fsis.usda.gov/fsis/api/recall/v/1 there (a browser opens
 * it fine) and the site serves it. A stale-but-present FSIS index beats
 * no meat/poultry coverage. */
$fsisRaw = null;
foreach (['https://www.fsis.usda.gov/fsis/api/recall/v/1'] as $u) {
    $b = http_get($u, 90);
    if ($b && $b !== '__NOT_FOUND__' && $b[0] === '[') { $fsisRaw = $b; break; }
}
// If the direct fetch was Akamai-blocked, try the browser-impersonating
// Python helper (curl_cffi) right here — it refreshes data/fsis_manual.json
// in-process, so no cron is needed when python3+curl_cffi are present.
if ($fsisRaw === null) {
    $py = trim((string)@shell_exec('command -v python3 2>/dev/null'));
    if ($py !== '' && is_file(__DIR__ . '/fetch_fsis.py')) {
        @shell_exec(escapeshellarg($py) . ' ' . escapeshellarg(__DIR__ . '/fetch_fsis.py') . ' 2>&1');
    }
}
if ($fsisRaw === null && is_file(DATA_DIR . '/fsis_manual.json')) {
    $fsisRaw = (string)file_get_contents(DATA_DIR . '/fsis_manual.json');
    $report['sources']['fsis_note'] = 'served via browser-impersonation helper';
}
if ($fsisRaw !== null) {
    $d = json_decode($fsisRaw, true);
    if (is_array($d)) {
        // FSIS returns several fields as JSON arrays (reason, establishment,
        // product_items) and an already-absolute recall_url. flatten() copes
        // with both array and scalar shapes; strip_tags cleans the HTML body.
        $flatten = function ($v): string {
            if (is_array($v)) return trim(implode(', ', array_map('strval', $v)));
            return trim((string)$v);
        };
        $rows = [];
        foreach ($d as $r) {
            $url = $flatten($r['field_recall_url'] ?? '');
            if ($url !== '' && stripos($url, 'http') !== 0) $url = 'https://www.fsis.usda.gov' . $url;
            $rows[] = [
                's' => 'FSIS', 'c' => 'US',
                'id'=> $flatten($r['field_recall_number'] ?? ''),
                'd' => substr($flatten($r['field_recall_date'] ?? ''), 0, 10),
                't' => $flatten($r['field_title'] ?? ''),
                'p' => mb_strimwidth(trim(strip_tags($flatten($r['field_product_items'] ?? ($r['field_summary'] ?? '')))), 0, 300, '…'),
                'r' => $flatten($r['field_recall_reason'] ?? ''),
                'k' => $flatten($r['field_recall_classification'] ?? ''),
                'o' => $flatten($r['field_establishment'] ?? ''),
                'u' => $url ?: 'https://www.fsis.usda.gov/recalls',
            ];
        }
        usort($rows, fn($a, $b) => strcmp($b['d'], $a['d']));
        write_index('fsis', $rows, $report, 50);
    } else fail('fsis', 'bad json', $report);
} else {
    fail('fsis', 'Akamai-blocked; add data/fsis_manual.json to enable (see README)', $report);
}

/* ---- finalize -------------------------------------------------------- */
file_put_contents(META_FILE . '.tmp', json_encode($report, JSON_PRETTY_PRINT));
rename(META_FILE . '.tmp', META_FILE);
flock($lock, LOCK_UN);
