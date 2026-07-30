<?php
/**
 * ADMIN dashboard — दो तरीक़ों से खुलता है:
 *   1. गुप्त छोटा path — /<admin_path>   (config.php का admin_path, जैसे /skaler2015)
 *   2. /admin?k=<run_token>              (लंबा token वाला पुराना तरीक़ा — अब भी चलता है)
 * सिर्फ़ पढ़ता है (read-only), noindex। 100% असली डेटा।
 * sync/health पर नज़र रखने के लिए — status.php का premium web रूप।
 */
declare(strict_types=1);

// ---- auth gate — किसी भी हाल में असफल → सादा 404 (मौजूदगी तक न बताए) ----
// $ADMIN_AUTHED router ने तब सच किया जब गुप्त admin_path से आया (path खुद चाबी है)।
$authed = !empty($GLOBALS['ADMIN_AUTHED']);
if (!$authed) {
    $tok = (string) ($CFG['run_token'] ?? '');
    if ($tok === '' || $tok === 'बदल-कर-कुछ-लंबा-लिखिए'
        || !isset($_GET['k']) || !hash_equals($tok, (string) $_GET['k'])) {
        not_found();
    }
}
header('X-Robots-Tag: noindex, nofollow');

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$nf = fn (int $n) => number_format($n);

// ---- overview ----------------------------------------------------------------
$c = [
    'titles'   => (int) scalar($PDO, 'SELECT COUNT(*) FROM titles'),
    'movies'   => (int) scalar($PDO, "SELECT COUNT(*) FROM titles WHERE media_type='movie'"),
    'series'   => (int) scalar($PDO, "SELECT COUNT(*) FROM titles WHERE media_type='tv'"),
    'prov'     => (int) scalar($PDO, 'SELECT COUNT(*) FROM providers WHERE is_active = 1'),
    'live'     => (int) scalar($PDO, 'SELECT COUNT(*) FROM availability WHERE is_current = 1'),
    'past'     => (int) scalar($PDO, 'SELECT COUNT(*) FROM availability WHERE is_current = 0'),
    'changes'  => (int) scalar($PDO, 'SELECT COUNT(*) FROM availability_changes'),
    'never'    => (int) scalar($PDO, 'SELECT COUNT(*) FROM titles WHERE providers_last_success IS NULL'),
    'failing'  => (int) scalar($PDO, 'SELECT COUNT(*) FROM titles WHERE providers_fail_streak >= 3'),
];

