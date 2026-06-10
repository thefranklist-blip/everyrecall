<?php
/**
 * recall_lib.php — EveryRecall core library.
 *
 * One normalized record shape everywhere:
 *   s   source key (FDA-FOOD, FDA-DRUG, FDA-DEVICE, CPSC, FSIS, HC, UK-OPSS, UK-FSA)
 *   c   country (US, CA, UK)
 *   id  source-native id
 *   d   date Y-m-d ('' when the source publishes none — HC dump has no date)
 *   t   title / headline
 *   p   product description
 *   r   reason / hazard
 *   k   classification (Class I/II/III where the source provides it)
 *   o   recalling firm / organization
 *   u   canonical URL at the source
 *
 * Live sources are queried per-request (they're fast and rate-limits are
 * generous). Bulk sources (HC, UK, FSIS) are served from local NDJSON
 * indexes that ingest.php replaces once a day, triggered by a visitor
 * beacon — see ingest.php for the lock/atomic-swap mechanics.
 */

const SITE_ORIGIN = 'https://everyrecall.org';   // adjust when domain is final
const SITE_NAME   = 'EveryRecall';

/* mbstring polyfills — most hosts have the extension, but recall data is
 * overwhelmingly ASCII, so byte-based fallbacks are acceptable when the
 * extension is missing. */
if (!function_exists('mb_strlen'))     { function mb_strlen($s) { return strlen($s); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower($s); } }
if (!function_exists('mb_strpos'))     { function mb_strpos($h, $n, $o = 0) { return strpos($h, $n, $o); } }
if (!function_exists('mb_stripos'))    { function mb_stripos($h, $n, $o = 0) { return stripos($h, $n, $o); } }
if (!function_exists('mb_substr'))     { function mb_substr($s, $st, $l = null) { return $l === null ? substr($s, $st) : substr($s, $st, $l); } }
if (!function_exists('mb_strimwidth')) { function mb_strimwidth($s, $st, $w, $tr = '') {
    $s = substr($s, $st);
    return strlen($s) <= $w ? $s : substr($s, 0, max(0, $w - strlen($tr))) . $tr;
} }
const DATA_DIR    = __DIR__ . '/data';
const META_FILE   = DATA_DIR . '/meta.json';
const REFRESH_TTL = 86400;            // 24h between ingest runs
const RECENT_DAYS = 548;              // 18 months = "recent" for the verdict

const SOURCE_LABELS = [
    'FDA-FOOD'   => ['FDA food enforcement', 'US'],
    'FDA-DRUG'   => ['FDA drug enforcement', 'US'],
    'FDA-DEVICE' => ['FDA device enforcement', 'US'],
    'CPSC'       => ['CPSC consumer products', 'US'],
    'FSIS'       => ['USDA FSIS meat & poultry', 'US'],
    'NHTSA'      => ['NHTSA vehicles', 'US'],
    'HC'         => ['Health Canada / CFIA / Transport Canada', 'CA'],
    'UK-OPSS'    => ['UK OPSS product safety', 'UK'],
    'UK-FSA'     => ['UK Food Standards Agency', 'UK'],
];

/* ----------------------------------------------------------------------
 * HTTP — curl preferred, stream fallback (some shared hosts lack curl).
 * Browser-ish UA matters: FSIS/FDA CDNs reject bare PHP UAs.
 * -------------------------------------------------------------------- */
