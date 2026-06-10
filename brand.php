<?php
/**
 * brand.php — programmatic landing pages: /is-{slug}-recalled/
 *
 * Every page is backed by a genuinely different live cross-database
 * query (brand + aliases), which is what keeps this on the legitimate
 * side of Google's scaled-content line. SEO decisions baked in from the
 * GlutenScreen audit:
 *   - answer-first <title> with a verdict tag and year
 *   - answer-first meta description
 *   - HONEST dates only: dateModified = the newest recall record found
 *     for this brand (a real fact), never the render date
 *   - unknown slugs render a noindexed not-found page
 */
require_once __DIR__ . '/recall_lib.php';
require_once __DIR__ . '/ads.php';

$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
$catalog = json_decode((string)@file_get_contents(__DIR__ . '/brands.json'), true);
$brand = null;
foreach (($catalog['brands'] ?? []) as $b) {
    if (($b['slug'] ?? '') === $slug) { $brand = $b; break; }
}

if (!$brand) {
    http_response_code(404);
    $t = 'Brand not found — ' . SITE_NAME;
    echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>" . e($t) . "</title><meta name=\"robots\" content=\"noindex, follow\"><style>body{font-family:system-ui,sans-serif;max-width:44rem;margin:4rem auto;padding:0 1.5rem;line-height:1.6}a{color:#c2410c}</style></head><body><h1>We don't have a page for that brand yet</h1><p>You can still check it instantly — the live search screens the same " . count(SOURCE_LABELS) . " official databases for any product or brand.</p><p><a href=\"/\">&larr; Search any product on " . e(SITE_NAME) . "</a></p></body></html>";
    exit;
}

$display  = $brand['display'];
$category = $brand['category'] ?? 'products';
$terms    = array_values(array_unique(array_merge([$display], $brand['aliases'] ?? [])));

/* Run the cross-database screen over the brand name + aliases, merged
 * and de-duplicated — CACHED per brand for 6 hours. Without the cache a
 * brand with two aliases fires 12 sequential live API calls per render
 * (multi-second pages: bad for visitors, crawl budget, and Core Web
 * Vitals alike). Recalls don't move faster than this, and the daily
 * ingest bounds staleness for the bulk sources anyway. */
$pcDir  = DATA_DIR . '/page_cache';
$pcFile = $pcDir . '/' . $slug . '.json';
$cached = null;
if (is_file($pcFile) && time() - filemtime($pcFile) < 21600) {
    $cached = json_decode((string)file_get_contents($pcFile), true);
}
if (is_array($cached) && isset($cached['bySource'], $cached['verdict'])) {
    $bySource = $cached['bySource']; $unreachable = $cached['unreachable'] ?? []; $verdict = $cached['verdict'];
} else {
    $bySource = []; $unreachable = [];
    foreach ($terms as $term) {
        $r = screen_query($term);
        foreach ($r['results'] as $key => $rows) {
            foreach ($rows as $row) {
                $dedup = $row['s'] . '|' . $row['id'] . '|' . mb_substr($row['t'], 0, 60);
                $bySource[$key][$dedup] = $row;
            }
            $bySource[$key] = $bySource[$key] ?? [];
        }
        $unreachable = array_values(array_unique(array_merge($unreachable, $r['unreachable'])));
    }
    foreach ($bySource as $key => $rows) {
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => strcmp($b['d'], $a['d']));
        $bySource[$key] = array_slice($rows, 0, 10);
    }
    // Only count a source unreachable if EVERY term failed to reach it
    // is overcautious the other way; union is the honest summary here.
    $verdict = compute_verdict($bySource, $unreachable);
    if (!is_dir($pcDir)) @mkdir($pcDir, 0775, true);
    @file_put_contents($pcFile . '.tmp', json_encode(['bySource'=>$bySource,'unreachable'=>$unreachable,'verdict'=>$verdict]));
    @rename($pcFile . '.tmp', $pcFile);
}

/* Related federal court cases (CourtListener) — informational only,
 * never part of the recall verdict. */
$legalTerms = array_values(array_unique(array_merge($terms, $brand['parties'] ?? [])));
$lawsuits   = search_courtlistener($legalTerms, $slug);

$totalHits = 0; $newest = '';
foreach ($bySource as $rows) foreach ($rows as $r) {
    $totalHits++;
    if (($r['d'] ?? '') > $newest) $newest = $r['d'];
}
$checked = count($bySource);

/* Honest freshness: the page's date is the newest record's date — a real
 * fact about the data. When NO records exist, fall back to the page
 * template's own mtime (stable), NOT the daily ingest date: a clean page's
 * content is identical day to day, and stamping it with a rolling date is
 * the fake-freshness pattern Google demotes. The visible copy can still
 * truthfully say the underlying databases refresh daily. */
$meta = load_meta();
$pageDate = $newest !== ''
    ? $newest
    : date('Y-m-d', max(@filemtime(__FILE__) ?: 0, @filemtime(__DIR__ . '/brands.json') ?: 0, 1));

