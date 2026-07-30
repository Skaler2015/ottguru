<?php
/**
 * Admin — Title inspector  (/admin?view=title&id=N)
 * एक फिल्म/सीरीज़ की पूरी कुंडली + owner की क्रियाएँ (दोबारा जाँचें, tier बदलें)।
 * admin.php से मिलता है: $t, $iOffers, $iEvents, $iLangs, $iGenres, $iCast,
 *                        $CSRF, $selfPath, $flash, $e, $nf
 * writes admin.php के POST handler में हैं — यहाँ सिर्फ़ फ़ॉर्म।
 */
declare(strict_types=1);
$L = OTT_LANG === 'hi';
?><!doctype html>
<html lang="<?= OTT_LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>OTTGuru · Admin · <?= $t !== null ? $e($t['title']) : 'नहीं मिला' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css">
</head>
<body>

<div class="abar"><div class="wrap abar-in">
  <a class="logo" href="/">OTT<span>Guru</span></a>
  <span class="tag">Admin</span>
  <a class="alogout" href="<?= $e($selfPath) ?>" style="margin-left:auto"><?= $L ? '← डैशबोर्ड' : '← Dashboard' ?></a>
  <a class="alogout" href="<?= $e($selfPath) ?>?logout=1"><?= $L ? 'लॉगआउट ↩' : 'Log out ↩' ?></a>
</div></div>

<main class="wrap" style="padding-top:22px;max-width:900px">

<?php if ($t === null): ?>
  <div class="offer-none"><?= $L ? 'यह title नहीं मिला।' : 'Title not found.' ?>
    <a href="<?= $e($selfPath) ?>"><?= $L ? 'वापस' : 'Back' ?></a></div>
<?php else:
  $iYear = nz((string) ($t['release_year'] ?? ''));
  $iType = $t['media_type'] === 'tv' ? ($L ? 'वेब सीरीज़' : 'Series') : ($L ? 'फिल्म' : 'Movie');
  $tierName = [1 => ($L ? 'रोज़' : 'daily'), 2 => ($L ? 'हफ़्ता' : 'weekly'), 3 => ($L ? 'महीना' : 'monthly')];