function http_get(string $url, int $timeout = 25): ?string {
    $ua = 'Mozilla/5.0 (compatible; EveryRecall/1.0; +' . SITE_ORIGIN . ')';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Accept: application/json, text/plain, */*'],
            CURLOPT_ENCODING       => '',           // accept gzip
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        // openFDA quirk: HTTP 404 with a valid query means ZERO MATCHES,
        // not an error. Callers must treat null-with-404 as "clean".
        if ($body === false) return null;
        if ($code >= 400 && $code !== 404) return null;
        if ($code === 404) return '__NOT_FOUND__';
        return $body;
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout, 'follow_location' => 1,
        'header' => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
    }
    if ($status === 404) return '__NOT_FOUND__';
    if ($status >= 400) return null;
    return $body;
}

function load_meta(): array {
    $j = @file_get_contents(META_FILE);
    $m = $j ? json_decode($j, true) : null;
    return is_array($m) ? $m : ['last_refresh' => 0, 'sources' => []];
}

/* ----------------------------------------------------------------------
 * Live source searches. Each returns a list of normalized records.
 * A null return means "source unreachable" (distinct from "no recalls"),
 * which the verdict copy must reflect honestly.
 * -------------------------------------------------------------------- */
function fda_field(array $r, string $k): string { return trim((string)($r[$k] ?? '')); }
function fda_date(string $raw): string {
    return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : '';
}

function search_openfda(string $endpoint, string $sourceKey, string $q, int $limit = 10): ?array {
    // Phrase search against product_description, newest first.
    $url = "https://api.fda.gov/{$endpoint}/enforcement.json?search="
         . rawurlencode('product_description:"' . $q . '"')
         . "&sort=recall_initiation_date:desc&limit={$limit}";
    $body = http_get($url);
    if ($body === null) return null;
    if ($body === '__NOT_FOUND__') return [];          // zero matches = clean
    $d = json_decode($body, true);
    $out = [];
    foreach (($d['results'] ?? []) as $r) {
        $out[] = [
            's' => $sourceKey, 'c' => 'US',
            'id'=> fda_field($r, 'recall_number'),
            'd' => fda_date(fda_field($r, 'recall_initiation_date')),
            't' => mb_strimwidth(fda_field($r, 'product_description'), 0, 160, '…'),
            'p' => fda_field($r, 'product_description'),
            'r' => fda_field($r, 'reason_for_recall'),
            'k' => fda_field($r, 'classification'),
            'o' => fda_field($r, 'recalling_firm'),
            'u' => 'https://www.accessdata.fda.gov/scripts/ires/index.cfm', // FDA IRES search hub
        ];
    }
    return $out;
}

function search_cpsc(string $q, int $limit = 10): ?array {
    $url = 'https://www.saferproducts.gov/RestWebServices/Recall?format=json&ProductName=' . rawurlencode($q);
    $body = http_get($url);
    if ($body === null) return null;
    if ($body === '__NOT_FOUND__') return [];
    $d = json_decode($body, true);
    if (!is_array($d)) return null;
    // Newest first; the API returns oldest-first.
    usort($d, fn($a, $b) => strcmp((string)($b['RecallDate'] ?? ''), (string)($a['RecallDate'] ?? '')));
    $out = [];
    foreach (array_slice($d, 0, $limit) as $r) {
        $prods   = $r['Products'][0]['Name'] ?? '';
        $hazards = $r['Hazards'][0]['Name']  ?? '';
        $out[] = [
            's' => 'CPSC', 'c' => 'US',
            'id'=> (string)($r['RecallNumber'] ?? $r['RecallID'] ?? ''),
            'd' => substr((string)($r['RecallDate'] ?? ''), 0, 10),
            't' => trim((string)($r['Title'] ?? '')),
            'p' => trim((string)$prods),
            'r' => trim((string)$hazards),
            'k' => '',
            'o' => trim((string)($r['Manufacturers'][0]['Name'] ?? '')),
            'u' => trim((string)($r['URL'] ?? 'https://www.cpsc.gov/Recalls')),
        ];
    }
    return $out;
}

function search_nhtsa(string $make, string $model, string $year): ?array {
    $url = 'https://api.nhtsa.gov/recalls/recallsByVehicle?make=' . rawurlencode($make)
         . '&model=' . rawurlencode($model) . '&modelYear=' . rawurlencode($year);
    $body = http_get($url);
    if ($body === null || $body === '__NOT_FOUND__') return $body === null ? null : [];
    $d = json_decode($body, true);
    $out = [];
    foreach (($d['results'] ?? []) as $r) {
        $rawDate = (string)($r['ReportReceivedDate'] ?? '');   // dd/mm/yyyy
        $date = preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $rawDate, $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : '';
        $out[] = [
            's' => 'NHTSA', 'c' => 'US',
            'id'=> (string)($r['NHTSACampaignNumber'] ?? ''),
            'd' => $date,
            't' => trim((string)($r['Component'] ?? '')),
            'p' => trim("{$year} {$make} {$model}"),
            'r' => mb_strimwidth(trim((string)($r['Summary'] ?? '')), 0, 280, '…'),
            'k' => '',
            'o' => trim((string)($r['Manufacturer'] ?? '')),
            'u' => 'https://www.nhtsa.gov/recalls',
        ];
    }
    return $out;
}

/* ----------------------------------------------------------------------
 * Local NDJSON indexes (written by ingest.php). Streaming search keeps
 * memory flat even though the HC index alone is ~30k rows.
 * -------------------------------------------------------------------- */
function search_local(string $file, string $q, int $limit = 12): ?array {
    $path = DATA_DIR . "/{$file}.ndjson";
    if (!is_file($path)) return null;                  // not ingested yet
    $fh = @fopen($path, 'r');
    if (!$fh) return null;
    $needle = mb_strtolower($q);
    $out = [];
    while (($line = fgets($fh)) !== false) {
        if (mb_stripos($line, $needle) === false) continue;   // fast pre-filter
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        $hay = mb_strtolower(($r['t'] ?? '') . ' ' . ($r['p'] ?? '') . ' ' . ($r['o'] ?? ''));
        if (mb_strpos($hay, $needle) === false) continue;
        $out[] = $r;
        if (count($out) >= $limit) break;              // files are newest-first
    }
    fclose($fh);
    return $out;
}

/* ----------------------------------------------------------------------
 * The full cross-database search + verdict.
 * -------------------------------------------------------------------- */
function screen_query(string $q): array {
    $q = trim($q);
    $bySource    = [];
    $unreachable = [];

    $live = [
        'FDA-FOOD'   => fn() => search_openfda('food',   'FDA-FOOD',   $q),
        'FDA-DRUG'   => fn() => search_openfda('drug',   'FDA-DRUG',   $q),
        'FDA-DEVICE' => fn() => search_openfda('device', 'FDA-DEVICE', $q),
        'CPSC'       => fn() => search_cpsc($q),
    ];
    $local = ['HC' => 'hc', 'UK-OPSS' => 'uk_opss', 'UK-FSA' => 'uk_fsa', 'FSIS' => 'fsis'];

    foreach ($live as $key => $fn) {
        $r = $fn();
        if ($r === null) $unreachable[] = $key; else $bySource[$key] = $r;
    }
    foreach ($local as $key => $file) {
        $r = search_local($file, $q);
        if ($r === null) $unreachable[] = $key; else $bySource[$key] = $r;
    }
    return ['results' => $bySource, 'unreachable' => $unreachable,
            'verdict' => compute_verdict($bySource, $unreachable)];
}

/** Which sources are actually live right now (live APIs assumed up;
 *  local indexes must have a non-empty file). Used so the homepage
 *  source list never advertises a database we aren't really checking. */
function active_sources(): array {
    $active = ['FDA-FOOD', 'FDA-DRUG', 'FDA-DEVICE', 'CPSC', 'NHTSA'];
    foreach (['HC' => 'hc', 'UK-OPSS' => 'uk_opss', 'UK-FSA' => 'uk_fsa', 'FSIS' => 'fsis'] as $k => $f) {
        if (is_file(DATA_DIR . "/{$f}.ndjson") && filesize(DATA_DIR . "/{$f}.ndjson") > 0) $active[] = $k;
    }
    return $active;
}

function compute_verdict(array $bySource, array $unreachable): array {
    $total = 0; $newestTs = 0; $datedHits = 0; $undatedHits = 0;
    foreach ($bySource as $rows) {
        foreach ($rows as $r) {
            $total++;
            if (!empty($r['d'])) { $datedHits++; $ts = strtotime($r['d']); if ($ts) $newestTs = max($newestTs, $ts); }
            else $undatedHits++;
        }
    }
    $checked = count($bySource);
    $dbNote  = "{$checked} official database" . ($checked === 1 ? '' : 's')
             . ($unreachable ? ' (' . count($unreachable) . ' source' . (count($unreachable) === 1 ? '' : 's') . ' unreachable — try again shortly)' : '');

    if ($total === 0) {
        return ['level' => 'clean', 'headline' => 'No recalls found',
                'detail' => "No matching recall records across {$dbNote}. A clean result is not a safety guarantee — check the exact lot or model number with the manufacturer for anything safety-critical."];
    }
    $recent = $newestTs && $newestTs >= (time() - RECENT_DAYS * 86400);
    if ($recent) {
        return ['level' => 'recent', 'headline' => 'Recent recall on record',
                'detail' => "The newest matching record is dated " . date('F j, Y', $newestTs) . ". Read the matched records below and check whether your exact product, lot, or model is included — recalls usually cover specific lots, not whole brands."];
    }
    if ($datedHits) {
        return ['level' => 'past', 'headline' => 'Past recalls on record — nothing recent',
                'detail' => "Matching records exist but the newest is dated " . date('F j, Y', $newestTs) . ". Older recalls can still matter for secondhand or stored products."];
    }
    return ['level' => 'undated', 'headline' => 'Recall records found',
            'detail' => "Matching records were found in sources that don't publish machine-readable dates (e.g. the Canadian recall registry). Open the records below for details."];
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ----------------------------------------------------------------------
 * Related federal court cases — CourtListener (Free Law Project).
 *
 * Recipe (validated June 2026): fielded caseName:(...) query for the
 * brand + aliases, filtered to product-liability nature-of-suit codes
 * (365 personal injury, 385 property damage, 195 contract, 370 fraud —
 * the codes consumer/product class actions file under). Full-text
 * search is far too noisy; party-name matching is precise.
 *
 * Etiquette: CourtListener is run by a nonprofit. Results are cached
 * for CL_CACHE_TTL per brand so traffic spikes never translate into
 * API hammering. An API token (free signup) raises rate limits —
 * set CL_TOKEN when you have one; anonymous works for low volume.
 * -------------------------------------------------------------------- */
const CL_TOKEN     = 'Token 2ce0544fee733fec2354b8ce871c3664e337039e'; // courtlistener.com API key
const CL_CACHE_TTL = 86400;

function search_courtlistener(array $terms, string $cacheKey, int $limit = 6): ?array {
    $cacheDir = DATA_DIR . '/cl_cache';
    $cache    = $cacheDir . '/' . preg_replace('/[^a-z0-9-]/', '', strtolower($cacheKey)) . '.json';
    if (is_file($cache) && time() - filemtime($cache) < CL_CACHE_TTL) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d)) return $d;
    }
    $names = implode(' OR ', array_map(fn($t) => '"' . str_replace('"', '', $t) . '"', $terms));
    $url = 'https://www.courtlistener.com/api/rest/v4/search/?type=r&order_by=' . rawurlencode('dateFiled desc')
         . '&q=' . rawurlencode("caseName:({$names})")
         . '&nature_of_suit=' . rawurlencode('"365" OR "385" OR "195" OR "370"');

    $ua = 'Mozilla/5.0 (compatible; EveryRecall/1.0; +' . SITE_ORIGIN . ')';
    $headers = ['Accept: application/json'];
    if (CL_TOKEN !== '') $headers[] = 'Authorization: ' . CL_TOKEN;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => '']);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) return is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    } else {
        $body = http_get($url);
        if ($body === null || $body === '__NOT_FOUND__') return is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    }
    $d = json_decode($body, true);
    $out = ['count' => (int)($d['count'] ?? 0), 'cases' => []];
    foreach (array_slice($d['results'] ?? [], 0, $limit) as $r) {
        $out['cases'][] = [
            'name'   => trim((string)($r['caseName'] ?? '')),
            'filed'  => substr((string)($r['dateFiled'] ?? ''), 0, 10),
            'closed' => substr((string)($r['dateTerminated'] ?? ''), 0, 10),
            'court'  => trim((string)($r['court_citation_string'] ?? $r['court'] ?? '')),
            'nos'    => trim((string)($r['suitNature'] ?? '')),
            'no'     => trim((string)($r['docketNumber'] ?? '')),
            'url'    => 'https://www.courtlistener.com' . (string)($r['docket_absolute_url'] ?? '/'),
        ];
    }
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    @file_put_contents($cache . '.tmp', json_encode($out));
    @rename($cache . '.tmp', $cache);
    return $out;
}