$titleTags = [
    'recent'  => 'Recent Recall Found',
    'past'    => 'Past Recalls Only',
    'undated' => 'Records Found',
    'clean'   => 'No Recalls Found',
];
$tag         = $titleTags[$verdict['level']] ?? "Checked Across {$checked} Databases";
$title       = "Is {$display} Recalled? {$tag} (" . date('Y') . ") — " . SITE_NAME;
$description = $verdict['headline'] . ". We checked {$display} against {$checked} official recall databases across the US, Canada, and the UK — FDA, CPSC, USDA, Health Canada, and UK safety agencies. Free recall checker.";
$canonical   = SITE_ORIGIN . "/is-{$slug}-recalled/";
$faqAnswer   = $verdict['headline'] . '. ' . $verdict['detail'];

$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebPage', '@id' => $canonical . '#webpage',
            'url' => $canonical, 'name' => "Is {$display} recalled?",
            'description' => $description, 'inLanguage' => 'en-US',
            'dateModified' => $pageDate,
            'isPartOf' => ['@id' => SITE_ORIGIN . '/#website'],
        ],
        [
            '@type' => 'FAQPage', '@id' => $canonical . '#faq',
            'mainEntity' => [[
                '@type' => 'Question', 'name' => "Is {$display} recalled?",
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faqAnswer],
            ]],
        ],
        [
            '@type' => 'BreadcrumbList', '@id' => $canonical . '#crumbs',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => SITE_NAME, 'item' => SITE_ORIGIN . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => "Is {$display} recalled?"],
            ],
        ],
    ],
];

