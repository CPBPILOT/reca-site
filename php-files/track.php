<?php
// track.php — minimal visit counter (pageviews + uniques per day + total uniques)
// Counts only real page loads (ignores bots/prefetch). Returns 204 No Content.

date_default_timezone_set('America/Chicago');
header('Cache-Control: no-store');
header('Content-Type: text/plain');

// 1) Skip obvious bots/prefetch
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua === '' ||
    preg_match('~bot|crawl|spider|scanner|curl|wget|checkmk|uptime|monitor|lighthouse|render~i', $ua) ||
    (isset($_SERVER['HTTP_SEC_PURPOSE']) && stripos($_SERVER['HTTP_SEC_PURPOSE'], 'prefetch') !== false)
) {
    http_response_code(204);
    exit;
}

// 2) Files
$file = __DIR__ . '/visits.json';
$tmp  = $file . '.tmp';
$today = date('Y-m-d');

// 3) Load current data
$all = [];
if (is_file($file)) {
    $json = file_get_contents($file);
    $all = json_decode($json, true) ?: [];
}
$all += [
    'totals' => ['pageviews' => 0, 'uniques' => 0],
    'days'   => []
];
$all['days'][$today] = $all['days'][$today] ?? ['pageviews' => 0, 'uniques' => 0];

// 4) Uniques via cookies
$uidCookie  = 'reca_uid';        // long-lived visitor id
$seenCookie = 'reca_seen_day';   // last date we counted a "daily unique" for this browser

$isNewVisitor = empty($_COOKIE[$uidCookie]);
$isNewToday   = empty($_COOKIE[$seenCookie]) || $_COOKIE[$seenCookie] !== $today;

// Generate/set cookies (httpOnly not required, no sensitive data)
if ($isNewVisitor) {
    $uid = bin2hex(random_bytes(16));
    setcookie($uidCookie, $uid, [
        'expires'  => time() + 3600*24*365,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'samesite' => 'Lax',
    ]);
    $all['totals']['uniques']++;
}
if ($isNewToday) {
    setcookie($seenCookie, $today, [
        'expires'  => time() + 3600*24*7, // only need this week
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'samesite' => 'Lax',
    ]);
    $all['days'][$today]['uniques']++;
}

// 5) Always increment pageviews
$all['totals']['pageviews']++;
$all['days'][$today]['pageviews']++;

// 6) Atomic write
$json = json_encode($all, JSON_PRETTY_PRINT);
if ($json !== false && file_put_contents($tmp, $json, LOCK_EX) !== false) {
    @rename($tmp, $file);
}

// 7) Done
http_response_code(204); // no body
