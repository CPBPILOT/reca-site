<?php
// index.php — table + line chart + CSV export
// Expects data.json (written by scrape.php) in the same directory.

date_default_timezone_set('America/Chicago');

$file = __DIR__ . '/data.json';
$data = [];
if (file_exists($file)) {
    $raw = file_get_contents($file);
    $data = json_decode($raw, true) ?: [];
}

// Ensure data sorted by date ascending (YYYY-MM-DD)
usort($data, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
// Use ascending for the chart, but reverse for the table (newest first)
$tableRows = array_reverse($data);
// Latest (for the “Latest:” label)
$latest = $data ? $data[count($data)-1] : null;


// CSV export if requested: index.php?csv=1
if (isset($_GET['csv'])) {
    // Prepare CSV in-memory
    $filename = 'reca_manhattan_project_waste.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    // Optional UTF-8 BOM for Excel friendliness:
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Pending', 'Approved', 'Denied', 'Total']);
    foreach ($data as $r) {
        fputcsv($out, [
            $r['date'] ?? '',
            (int)($r['pending']  ?? 0),
            (int)($r['approved'] ?? 0),
            (int)($r['denied']   ?? 0),
            (int)($r['total']    ?? 0),
        ]);
    }
    fclose($out);
    exit;
}

// Helper for table formatting
function n($v) { return number_format((int)$v); }

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>RECA — Manhattan Project Waste Application Data Tracking</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
  <style>
    :root {
      --bg: #0b0d10;
      --card: #111418;
      --ink: #e7eaee;
      --muted: #a9b0ba;
      --border: #1e232a;
      --green: #16a34a;   /* approved */
      --red: #dc2626;     /* denied */
      --magenta: #ff00ff; /* total */
      --blue: #2563eb;    /* pending */
    }
    @media (prefers-color-scheme: light) {
      :root { --bg:#f7f8fa; --card:#ffffff; --ink:#101319; --muted:#5b6573; --border:#e5e8ee; }
    }
    * { box-sizing: border-box; }
    body { margin: 0; font: 16px/1.5 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--bg); color: var(--ink); }
    .wrap { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
    header { margin-bottom: 1rem; }
    h1 { margin: 0 0 .25rem 0; font-size: 1.5rem; }
    .sub { color: var(--muted); margin: 0 0 1rem 0; }
    .grid { display: grid; grid-template-columns: 1.4fr .9fr; gap: 1rem; }
    @media (max-width: 1000px) { .grid { grid-template-columns: 1fr; } }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 1rem; box-shadow: 0 1px 10px rgba(0,0,0,.08); }
    canvas { display: block; width: 100%; height: 100%; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: right; padding: .5rem .6rem; border-bottom: 1px solid var(--border); white-space: nowrap; }
    th:first-child, td:first-child { text-align: left; }
    thead th { position: sticky; top: 0; background: var(--card); z-index: 1; }
    .legend { display:flex; gap:1rem; flex-wrap:wrap; margin:.5rem 0 0 0; }
    .dot { width: .8rem; height: .8rem; border-radius: 50%; display: inline-block; margin-right:.4rem; vertical-align: middle; }
    .muted { color: var(--muted); }
    .controls { display:flex; gap:.75rem; align-items:center; margin-bottom:.5rem; }
    .controls input[type="checkbox"] { transform: translateY(1px); }
    .tight { margin: 0; }
    .note { font-size: .9rem; color: var(--muted); }
    .btn { display:inline-block; padding:.45rem .75rem; border:1px solid var(--border); border-radius:8px; text-decoration:none; color:var(--ink); }
    .btn:hover { background: rgba(255,255,255,.06); }
    .rowcount { font-size:.95rem; }
    .actions { display:flex; gap:.5rem; align-items:center; }
    .chart-wrap { position: relative; height: 640px; }
    .date-link { color: inherit; text-decoration: underline; }
    .date-link:hover { text-decoration: underline; }
    .date-link .ext { font-size: .85em; color: var(--muted); margin-left: .25rem; }
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <h1 class="tight">RECA — Manhattan Project Waste Application Data Tracking</h1>
  </header>

  <div class="grid">
    <section class="card">
      <div class="controls">
        <label><input type="checkbox" class="series-toggle" data-key="approved" checked> Approved</label>
        <label><input type="checkbox" class="series-toggle" data-key="denied" checked> Denied</label>
        <label><input type="checkbox" class="series-toggle" data-key="total" checked> Total</label>
        <label><input type="checkbox" class="series-toggle" data-key="pending" checked> Pending</label>
        <span class="note">Tip: hover the chart or tap a legend item to focus.</span>
      </div>
      <div class="chart-wrap">
        <canvas id="lineChart"></canvas>
      </div>
    </section>

    <section class="card">
      <div style="display:flex;justify-content:space-between;align-items:baseline;">
        <h2 class="tight">Daily Table</h2>
        <div class="actions">
          <a class="btn" href="data.json" download>Download JSON</a>
          <a class="btn" href="?csv=1">Download CSV</a>
        </div>
      </div>
      <div class="rowcount muted" style="margin-top:.4rem;">
        <?php echo count($data); ?> rows
        <?php if (!empty($data)) { echo ' • Latest: '.htmlspecialchars($latest['date']); } ?>
      </div>
      <div style="max-height: 480px; overflow: auto; margin-top:.5rem;">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Pending</th>
              <th>Approved</th>
              <th>Denied</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($data)): ?>
              <tr><td colspan="5" class="muted">No data yet. Run your cron or visit <a class="btn" href="scrape.php">scrape.php</a> to generate today’s record.</td></tr>
            <?php else: ?>
              <?php foreach ($tableRows as $r): ?>
                <tr>
                  <td>
                    <?php if (!empty($r['source'])): ?>
                      <a class="date-link"
                         href="<?= htmlspecialchars($r['source']) ?>"
                         target="_blank" rel="noopener noreferrer"
                         title="Open DOJ source for <?= htmlspecialchars($r['date']) ?>">
                        <?= htmlspecialchars($r['date']) ?><span class="ext">↗</span>
                      </a>
                    <?php else: ?>
                      <?= htmlspecialchars($r['date']) ?>
                    <?php endif; ?>
                  </td>
                  <td><?php echo n($r['pending'] ?? 0); ?></td>
                  <td><?php echo n($r['approved'] ?? 0); ?></td>
                  <td><?php echo n($r['denied'] ?? 0); ?></td>
                  <td><?php echo n($r['total'] ?? 0); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <p class="note">Table shows one row per weekday. Weekends typically have no updates but are still checked. So far the DOJ only updates the numbers once per day (around 9am cst) but they are rechecked every hour for any changes. Also for transparancy each date is linked to the DOJ site where the data was pulled from. </p>
    </section>
  </div>
  <footer style="margin-top:1rem;">
    <?php
      $vis   = @json_decode(@file_get_contents(__DIR__.'/visits.json'), true) ?: [];
      $today = date('Y-m-d');
      $pv    = $vis['days'][$today]['pageviews'] ?? 0;
      $uv    = $vis['days'][$today]['uniques']   ?? 0;
      $tpv   = $vis['totals']['pageviews'] ?? 0;
      $tuv   = $vis['totals']['uniques']  ?? 0;
    ?>
    <p class="note">
      Today: <?= number_format($pv) ?> views
      • All-time: <?= number_format($tpv) ?> views
    </p>
  </footer>