?>

  <?php if ($flash !== ''): ?>
  <div class="alarm" style="border-color:rgba(0,210,106,.4);background:rgba(0,210,106,.08)">
    <b style="color:var(--good)">✓ हो गया।</b>
    <?= $flash === 'recheck' ? ($L ? 'यह title अगली providers दौड़ में सबसे पहले जाँचा जाएगा।' : 'Queued — will be re-checked first on the next providers run.')
       : ($flash === 'tier' ? ($L ? 'tier बदल दी गई।' : 'Tier updated.')
       : ($flash === 'insent' ? ($L ? 'URL IndexNow (Bing/Yandex) को भेज दिया।' : 'URL sent to IndexNow (Bing/Yandex).') : '')) ?>
  </div>
  <?php endif; ?>

  <!-- header -->
  <div class="t-head" style="margin-bottom:8px">
    <?php $ip = tmdb_img($t['poster_path'], 'w185'); ?>
    <?php if ($ip !== null): ?><div class="t-poster" style="flex:0 0 120px"><img src="<?= $e($ip) ?>" alt="" style="width:120px"></div><?php endif; ?>
    <div class="t-meta">
      <h1 style="font-size:26px"><?= $e($t['title']) ?><?= $iYear !== null ? ' <span class="dim">(' . $e($iYear) . ')</span>' : '' ?></h1>
      <p class="t-sub" style="margin:6px 0 10px">
        <?= $e($iType) ?>
        · <span class="mono">tier <?= (int) $t['tier'] ?></span> (<?= $e($tierName[(int) $t['tier']] ?? '?') ?>)
        · <span class="mono">tmdb <?= (int) $t['tmdb_id'] ?></span>
      </p>
      <div class="badges">
        <a class="badge" href="<?= $e(title_url($t)) ?>" target="_blank" rel="noopener"><?= $L ? 'लाइव पेज ↗' : 'Live page ↗' ?></a>
        <span class="badge">slug: <?= $e($t['slug']) ?></span>
      </div>
    </div>
  </div>

  <!-- actions -->
  <div class="panel" style="margin-top:14px">
    <div class="ph"><h3><?= $L ? 'क्रियाएँ' : 'Actions' ?></h3><span class="t"><?= $L ? 'सुरक्षित — इतिहास नहीं छूता' : 'safe — never touches history' ?></span></div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;padding:4px 2px 2px">
      <form method="post" action="<?= $e($selfPath) ?>?view=title&id=<?= (int) $t['id'] ?>">
        <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
        <input type="hidden" name="do" value="recheck">
        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <button class="watchbtn" type="submit" style="box-shadow:none"><?= $L ? '↻ अभी दोबारा जाँचें' : '↻ Re-check now' ?></button>
      </form>
      <?php foreach ([1 => ($L ? 'tier-1 (रोज़)' : 'tier-1 (daily)'), 3 => ($L ? 'सामान्य (महीना)' : 'normal (monthly)')] as $tv => $lbl): ?>
      <form method="post" action="<?= $e($selfPath) ?>?view=title&id=<?= (int) $t['id'] ?>">
        <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
        <input type="hidden" name="do" value="tier">
        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <input type="hidden" name="tier" value="<?= $tv ?>">
        <button type="submit" class="abtn" <?= (int) $t['tier'] === $tv ? 'disabled' : '' ?>><?= $e($lbl) ?></button>
      </form>
      <?php endforeach; ?>
      <?php if (($inKey ?? '') !== ''): ?>
      <form method="post" action="<?= $e($selfPath) ?>?view=title&id=<?= (int) $t['id'] ?>">
        <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
        <input type="hidden" name="do" value="indexnow_one">
        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
        <button type="submit" class="abtn" title="IndexNow → Bing/Yandex">🔍 <?= $L ? 'index सबमिट करें' : 'Submit to index' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <p class="dim small" style="margin:12px 2px 2px">
      <?= $L ? '“दोबारा जाँचें” = अगली providers दौड़ इसे सबसे पहले जाँचेगी (कुछ मिनट/घंटे में, cron पर निर्भर)। कोई डेटा अभी नहीं बदलता।'
             : '“Re-check” queues it first for the next providers run (minutes/hours, cron-dependent). Nothing is changed right now.' ?>
    </p>
  </div>

  <div class="agrid" style="margin-top:16px">
    <!-- sync status -->
    <div class="panel">
      <div class="ph"><h3><?= $L ? 'Sync स्थिति' : 'Sync status' ?></h3></div>
      <table class="atable">
        <tr><td><?= $L ? 'आख़िरी कोशिश' : 'Last checked' ?></td><td class="n"><?= $e($t['providers_last_checked'] ?? '—') ?: '—' ?></td></tr>
        <tr><td><?= $L ? 'आख़िरी सफलता' : 'Last success' ?></td><td class="n" <?= $t['providers_last_success'] === null ? 'style="color:var(--warn)"' : '' ?>><?= $e($t['providers_last_success'] ?? '') ?: ($L ? 'कभी नहीं' : 'never') ?></td></tr>
        <tr><td><?= $L ? 'लगातार विफल' : 'Fail streak' ?></td><td class="n" <?= (int) $t['providers_fail_streak'] > 0 ? 'style="color:var(--pink)"' : '' ?>><?= (int) $t['providers_fail_streak'] ?></td></tr>
        <tr><td><?= $L ? 'मेटाडेटा भरा' : 'Detail synced' ?></td><td class="n"><?= $e($t['detail_last_success'] ?? '') ?: '—' ?></td></tr>
        <tr><td>popularity</td><td class="n"><?= $e(number_format((float) $t['popularity'], 1)) ?></td></tr>
        <tr><td>TMDB score</td><td class="n"><?= $e(number_format((float) $t['vote_average'], 1)) ?> (<?= $nf((int) $t['vote_count']) ?>)</td></tr>
      </table>
    </div>

    <!-- metadata -->
    <div class="panel">
      <div class="ph"><h3><?= $L ? 'मेटाडेटा' : 'Metadata' ?></h3></div>
      <table class="atable">
        <tr><td><?= $L ? 'भाषाएँ' : 'Languages' ?></td><td><?= $iLangs ? $e(implode(', ', array_map(fn ($l) => lang_label($l['lang_code']) . ($l['kind'] === 'original' ? '*' : ''), $iLangs))) : '<span class="dim">—</span>' ?></td></tr>
        <tr><td>genres</td><td><?= $iGenres ? $e(implode(', ', array_map(fn ($g) => $g['name_en'], $iGenres))) : '<span class="dim">—</span>' ?></td></tr>
        <tr><td>cast/crew</td><td><?= $iCast ? $e(implode(', ', array_map(fn ($c) => $c['name'], array_slice($iCast, 0, 6)))) : '<span class="dim">—</span>' ?></td></tr>
      </table>
    </div>
  </div>

  <!-- availability + history -->
  <div class="panel" style="margin-top:16px">
    <div class="ph"><h3><?= $L ? 'उपलब्धता + इतिहास' : 'Availability + history' ?></h3><span class="t"><?= count($iOffers) ?> spells · <?= count($iEvents) ?> <?= $L ? 'बदलाव' : 'changes' ?></span></div>
    <div style="overflow-x:auto"><table class="atable">
      <tr><th></th><th>platform</th><th>offer</th><th><?= $L ? 'कब से' : 'since' ?></th><th></th></tr>
      <?php foreach ($iOffers as $o): ?>
      <tr>
        <td class="n" style="color:<?= $o['is_current'] ? 'var(--good)' : 'var(--ink3)' ?>"><?= $o['is_current'] ? '●' : '○' ?></td>
        <td><?= $e($o['name']) ?></td>
        <td><?= $e(offer_label($o['offer_type'])) ?></td>
        <td class="n"><?= $e(substr((string) $o['first_seen'], 0, 10)) ?></td>
        <td class="n"><?= $o['is_current'] ? ($L ? 'अभी' : 'now') : ($L ? 'बीता' : 'past') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if ($iOffers === []): ?><tr><td colspan="5" class="dim"><?= $L ? 'अभी किसी OTT पर नहीं, और कोई इतिहास नहीं।' : 'Not on any OTT, no history yet.' ?></td></tr><?php endif; ?>
    </table></div>

    <?php if ($iEvents !== []): ?>
    <div style="overflow-x:auto;margin-top:14px"><table class="atable">
      <tr><th></th><th><?= $L ? 'बदलाव' : 'change' ?></th><th>platform</th><th><?= $L ? 'कब' : 'when' ?></th></tr>
      <?php foreach ($iEvents as $ev): ?>
      <tr>
        <td class="n" style="color:<?= $ev['change_type'] === 'added' ? 'var(--good)' : 'var(--pink)' ?>"><?= $ev['change_type'] === 'added' ? '+' : '−' ?></td>
        <td><?= $ev['change_type'] === 'added' ? ($L ? 'आई' : 'added') : ($L ? 'हटी' : 'removed') ?></td>
        <td><?= $e($ev['name']) ?></td>
        <td class="n"><?= $e(substr((string) $ev['changed_on'], 0, 10)) ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>

<?php endif; ?>

</main>
</body>
</html>