// DB size (MB)
$dbmb = (float) (scalar($PDO, 'SELECT ROUND(SUM(data_length+index_length)/1048576,1)
                                 FROM information_schema.tables WHERE table_schema = DATABASE()') ?? 0);

// आज
$today = one($PDO, "SELECT
    COALESCE(SUM(change_type='added'),0)   AS a,
    COALESCE(SUM(change_type='removed'),0) AS r
  FROM availability_changes WHERE changed_on = CURDATE()") ?? ['a' => 0, 'r' => 0];
$todayRuns = (int) scalar($PDO, 'SELECT COUNT(*) FROM sync_runs WHERE DATE(started_at) = CURDATE()');

// coverage
$pcov = one($PDO, "SELECT COUNT(*) tot, COALESCE(SUM(poster_path IS NOT NULL AND poster_path<>''),0) wp FROM titles")
      ?? ['tot' => 0, 'wp' => 0];
$langTitles = (int) scalar($PDO, 'SELECT COUNT(DISTINCT title_id) FROM title_languages');
$posterPct = (int) $pcov['tot'] > 0 ? round((int) $pcov['wp'] / (int) $pcov['tot'] * 100) : 0;
$langPct   = $c['titles'] > 0 ? round($langTitles / $c['titles'] * 100) : 0;

// last run per job + recent runs
$lastCat  = one($PDO, "SELECT * FROM sync_runs WHERE job='catalog'   ORDER BY id DESC LIMIT 1");
$lastProv = one($PDO, "SELECT * FROM sync_runs WHERE job='providers' ORDER BY id DESC LIMIT 1");
$runs = all($PDO, 'SELECT * FROM sync_runs ORDER BY id DESC LIMIT 12');
$halted = array_values(array_filter($runs, fn ($r) => $r['status'] === 'halted'));

// health पिल — providers की आख़िरी दौड़ से
$health = ['warn', 'कोई providers दौड़ नहीं'];
if ($halted !== []) {
    $health = ['bad', 'सुरक्षा ब्रेक (halted)'];
} elseif ($lastProv !== null) {
    $ageH = $lastProv['finished_at'] ? (time() - strtotime($lastProv['finished_at'])) / 3600 : 999;
    if ($lastProv['status'] === 'done' && $ageH <= 30)      $health = ['ok',   'सेहत ठीक'];
    elseif ($lastProv['status'] === 'done' && $ageH > 30)   $health = ['warn', 'cron देर से चला'];
    elseif ($lastProv['status'] === 'failed')               $health = ['bad',  'आख़िरी दौड़ विफल'];
    else                                                    $health = ['warn', $lastProv['status']];
}

// growth — पिछले 14 दिन के बदलाव
$growth = [];
foreach (all($PDO, "SELECT changed_on d,
    COALESCE(SUM(change_type='added'),0) a, COALESCE(SUM(change_type='removed'),0) r
  FROM availability_changes WHERE changed_on >= (CURDATE() - INTERVAL 13 DAY)
  GROUP BY changed_on") as $g) {
    $growth[$g['d']] = ['a' => (int) $g['a'], 'r' => (int) $g['r']];
}

// tables by size
$tables = all($PDO, 'SELECT table_name tn, table_rows tr, ROUND((data_length+index_length)/1048576,2) mb
    FROM information_schema.tables WHERE table_schema = DATABASE()
    ORDER BY (data_length+index_length) DESC');

// top providers + languages
$topProv = all($PDO, "SELECT p.name, COUNT(DISTINCT a.title_id) c
    FROM availability a JOIN providers p ON p.id = a.provider_id
   WHERE a.is_current = 1 AND a.offer_type IN ('flatrate','ads','free')
   GROUP BY p.id ORDER BY c DESC LIMIT 8");
$langs = all($PDO, "SELECT l.lang_code, COUNT(DISTINCT t.id) c
    FROM availability a JOIN titles t ON t.id=a.title_id JOIN title_languages l ON l.title_id=t.id
   WHERE a.is_current=1 AND a.offer_type IN ('flatrate','ads','free')
   GROUP BY l.lang_code ORDER BY c DESC LIMIT 8");

$recent = all($PDO, "SELECT c.change_type, c.changed_on, t.title, p.name prov, c.offer_type
    FROM availability_changes c JOIN titles t ON t.id=c.title_id JOIN providers p ON p.id=c.provider_id
   ORDER BY c.id DESC LIMIT 12");

$unknown = state_get($PDO, 'unknown_providers', []);
$cursor  = state_get($PDO, 'catalog_cursor', []);
$now     = date('d M Y, H:i');

$runcell = function (?array $r) use ($e): string {
    if ($r === null) return '<span class="dim">कभी नहीं</span>';
    $when = $e(substr((string) $r['started_at'], 5, 11));
    return '<span class="stt ' . $e($r['status']) . '">' . $e($r['status']) . '</span> · '
         . '<span class="dim">' . $when . ' · +' . (int) $r['changes_added'] . ' −' . (int) $r['changes_removed'] . '</span>';
};

// ---- premium dark shell (public nav/footer नहीं) -----------------------------
?><!doctype html>
<html lang="<?= OTT_LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>OTTGuru · Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css">
</head>
<body>

<div class="abar"><div class="wrap abar-in">
  <a class="logo" href="/">OTT<span>Guru</span></a>
  <span class="tag">Admin</span>
  <span class="hpill <?= $health[0] ?>"><span class="d"></span><?= $e($health[1]) ?></span>
  <span class="when"><?= $e($now) ?></span>
</div></div>

<main class="wrap" style="padding-top:22px">

<?php if ($halted !== []): ?>
<div class="alarm"><b>सुरक्षा ब्रेक लगा था।</b> <?= $e($halted[0]['note'] ?? '') ?><br>
  <span class="dim small">वजह ठीक होने तक कोई बदलाव दर्ज नहीं होगा — इतिहास सुरक्षित है।</span></div>
<?php endif; ?>

<div class="acards">
  <div class="acard"><div class="v"><?= $nf($c['titles']) ?></div><div class="k">titles (<?= $nf($c['movies']) ?> फिल्में · <?= $nf($c['series']) ?> सीरीज़)</div></div>
  <div class="acard"><div class="v"><?= $nf($c['prov']) ?></div><div class="k">active platforms</div></div>
  <div class="acard ok"><div class="v"><?= $nf($c['live']) ?></div><div class="k">अभी उपलब्ध</div></div>
  <div class="acard"><div class="v"><?= $nf($c['past']) ?></div><div class="k">बीत चुके spells</div></div>
  <div class="acard ok"><div class="v"><?= $nf($c['changes']) ?></div><div class="k">इतिहास records</div></div>
  <div class="acard"><div class="v"><?= number_format($dbmb, 1) ?> <span style="font-size:14px">MB</span></div><div class="k">DB size</div></div>
</div>

<div class="acards" style="grid-template-columns:repeat(3,1fr)">
  <div class="acard ok"><div class="v">+<?= $nf((int) $today['a']) ?></div><div class="k">आज जुड़े</div></div>
  <div class="acard <?= (int) $today['r'] > 0 ? 'bad' : '' ?>"><div class="v">−<?= $nf((int) $today['r']) ?></div><div class="k">आज हटे</div></div>
  <div class="acard"><div class="v"><?= $nf($todayRuns) ?></div><div class="k">आज की sync दौड़ें</div></div>
</div>

<div class="agrid">
  <!-- sync health -->
  <div class="panel">
    <div class="ph"><h3>Sync health</h3><span class="t">cron · दो jobs</span></div>
    <table class="atable">
      <tr><th>job</th><th>आख़िरी दौड़</th></tr>
      <tr><td>catalog</td><td><?= $runcell($lastCat) ?></td></tr>
      <tr><td>providers</td><td><?= $runcell($lastProv) ?></td></tr>
    </table>
    <div style="overflow-x:auto;margin-top:14px">
    <table class="atable">
      <tr><th>job</th><th>हालत</th><th>कॉल</th><th>विफल</th><th>+</th><th>−</th><th>कब</th></tr>
      <?php foreach ($runs as $r): ?>
      <tr><td><?= $e($r['job']) ?></td>
        <td class="stt <?= $e($r['status']) ?>"><?= $e($r['status']) ?></td>
        <td class="n"><?= (int) $r['api_calls'] ?></td>
        <td class="n" <?= (int) $r['api_failures'] > 0 ? 'style="color:var(--warn)"' : '' ?>><?= (int) $r['api_failures'] ?></td>
        <td class="n"><?= (int) $r['changes_added'] ?></td>
        <td class="n"><?= (int) $r['changes_removed'] ?></td>
        <td class="n"><?= $e(substr((string) $r['started_at'], 5, 11)) ?></td></tr>
      <?php endforeach; ?>
    </table>
    </div>
  </div>

  <!-- coverage + queue -->
  <div class="panel">
    <div class="ph"><h3>Coverage &amp; queue</h3><span class="t">डेटा की गुणवत्ता</span></div>
    <div class="cov">
      <div>
        <div class="lbl"><span>Poster coverage</span><b><?= $posterPct ?>%</b></div>
        <div class="covbar"><i style="width:<?= $posterPct ?>%"></i></div>
      </div>
      <div>
        <div class="lbl"><span>भाषा coverage</span><b><?= $langPct ?>%</b></div>
        <div class="covbar"><i style="width:<?= $langPct ?>%"></i></div>
      </div>
    </div>
    <table class="atable" style="margin-top:18px">
      <tr><td>कभी नहीं जाँचे titles</td><td class="n" <?= $c['never'] > 0 ? 'style="color:var(--warn)"' : '' ?>><?= $nf($c['never']) ?></td></tr>
      <tr><td>लगातार विफल (fail-streak ≥ 3)</td><td class="n" <?= $c['failing'] > 0 ? 'style="color:var(--pink)"' : '' ?>><?= $nf($c['failing']) ?></td></tr>
      <tr><td>बिना poster titles</td><td class="n"><?= $nf((int) $pcov['tot'] - (int) $pcov['wp']) ?></td></tr>
    </table>
  </div>
</div>

<!-- growth chart -->
<div class="panel" style="margin-top:16px">
  <div class="ph"><h3>पिछले 14 दिन · बदलाव</h3>
    <span class="t"><span style="color:var(--good)">■</span> जुड़े &nbsp; <span style="color:var(--pink)">■</span> हटे</span></div>
  <?php
  $days = [];
  for ($i = 13; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i day"));
  $maxv = 1;
  foreach ($days as $d) { $maxv = max($maxv, $growth[$d]['a'] ?? 0, $growth[$d]['r'] ?? 0); }
  $W = 720; $H = 150; $pad = 18; $bw = ($W - 2 * $pad) / count($days);
  ?>
  <svg viewBox="0 0 <?= $W ?> <?= $H ?>" width="100%" height="170" preserveAspectRatio="xMidYMid meet" role="img" aria-label="14-day changes">
    <line x1="<?= $pad ?>" y1="<?= $H - $pad ?>" x2="<?= $W - $pad ?>" y2="<?= $H - $pad ?>" stroke="rgba(255,255,255,.1)" stroke-width="1"/>
    <?php foreach ($days as $i => $d):
      $a = $growth[$d]['a'] ?? 0; $r = $growth[$d]['r'] ?? 0;
      $x = $pad + $i * $bw; $ah = ($H - 2 * $pad) * $a / $maxv; $rh = ($H - 2 * $pad) * $r / $maxv;
      $w = $bw / 2 - 3; ?>
      <rect x="<?= round($x + 3, 1) ?>" y="<?= round($H - $pad - $ah, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round($ah, 1) ?>" rx="2" fill="#00D26A"/>
      <rect x="<?= round($x + 3 + $w + 1, 1) ?>" y="<?= round($H - $pad - $rh, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round($rh, 1) ?>" rx="2" fill="#FF4D6D"/>
      <?php if ($i % 2 === 0): ?><text x="<?= round($x + $bw / 2, 1) ?>" y="<?= $H - 4 ?>" text-anchor="middle" font-family="IBM Plex Mono,monospace" font-size="9" fill="#5E6C86"><?= (int) date('d', strtotime($d)) ?></text><?php endif; ?>
    <?php endforeach; ?>
  </svg>
</div>

<div class="agrid" style="margin-top:16px">
  <div class="panel">
    <div class="ph"><h3>Top platforms</h3><span class="t">अभी उपलब्ध titles</span></div>
    <?php $pmax = $topProv ? max(array_map(fn ($p) => (int) $p['c'], $topProv)) : 1; ?>
    <div class="bars">
      <?php foreach ($topProv as $p): ?>
      <div class="barrow"><span class="nm"><?= $e($p['name']) ?></span>
        <span class="track"><span class="fill" style="width:<?= (int) round((int) $p['c'] / $pmax * 100) ?>%"></span></span>
        <span class="val"><?= $nf((int) $p['c']) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="panel">
    <div class="ph"><h3>भाषा distribution</h3><span class="t">अभी उपलब्ध titles</span></div>
    <?php $lmax = $langs ? max(array_map(fn ($l) => (int) $l['c'], $langs)) : 1; ?>
    <div class="bars">
      <?php foreach ($langs as $l): ?>
      <div class="barrow"><span class="nm"><?= $e(lang_label($l['lang_code'])) ?></span>
        <span class="track"><span class="fill" style="width:<?= (int) round((int) $l['c'] / $lmax * 100) ?>%"></span></span>
        <span class="val"><?= $nf((int) $l['c']) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="agrid" style="margin-top:16px">
  <div class="panel">
    <div class="ph"><h3>Database tables</h3><span class="t"><?= number_format($dbmb, 1) ?> MB कुल</span></div>
    <div style="overflow-x:auto"><table class="atable">
      <tr><th>table</th><th>rows</th><th>MB</th></tr>
      <?php foreach ($tables as $t): ?>
      <tr><td><?= $e($t['tn']) ?></td><td class="n"><?= $nf((int) $t['tr']) ?></td><td class="n"><?= number_format((float) $t['mb'], 2) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <div class="panel">
    <div class="ph"><h3>ताज़ा बदलाव</h3><span class="t">आख़िरी 12</span></div>
    <div style="overflow-x:auto"><table class="atable">
      <tr><th></th><th>title</th><th>platform</th><th>कब</th></tr>
      <?php foreach ($recent as $r): ?>
      <tr><td class="n" style="color:<?= $r['change_type'] === 'added' ? 'var(--good)' : 'var(--pink)' ?>"><?= $r['change_type'] === 'added' ? '+' : '−' ?></td>
        <td><?= $e(mb_substr($r['title'], 0, 30, 'UTF-8')) ?></td>
        <td><?= $e($r['prov']) ?></td>
        <td class="n"><?= $e(substr((string) $r['changed_on'], 5)) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($recent === []): ?><tr><td colspan="4" class="dim">अभी कुछ नहीं</td></tr><?php endif; ?>
    </table></div>
  </div>
</div>

<?php if (is_array($unknown) && $unknown !== []): ?>
<div class="panel" style="margin-top:16px">
  <div class="ph"><h3>अनजाने provider नाम</h3><span class="t">alias जोड़िए</span></div>
  <table class="atable"><tr><th>नाम</th><th>बार</th></tr>
  <?php arsort($unknown); foreach (array_slice($unknown, 0, 15, true) as $nm => $cnt): ?>
    <tr><td><?= $e($nm) ?></td><td class="n"><?= (int) $cnt ?></td></tr>
  <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<p class="dim small" style="margin:22px 0 40px;font-family:var(--mono)">
  catalog cursor: <?= $e(json_encode($cursor, JSON_UNESCAPED_UNICODE)) ?>
</p>

</main>
</body>
</html>
