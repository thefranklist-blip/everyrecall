<?php
/**
 * api.php — JSON search endpoint.
 *   GET /api?q=tylenol                      cross-database product/brand search
 *   GET /api?make=toyota&model=camry&year=2022   NHTSA vehicle check
 *   GET /api?status=1                       ingest freshness report
 */
require_once __DIR__ . '/recall_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

if (isset($_GET['status'])) {
    $m = load_meta();
    $m['active_sources'] = active_sources();
    echo json_encode($m);
    exit;
}

if (isset($_GET['make'], $_GET['model'], $_GET['year'])) {
    $make = substr(trim((string)$_GET['make']), 0, 40);
    $model = substr(trim((string)$_GET['model']), 0, 40);
    $year = preg_replace('/\D/', '', (string)$_GET['year']);
    if ($make === '' || $model === '' || strlen($year) !== 4) {
        http_response_code(400);
        echo json_encode(['error' => 'make, model, and a 4-digit year are required']);
        exit;
    }
    $rows = search_nhtsa($make, $model, $year);
    if ($rows === null) { http_response_code(502); echo json_encode(['error' => 'NHTSA unreachable, try again']); exit; }
    $verdict = $rows
        ? ['level' => 'recent', 'headline' => count($rows) . ' open recall' . (count($rows) === 1 ? '' : 's') . ' on file',
           'detail' => 'NHTSA lists these campaigns for this vehicle. Any franchised dealer must perform recall repairs free of charge. For lot-level certainty, run your 17-character VIN at nhtsa.gov/recalls.']
        : ['level' => 'clean', 'headline' => 'No open recalls on file',
           'detail' => 'NHTSA shows no recall campaigns for this make, model, and year. For certainty about your specific vehicle, run your VIN at nhtsa.gov/recalls.'];
    echo json_encode(['vehicle' => "{$year} {$make} {$model}", 'verdict' => $verdict,
                      'results' => ['NHTSA' => $rows], 'unreachable' => []]);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2 || mb_strlen($q) > 80) {
    http_response_code(400);
    echo json_encode(['error' => 'q must be 2–80 characters']);
    exit;
}

$res = screen_query($q);
echo json_encode([
    'query'       => $q,
    'verdict'     => $res['verdict'],
    'results'     => $res['results'],
    'unreachable' => $res['unreachable'],
    'labels'      => SOURCE_LABELS,
]);
