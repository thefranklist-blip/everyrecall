<?php
/**
 * sitemap.php — served as /sitemap.xml.
 * Honest lastmod only: the homepage carries index.html's mtime; brand
 * pages carry the last ingest date (the day the underlying data was
 * actually replaced) — never a render-time date.
 */
require_once __DIR__ . '/recall_lib.php';
header('Content-Type: application/xml; charset=utf-8');

$homeDate  = gmdate('Y-m-d', max(@filemtime(__DIR__ . '/index.html') ?: 0, 1));
// Stable lastmod for brand pages: template/catalog mtimes only. The daily
// ingest date would roll every day regardless of whether a given brand's
// content changed — the fake-freshness pattern. New recalls surface via
// the brand page content itself and the 'weekly' changefreq hint.
$brandDate = gmdate('Y-m-d', max(@filemtime(__DIR__ . '/brand.php') ?: 0,
                                 @filemtime(__DIR__ . '/brands.json') ?: 0, 1));

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$urls = [[SITE_ORIGIN . '/', $homeDate, '1.0', 'weekly'],
         [SITE_ORIGIN . '/about.html', gmdate('Y-m-d', max(@filemtime(__DIR__ . '/about.html') ?: 0, 1)), '0.7', 'monthly']];
$catalog = json_decode((string)@file_get_contents(__DIR__ . '/brands.json'), true);
$brands = $catalog['brands'] ?? [];
usort($brands, fn($a, $b) => strcmp($a['slug'] ?? '', $b['slug'] ?? ''));
foreach ($brands as $b) {
    $slug = $b['slug'] ?? '';
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) continue;
    $urls[] = [SITE_ORIGIN . "/is-{$slug}-recalled/", $brandDate, '0.8', 'weekly'];
}
foreach ($urls as [$loc, $lastmod, $priority, $freq]) {
    echo "  <url>\n    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n"
       . "    <lastmod>{$lastmod}</lastmod>\n"
       . "    <changefreq>{$freq}</changefreq>\n"
       . "    <priority>{$priority}</priority>\n  </url>\n";
}
echo "</urlset>\n";
