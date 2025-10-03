<?php
// scrape.php — DOJ RECA "Manhattan Project Waste" scraper with upsert + archive
// Runs under PHP-FPM in Docker. Writes/updates data.json (and optionally data_changes.json).

/* ---------- runtime hygiene ---------- */
date_default_timezone_set("America/Chicago");
ini_set('display_errors', '0');                 // don't print warnings to output
ini_set('log_errors', '1');                     // log to file instead
ini_set('error_log', __DIR__ . '/php_errors.log');

// Always return JSON
header('Content-Type: application/json');

/* ---------- config / helpers ---------- */

// Upsert mode: 'skip' | 'replace' | 'archive' (override with ?mode=...)
$UPSERT_MODE = isset($_GET['mode']) ? strtolower($_GET['mode']) : 'replace';

function write_json_atomic(string $path, array $data): bool {
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if ($json === false) return false;
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $path);
}

function cleanint($s): int {
    // keep leading '-' if any, drop everything non-digit
    $s = (string)$s;
    $neg = str_starts_with(trim($s), '-') ? -1 : 1;
    $n = preg_replace('/[^\d]/', '', $s);
    return $n === '' ? 0 : $neg * (int)$n;
}

function fetchPage(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => "Mozilla/5.0 (compatible; RECA-PHP-Scraper)",
        CURLOPT_TIMEOUT => 20
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $html) ? $html : null;
}

/* ---------- resolve date & URL ---------- */

// Optional override: ?date=09232025 or ?date=2025-09-23
$dt = new DateTime();
if (isset($_GET['date'])) {
    $q = $_GET['date'];
    if (preg_match('/^\d{8}$/', $q))          $dt = DateTime::createFromFormat('mdY', $q) ?: new DateTime();
    elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) $dt = new DateTime($q);
}
$url = "https://www.justice.gov/civil/awards-date-" . $dt->format("mdY");

/* ---------- read existing data ---------- */
$dataFile = __DIR__ . "/data.json";
$all = file_exists($dataFile) ? (json_decode(file_get_contents($dataFile), true) ?: []) : [];

/* ---------- fetch & parse ---------- */
$html = fetchPage($url);
if (!$html) {
    // No update today (weekend/missing) — just return existing dataset
    echo json_encode($all, JSON_PRETTY_PRINT);
    exit;
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();
$xp = new DOMXPath($dom);

// Find the Manhattan row
$row = $xp->query("//tr[td[contains(normalize-space(.), 'Manhattan Project Waste')]]")->item(0);
if (!$row) {
    // Structure changed or not published — return existing
    echo json_encode($all, JSON_PRETTY_PRINT);
    exit;
}

// Find the table that contains this row; map header cells to indexes
$table = $row;
while ($table && strtolower($table->nodeName) !== 'table') $table = $table->parentNode;

$map = []; // header text -> index
if ($table) {
    $ths = $xp->query(".//thead//th", $table);
    foreach ($ths as $i => $th) {
        $label = strtolower(trim(preg_replace('/\s+/', ' ', $th->textContent)));
        // normalize header tokens to simple keys
        $label = preg_replace('/[^a-z%]/', '', $label);
        $map[$label] = $i;
    }
}

// Expected DOJ layout (fallbacks if header map missing):
// Category | Pending | Approved | % | $ Approved | Denied | Total
$idxPending  = $map['pending']  ?? 1;
$idxApproved = $map['approved'] ?? 2;
$idxDenied   = $map['denied']   ?? 5;
// sometimes header shows as "Total Claims"
$idxTotal    = $map['total']    ?? ($map['totalclaims'] ?? 6);

$cells = $row->getElementsByTagName("td");
$pending  = isset($cells[$idxPending])  ? cleanint($cells[$idxPending]->textContent)  : 0;
$approved = isset($cells[$idxApproved]) ? cleanint($cells[$idxApproved]->textContent) : 0;
$denied   = isset($cells[$idxDenied])   ? cleanint($cells[$idxDenied]->textContent)   : 0;
$total    = isset($cells[$idxTotal])    ? cleanint($cells[$idxTotal]->textContent)    : 0;

// If total looks wrong, try strict fallback by position
if (($total === 0) && ($cells->length >= 7)) {
    $pending  = cleanint($cells->item(1)->textContent ?? '0'); // Pending
    $approved = cleanint($cells->item(2)->textContent ?? '0'); // Approved
    $denied   = cleanint($cells->item(5)->textContent ?? '0'); // Denied
    $total    = cleanint($cells->item(6)->textContent ?? '0'); // Total
}

// Build today's record
$record = [
    "date"        => $dt->format("Y-m-d"),
    "pending"     => $pending,
    "approved"    => $approved,
    "denied"      => $denied,
    "total"       => $total,
    "last_updated"=> date('c'),
    "source"      => $url
];

// Guard: don't write clearly bad rows (all zeros)
if ($record['pending'] === 0 && $record['approved'] === 0 && $record['denied'] === 0 && $record['total'] === 0) {
    echo json_encode($all, JSON_PRETTY_PRINT);
    exit;
}

/* ---------- upsert (skip | replace | archive) ---------- */

// Index existing by date
$byDate = [];
foreach ($all as $row) {
    if (!isset($row['date'])) continue;
    $byDate[$row['date']] = $row;
}

$todayKey = $record['date'];
$differs = function ($a, $b): bool {
    foreach (['pending','approved','denied','total'] as $k) {
        if ((int)($a[$k] ?? -1) !== (int)($b[$k] ?? -1)) return true;
    }
    return false;
};

$did = 'noop';
if (!isset($byDate[$todayKey])) {
    $byDate[$todayKey] = $record;
    $did = 'insert';
} else {
    if ($differs($byDate[$todayKey], $record)) {
        if ($UPSERT_MODE === 'replace' || $UPSERT_MODE === 'archive') {
            $old = $byDate[$todayKey];
            $byDate[$todayKey] = $record;
            $did = 'update';

            if ($UPSERT_MODE === 'archive') {
                $chgFile = __DIR__ . '/data_changes.json';
                $changes = file_exists($chgFile) ? (json_decode(file_get_contents($chgFile), true) ?: []) : [];
                $changes[] = [
                    'ts'   => date('c'),
                    'date' => $todayKey,
                    'old'  => $old,
                    'new'  => $record,
                ];
                write_json_atomic($chgFile, $changes);
            }
        }
        // if mode == 'skip', keep first seen values (do nothing)
    }
}

// Rebuild array, sort by date, write
$all = array_values($byDate);
usort($all, fn($a,$b) => strcmp($a['date'], $b['date']));

if (!write_json_atomic($dataFile, $all)) {
    // If not writable, return existing without failing hard
    echo json_encode($all, JSON_PRETTY_PRINT);
    exit;
}

/* ---------- done ---------- */
echo json_encode($all, JSON_PRETTY_PRINT);