</div>

<script>
(() => {
  // Inject PHP data directly to avoid CORS/fetch
  const data = <?php echo json_encode($data, JSON_UNESCAPED_SLASHES); ?> || [];

  const labels  = data.map(d => d.date);
  const pending = data.map(d => d.pending ?? 0);
  const approved= data.map(d => d.approved ?? 0);
  const denied  = data.map(d => d.denied ?? 0);
  const total   = data.map(d => d.total ?? 0);

  const ctx = document.getElementById('lineChart').getContext('2d');
  const chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Approved', data: approved, borderColor: '#16a34a', backgroundColor: '#16a34a', tension: 0.1, fill: false, pointRadius: 2, yAxisID: 'y', key:'approved' },
        { label: 'Denied',   data: denied,   borderColor: '#dc2626', backgroundColor: '#dc2626', tension: 0.1, fill: false, pointRadius: 2, yAxisID: 'y', key:'denied' },
        { label: 'Total',    data: total,    borderColor: '#ff00ff', backgroundColor: '#ff00ff', tension: 0.1, fill: false, pointRadius: 2, yAxisID: 'y', key:'total' },
        { label: 'Pending',  data: pending,  borderColor: '#2563eb', backgroundColor: '#2563eb', tension: 0.1, fill: false, pointRadius: 2, yAxisID: 'y', key:'pending' },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      resizeDelay: 200,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: true, position: 'top' },
        tooltip: {
          callbacks: {
            title: items => `Date: ${items[0].label}`,
            label: c => `${c.dataset.label}: ${Number(c.parsed.y ?? 0).toLocaleString()}`
          }
        }
      },
      scales: {
        x: { ticks: { autoSkip: true, maxTicksLimit: 12 } },
        y: {
          beginAtZero: true,
          title: { display: true, text: 'Count' },
          ticks: { callback: (v) => Number(v).toLocaleString() }
        }
      }
    }
  });

  // Checkbox toggles
  document.querySelectorAll('.series-toggle').forEach(cb => {
    cb.addEventListener('change', () => {
      const ds = chart.data.datasets.find(d => d.key === cb.dataset.key);
      if (ds) { ds.hidden = !cb.checked; chart.update(); }
    });
  });
})();
</script>

<script>
(function () {
  try {
    if (navigator.sendBeacon) {
      navigator.sendBeacon('/track.php');
    } else {
      fetch('/track.php', { method: 'POST', keepalive: true, cache: 'no-store', credentials: 'same-origin' });
    }
  } catch (e) { /* ignore */ }
})();
</script>

</body>
</html>
