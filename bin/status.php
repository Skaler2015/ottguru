<?php
/**
 * ============================================================================
 *  status.php — sync की सेहत
 *  खोलिए: bin/status.php?k=आपका-run-token
 *  यही पन्ना रोज़ एक बार देख लेना पूरे सिस्टम की निगरानी है।
 * ============================================================================
 */
$GLOBALS['__RAW_OUTPUT'] = true;
require dirname(__DIR__) . '/lib/boot.php';

if (!OTT_IS_CLI) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

$counts = [
    'titles'          => (int) scalar($PDO, 'SELECT COUNT(*) FROM titles'),
    'providers'       => (int) scalar($PDO, 'SELECT COUNT(*) FROM providers WHERE is_active = 1'),
    'avail_current'   => (int) scalar($PDO, 'SELECT COUNT(*) FROM availability WHERE is_current = 1'),
    'avail_past'      => (int) scalar($PDO, 'SELECT COUNT(*) FROM availability WHERE is_current = 0'),
    'changes'         => (int) scalar($PDO, 'SELECT COUNT(*) FROM availability_changes'),
    'never_checked'   => (int) scalar($PDO, 'SELECT COUNT(*) FROM titles WHERE providers_last_success IS NULL'),
    'failing'         => (int) scalar($PDO, 'SELECT COUNT(*) FROM titles WHERE providers_fail_streak >= 3'),
];