$toneClass = ['recent' => 'tone-recent', 'past' => 'tone-past', 'undated' => 'tone-undated', 'clean' => 'tone-clean'][$verdict['level']] ?? 'tone-undated';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-5W10Q5QPSE"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-5W10Q5QPSE');
</script>

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="<?= e(SITE_NAME) ?>">
<meta property="og:title" content="<?= e("Is {$display} recalled?") ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e(SITE_ORIGIN . '/og-image.png') ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= e(SITE_ORIGIN . '/og-image.png') ?>">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#D9480F">
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES) ?></script>
<style>
:root{
  --paper:#FCFBF8; --ink:#15171B; --muted:#5B6068; --line:#E2DFD7;
  --orange:#D9480F; --orange-soft:#FBEEE5; --green:#1E7F4F; --green-soft:#E9F5EE;
  --amber:#9A6700; --amber-soft:#FBF3E0; --steel:#2B4C7E;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Space Grotesk',system-ui,-apple-system,sans-serif;background:var(--paper);color:var(--ink);line-height:1.6;font-size:16px}
.stripe{height:6px;background:repeating-linear-gradient(-45deg,var(--ink) 0 12px,var(--orange) 12px 24px)}
main{max-width:46rem;margin:0 auto;padding:2rem 1.25rem 4rem}
.crumb{font-size:.8rem;color:var(--muted);margin-bottom:1.6rem}
.crumb a{color:var(--steel);text-decoration:none}
h1{font-size:clamp(1.7rem,5vw,2.4rem);line-height:1.15;letter-spacing:-.01em;margin-bottom:.4rem}
.meta-line{font-size:.82rem;color:var(--muted);margin-bottom:1.6rem}
.placard{border:3px solid var(--ink);padding:1.3rem 1.4rem;margin:0 0 2rem;background:#fff;position:relative}
.placard::before{content:"OFFICIAL RECORD CHECK";position:absolute;top:-.7rem;left:1rem;background:var(--paper);padding:0 .5rem;font-size:.66rem;letter-spacing:.14em;color:var(--muted)}
.placard .head{font-size:1.25rem;font-weight:700;margin-bottom:.4rem}
.placard p{font-size:.95rem;color:var(--ink)}
.tone-recent .head{color:var(--orange)} .tone-recent{background:var(--orange-soft);border-color:var(--orange)}
.tone-past .head{color:var(--amber)}   .tone-past{background:var(--amber-soft)}
.tone-undated .head{color:var(--steel)}
.tone-clean .head{color:var(--green)}  .tone-clean{background:var(--green-soft);border-color:var(--green)}
h2{font-size:1.05rem;margin:2rem 0 .7rem;letter-spacing:.02em}
.src{font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
ul.recs{list-style:none}
ul.recs li{border:1px solid var(--line);background:#fff;padding: .8rem .95rem;margin-bottom:.6rem}
ul.recs .t{font-weight:600;font-size:.93rem}
ul.recs .d{font-size:.78rem;color:var(--muted);margin-top:.15rem}
ul.recs .r{font-size:.86rem;margin-top:.3rem}
ul.recs a{color:var(--steel);font-size:.82rem}
.chip{display:inline-block;border:1px solid var(--ink);font-size:.66rem;letter-spacing:.08em;padding:.1rem .45rem;margin-left:.5rem;vertical-align:middle}
.none{font-size:.88rem;color:var(--muted);padding:.5rem 0}
.disclaimer{border-left:4px solid var(--orange);background:#fff;padding:.9rem 1rem;font-size:.85rem;color:var(--muted);margin-top:2.5rem}
.cta{display:inline-block;margin-top:1.5rem;background:var(--ink);color:#fff;padding:.65rem 1.1rem;text-decoration:none;font-weight:600;font-size:.9rem}
footer{border-top:1px solid var(--line);margin-top:3rem;padding-top:1rem;font-size:.78rem;color:var(--muted)}
footer a{color:var(--steel)}
</style>
</head>
<body>
<div class="stripe"></div>
<main>
  <nav class="crumb"><a href="/"><?= e(SITE_NAME) ?></a> › Is <?= e($display) ?> recalled?</nav>

  <h1>Is <?= e($display) ?> recalled?</h1>
  <p class="meta-line">
    <?= e(ucfirst($category)) ?> · checked against <?= (int)$checked ?> official databases (US · Canada · UK)
    <?php if ($newest): ?> · newest matching record: <?= e(date('F j, Y', strtotime($newest))) ?><?php endif; ?>
  </p>

  <div class="placard <?= e($toneClass) ?>">
    <div class="head"><?= e($verdict['headline']) ?></div>
    <p><?= e($verdict['detail']) ?></p>
  </div>

  <?= ad_leaderboard() ?>

  <?php $adShown = false; foreach ($bySource as $key => $rows): [$label] = SOURCE_LABELS[$key]; ?>
    <h2><?= e($label) ?> <span class="src"><?= e(SOURCE_LABELS[$key][1]) ?></span></h2>
    <?php if (!$rows): ?>
      <p class="none">No matching records.</p>
    <?php else: ?>
      <ul class="recs">
      <?php foreach ($rows as $r): ?>
        <li>
          <div class="t"><?= e($r['t'] ?: $r['p']) ?><?php if ($r['k']): ?><span class="chip"><?= e($r['k']) ?></span><?php endif; ?></div>
          <div class="d"><?= e($r['d'] ? date('M j, Y', strtotime($r['d'])) : 'date at source') ?><?= $r['o'] ? ' · ' . e($r['o']) : '' ?></div>
          <?php if ($r['r']): ?><div class="r"><?= e(mb_strimwidth($r['r'], 0, 220, '…')) ?></div><?php endif; ?>
          <a href="<?= e($r['u']) ?>" target="_blank" rel="noopener">View official record →</a>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!$adShown): $adShown = true; ?><?= ad_banner('6e48a72f1e878d2a23b46edb7e324e96', 300, 250) ?><?php endif; ?>
  <?php endforeach; ?>

  <?php if ($lawsuits && $lawsuits['count'] > 0): ?>
  <h2>Related federal court cases <span class="src">via CourtListener</span></h2>
  <p class="none" style="margin-bottom:.6rem"><?= (int)$lawsuits['count'] ?> product-liability or consumer-fraud
  docket<?= $lawsuits['count'] === 1 ? '' : 's' ?> in federal court name <?= e($display) ?> or a related entity —
  some are class actions, others individual suits. Newest first.</p>
  <ul class="recs">
  <?php foreach ($lawsuits['cases'] as $c): ?>
    <li>
      <div class="t"><?= e($c['name']) ?><?php if ($c['nos']): ?><span class="chip"><?= e($c['nos']) ?></span><?php endif; ?></div>
      <div class="d">Filed <?= e($c['filed'] ?: '—') ?><?= $c['closed'] ? ' · closed ' . e($c['closed']) : ' · open' ?><?= $c['court'] ? ' · ' . e($c['court']) : '' ?><?= $c['no'] ? ' · No. ' . e($c['no']) : '' ?></div>
      <a href="<?= e($c['url']) ?>" target="_blank" rel="noopener">View docket →</a>
    </li>
  <?php endforeach; ?>
  </ul>
  <p class="none">Court filings are allegations, not findings of fault or safety determinations.
  Cases are matched by party name and may involve unrelated products. This is public-record
  information, not legal advice.</p>
  <?php endif; ?>

  <a class="cta" href="/?q=<?= e(rawurlencode($display)) ?>">Run a live search for any product →</a>

  <?= ad_native() ?>

  <div class="disclaimer">
    <strong>Important.</strong> Recalls almost always cover specific lots, date codes, or
    model numbers — not entire brands. A match above does not mean every <?= e($display) ?>
    product is affected, and a clean result is not a safety guarantee. Always confirm your
    exact product against the official notice linked on each record.
  </div>

  <footer>
    Sources: openFDA enforcement reports, CPSC SaferProducts, USDA FSIS, NHTSA,
    Health Canada / CFIA / Transport Canada open data, UK OPSS, UK Food Standards Agency.
    Government data republished under each agency's open-data policy. <?= e(SITE_NAME) ?> is
    not affiliated with any government agency. <a href="/">Home</a>
  </footer>
</main>
<script>
(function(){try{var k='rc_refresh',d=new Date().toISOString().slice(0,10);
if(localStorage.getItem(k)!==d){localStorage.setItem(k,d);
navigator.sendBeacon?navigator.sendBeacon('/refresh'):fetch('/refresh',{method:'POST',keepalive:true});}}catch(e){}})();
</script>
</body>
</html>
