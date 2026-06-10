<?php
/**
 * ads.php — Adsterra slot helpers. Each banner runs inside its own
 * <iframe srcdoc> sandbox so the global `atOptions` from one unit can't
 * be overwritten by the next unit's invoke.js (Adsterra reuses one
 * global, so multiple inline units on a page race and only the last
 * renders). Isolation also keeps third-party ad JS from touching the
 * page DOM, analytics, or the recall data.
 */
function ad_banner(string $key, int $w, int $h, string $cls = ''): string {
    $inner = '<!DOCTYPE html><html><head><meta charset="utf-8">'
           . '<style>html,body{margin:0;padding:0;overflow:hidden;background:transparent}</style></head><body>'
           . '<script>atOptions={"key":"' . $key . '","format":"iframe","height":' . $h . ',"width":' . $w . ',"params":{}};</script>'
           . '<script src="https://www.highperformanceformat.com/' . $key . '/invoke.js"></script>'
           . '</body></html>';
    $srcdoc = htmlspecialchars($inner, ENT_QUOTES);
    return '<div class="ad-rail ' . $cls . '"><div><span class="ad-label">Advertisement</span>'
         . '<iframe srcdoc="' . $srcdoc . '" width="' . $w . '" height="' . $h . '" '
         . 'style="border:0;display:block" scrolling="no" loading="lazy" '
         . 'sandbox="allow-scripts allow-same-origin allow-popups" title="Advertisement"></iframe></div></div>';
}

/** Native banner (effectivecpmnetwork) — one per page max. */
function ad_native(): string {
    return '<div class="ad-native"><span class="ad-label">Advertisement</span>'
         . '<script async data-cfasync="false" src="https://pl29706176.effectivecpmnetwork.com/824d54070d44efa2fb344b93a9d8a0d7/invoke.js"></script>'
         . '<div id="container-824d54070d44efa2fb344b93a9d8a0d7"></div></div>';
}

/** Responsive leaderboard: 728x90 on desktop, 320x50 on mobile. */
function ad_leaderboard(): string {
    return ad_banner('abda80b59d1702489e3c2fa2a4bca9ea', 728, 90, 'ad-desktop-only')
         . ad_banner('6f1c8a8210048f9dcffe568ab49730b0', 320, 50, 'ad-mobile-only');
}