$byTier = all($PDO, 'SELECT tier, COUNT(*) c,
                            SUM(providers_last_success IS NULL) never_done
                       FROM titles GROUP BY tier ORDER BY tier');

$runs = all($PDO, 'SELECT * FROM sync_runs ORDER BY id DESC LIMIT 15');

$recent = all($PDO, '
    SELECT c.change_type, c.changed_on, t.title, t.release_year, p.name AS provider, c.offer_type
      FROM availability_changes c
      JOIN titles t ON t.id = c.title_id
      JOIN providers p ON p.id = c.provider_id
     ORDER BY c.id DESC LIMIT 25');

$last7 = all($PDO, '
    SELECT changed_on, change_type, COUNT(*) c
      FROM availability_changes
     WHERE changed_on >= (CURDATE() - INTERVAL 7 DAY)
     GROUP BY changed_on, change_type
     ORDER BY changed_on DESC');

$topProv = all($PDO, '
    SELECT p.name, COUNT(*) c
      FROM availability a JOIN providers p ON p.id = a.provider_id
     WHERE a.is_current = 1 AND a.offer_type IN ("flatrate","ads","free")
     GROUP BY p.id ORDER BY c DESC LIMIT 12');

$unknown = state_get($PDO, 'unknown_providers', []);
$cursor  = state_get($PDO, 'catalog_cursor', []);

$halted = array_values(array_filter($runs, fn($r) => $r['status'] === 'halted'));

if (OTT_IS_CLI) {
    echo "titles {$counts['titles']} · providers {$counts['providers']}"
       . " · अभी उपलब्ध {$counts['avail_current']} · इतिहास {$counts['changes']}\n";
    foreach ($runs as $r) {
        echo str_pad($r['job'], 12) . str_pad($r['status'], 9)
           . ' +' . $r['changes_added'] . ' -' . $r['changes_removed']
           . '  कॉल ' . $r['api_calls'] . ' (विफल ' . $r['api_failures'] . ')'
           . '  ' . $r['started_at'] . "\n";
    }
    exit(0);
}

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="hi"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>OTT Guru — sync सेहत</title>
<link href="https://fonts.googleapis.com/css2?family=Tiro+Devanagari+Hindi&family=Mukta:wght@400;600;700&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{--paper:#e6ebee;--surface:#fff;--ink:#0e1a21;--muted:#5a6b76;--rule:#c6d1d8;
--rule-soft:#dde4e9;--ok:#0b6b4f;--warn:#96550a;--bad:#8e1b3f;--petrol:#1b4b6b;
--mono:'IBM Plex Mono',ui-monospace,monospace}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);
font:16px/1.6 'Mukta',system-ui,sans-serif;padding:0 0 60px}
.wrap{max-width:1080px;margin:0 auto;padding:0 20px}
header{background:var(--surface);border-bottom:2px solid var(--ink);padding:16px 0}
h1{font-family:'Tiro Devanagari Hindi',serif;font-weight:400;font-size:23px;margin:0}
h1 small{display:block;font-family:'Mukta';font-size:11px;letter-spacing:.14em;
text-transform:uppercase;color:var(--muted);font-weight:600}
h2{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);
font-weight:600;margin:28px 0 10px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:22px}
.card{background:var(--surface);border:1px solid var(--rule);padding:13px 15px}
.card b{display:block;font-family:var(--mono);font-size:25px;font-weight:600;line-height:1.15;
font-variant-numeric:tabular-nums}
.card span{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em}
.card.bad b{color:var(--bad)}.card.warn b{color:var(--warn)}.card.ok b{color:var(--ok)}
table{width:100%;border-collapse:collapse;background:var(--surface);border:1px solid var(--rule);
font-size:14px}
th{text-align:left;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);
padding:8px 11px;border-bottom:1px solid var(--rule)}
td{padding:7px 11px;border-bottom:1px solid var(--rule-soft)}
tr:last-child td{border-bottom:0}
td.n{font-family:var(--mono);font-variant-numeric:tabular-nums}
.st{font-family:var(--mono);font-size:12px;font-weight:600}
.st.done{color:var(--ok)}.st.running{color:var(--petrol)}
.st.failed{color:var(--bad)}.st.halted{color:var(--bad)}
.pill{display:inline-block;font-family:var(--mono);font-size:11px;padding:1px 7px;border:1px solid var(--rule)}
.added{color:var(--ok);border-color:var(--ok)}.removed{color:var(--bad);border-color:var(--bad)}
.alarm{background:#fdecef;border:1px solid var(--bad);color:var(--bad);padding:14px 16px;margin-top:22px}
.note{font-size:13px;color:var(--muted);margin-top:8px}
code{font-family:var(--mono);font-size:12.5px}
@media(max-width:640px){table{font-size:13px}td,th{padding:6px 8px}}
</style></head><body>

<header><div class="wrap"><h1><small>ottguru.in</small>sync की सेहत</h1></div></header>
<div class="wrap">

<?php if ($halted !== []): ?>
  <div class="alarm">
    <b>सुरक्षा ब्रेक लगा था।</b><br>
    <?= e($halted[0]['note'] ?? '') ?><br>
    <span class="note">जब तक वजह ठीक नहीं होती, वे titles दोबारा जाँचे जाते रहेंगे और कोई बदलाव दर्ज नहीं होगा — यानी इतिहास सुरक्षित है।</span>
  </div>
<?php endif; ?>

<div class="cards">
  <div class="card"><b><?= number_format($counts['titles']) ?></b><span>titles</span></div>
  <div class="card"><b><?= number_format($counts['providers']) ?></b><span>providers</span></div>
  <div class="card ok"><b><?= number_format($counts['avail_current']) ?></b><span>अभी उपलब्ध</span></div>
  <div class="card"><b><?= number_format($counts['avail_past']) ?></b><span>बीत चुके</span></div>
  <div class="card ok"><b><?= number_format($counts['changes']) ?></b><span>इतिहास की entries</span></div>
  <div class="card <?= $counts['never_checked'] > 0 ? 'warn' : '' ?>">
    <b><?= number_format($counts['never_checked']) ?></b><span>कभी नहीं जाँचे</span></div>
  <div class="card <?= $counts['failing'] > 0 ? 'bad' : '' ?>">
    <b><?= number_format($counts['failing']) ?></b><span>लगातार विफल</span></div>
</div>

<h2>tier के हिसाब से</h2>
<table><tr><th>tier</th><th>मतलब</th><th>titles</th><th>अभी तक नहीं जाँचे</th></tr>
<?php
$tierName = [1 => 'रोज़ (चर्चित/नया)', 2 => 'हफ़्ते में', 3 => 'महीने में (long tail)'];
foreach ($byTier as $t): ?>
  <tr><td class="n"><?= (int) $t['tier'] ?></td>
      <td><?= e($tierName[(int) $t['tier']] ?? '—') ?></td>
      <td class="n"><?= number_format((int) $t['c']) ?></td>
      <td class="n"><?= number_format((int) $t['never_done']) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>पिछली दौड़ें</h2>
<table><tr><th>job</th><th>हालत</th><th>titles</th><th>कॉल</th><th>विफल</th>
<th>जुड़े</th><th>हटे</th><th>शुरू</th><th>अवधि</th><th>टिप्पणी</th></tr>
<?php foreach ($runs as $r):
    $dur = ($r['finished_at'] && $r['started_at'])
        ? (strtotime($r['finished_at']) - strtotime($r['started_at'])) . 's' : '—'; ?>
  <tr>
    <td><?= e($r['job']) ?></td>
    <td class="st <?= e($r['status']) ?>"><?= e($r['status']) ?></td>
    <td class="n"><?= (int) $r['titles_seen'] ?></td>
    <td class="n"><?= (int) $r['api_calls'] ?></td>
    <td class="n"><?= (int) $r['api_failures'] ?></td>
    <td class="n"><?= (int) $r['changes_added'] ?></td>
    <td class="n"><?= (int) $r['changes_removed'] ?></td>
    <td class="n"><?= e(substr((string) $r['started_at'], 5, 11)) ?></td>
    <td class="n"><?= e($dur) ?></td>
    <td style="font-size:12px;color:var(--muted)"><?= e(mb_substr((string) $r['note'], 0, 90)) ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>पिछले 7 दिन के बदलाव</h2>
<?php if ($last7 === []): ?>
  <p class="note">अभी कोई बदलाव दर्ज नहीं — पहली दौड़ के बाद यह भरने लगेगा।</p>
<?php else: ?>
<table><tr><th>तारीख़</th><th>किस्म</th><th>कितने</th></tr>
<?php foreach ($last7 as $d): ?>
  <tr><td class="n"><?= e($d['changed_on']) ?></td>
      <td><span class="pill <?= e($d['change_type']) ?>">
        <?= $d['change_type'] === 'added' ? 'जुड़े' : 'हटे' ?></span></td>
      <td class="n"><?= number_format((int) $d['c']) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>किस provider पर कितना (subscription/मुफ़्त)</h2>
<table><tr><th>provider</th><th>titles</th></tr>
<?php foreach ($topProv as $p): ?>
  <tr><td><?= e($p['name']) ?></td><td class="n"><?= number_format((int) $p['c']) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>ताज़ा बदलाव</h2>
<?php if ($recent === []): ?>
  <p class="note">अभी कुछ नहीं।</p>
<?php else: ?>
<table><tr><th>तारीख़</th><th></th><th>title</th><th>provider</th><th>किस्म</th></tr>
<?php foreach ($recent as $c): ?>
  <tr><td class="n"><?= e($c['changed_on']) ?></td>
      <td><span class="pill <?= e($c['change_type']) ?>">
        <?= $c['change_type'] === 'added' ? '+' : '−' ?></span></td>
      <td><?= e($c['title']) ?><?= $c['release_year'] ? ' <span style="color:var(--muted)">(' . (int) $c['release_year'] . ')</span>' : '' ?></td>
      <td><?= e($c['provider']) ?></td>
      <td class="n" style="font-size:12px"><?= e($c['offer_type']) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (is_array($unknown) && $unknown !== []): ?>
<h2>अनजाने provider नाम — alias जोड़िए</h2>
<table><tr><th>नाम</th><th>कितनी बार मिला</th></tr>
<?php arsort($unknown); foreach (array_slice($unknown, 0, 20, true) as $nm => $c): ?>
  <tr><td><?= e($nm) ?></td><td class="n"><?= (int) $c ?></td></tr>
<?php endforeach; ?>
</table>
<p class="note">इन्हें <code>provider_aliases</code> में जोड़ दीजिए, वरना ये availability में गिने नहीं जाएँगे।</p>
<?php endif; ?>

<h2>catalog कर्सर</h2>
<p class="note"><code><?= e(json_encode($cursor, JSON_UNESCAPED_UNICODE)) ?></code>
&nbsp;— <code>mt</code> 0=movie 1=tv, <code>pi</code> = provider क्रमांक, <code>page</code> = अगला पेज</p>

</div></body></html>
