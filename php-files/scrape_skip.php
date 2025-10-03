<?php
// scrape.php (robust header-mapped version)
date_default_timezone_set("America/Chicago");

// Optional override: ?date=09232025 or ?date=2025-09-23
$today = new DateTime();
if (isset($_GET['date'])) {
    $q = $_GET['date'];
    if (preg_match('/^\d{8}$/', $q))      $today = DateTime::createFromFormat('mdY', $q);
    elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) $today = new DateTime($q);
}
$url = "https://www.justice.gov/civil/awards-date-" . $today->format("mdY");

function fetchPage($url) {
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
function cleanint($s) {
    $n = preg_replace('/[^\d\-]/', '', (string)$s);
    return $n === '' ? 0 : (int)$n;
}

$html = fetchPage($url);
if (!$html) { http_response_code(500); echo json_encode(["error"=>"Failed to fetch", "url"=>$url]); exit; }

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();
$xp = new DOMXPath($dom);

// Find the Manhattan row
$row = $xp->query("//tr[td[contains(normalize-space(.), 'Manhattan Project Waste')]]")->item(0);
if (!$row) { http_response_code(404); echo json_encode(["error"=>"Row not found"]); exit; }

// Find the table and header cells for mapping
$table = $row;
while ($table && strtolower($table->nodeName) !== 'table') $table = $table->parentNode;

$map = []; // header text -> index
if ($table) {
    $ths = $xp->query(".//thead//th", $table);
    foreach ($ths as $i => $th) {
        $label = strtolower(trim(preg_replace('/\s+/', ' ', $th->textContent)));
        $label = preg_replace('/[^a-z%]/', '', $label); // normalize
        $map[$label] = $i;
    }
}

// Expected header keys
$idxPending  = $map['pending']  ?? 1;
$idxApproved = $map['approved'] ?? 2;
$idxDenied   = $map['denied']   ?? 5;
$idxTotal    = ($map['total'] ?? $map['totalclaims'] ?? 6);

$cells = $row->getElementsByTagName("td");
$pending  = cleanint($cells->item($idxPending)->textContent  ?? '0');
$approved = cleanint($cells->item($idxApproved)->textContent ?? '0');
$denied   = cleanint($cells->item($idxDenied)->textContent   ?? '0');
$total    = cleanint($cells->item($idxTotal)->textContent    ?? '0');

// Fallback to standard DOJ layout if total still looks wrong
if ($total === 0 && ($cells->length >= 7)) {
    $pending  = cleanint($cells->item(1)->textContent ?? '0'); // Pending
    $approved = cleanint($cells->item(2)->textContent ?? '0'); // Approved
    $denied   = cleanint($cells->item(5)->textContent ?? '0'); // Denied
    $total    = cleanint($cells->item(6)->textContent ?? '0'); // Total
}

$record = [
    "date"     => $today->format("Y-m-d"),
    "pending"  => $pending,
    "approved" => $approved,
    "denied"   => $denied,
    "total"    => $total
];

// Load & append
$file = __DIR__ . "/data.json";
$all = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

// Avoid adding clearly bad rows (all zeros)
if (!($record['total'] === 0 && $record['pending'] === 0 && $record['approved'] === 0 && $record['denied'] === 0)) {
    $exists = false;
    foreach ($all as $r) { if (($r["date"] ?? '') === $record["date"]) { $exists = true; break; } }
    if (!$exists) {
        $all[] = $record;
        usort($all, fn($a,$b) => strcmp($a['date'], $b['date']));
        file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT));
    }
}

// Serve full history
header("Content-Type: application/json");
echo json_encode($all, JSON_PRETTY_PRINT);
