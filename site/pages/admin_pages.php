<?php
/**
 * Admin — Pages & index  (/admin?view=pages)
 * हर page-type + गिनती + index नीति, और ज़रूरी पेजों की असली live जाँच।
 * admin.php से मिलता है: $base, $ptypes, $totalIndex, $check, $live,
 *                        $nMovie, $nSeries, $nProvLive, $nLang, $selfPath, $CSRF, $e, $nf
 */
declare(strict_types=1);
$L = OTT_LANG === 'hi';

// live status → रंग + लेबल।  code 0 = "जाँच नहीं हो पाई" (पेज down है ऐसा ज़रूरी नहीं —
// हो सकता है सर्वर ख़ुद तक न पहुँच पाया हो); असली गड़बड़ सिर्फ़ 4xx/5xx पर।
$anyUnchecked = false;
$statusOf = function (array $r) use ($L, &$anyUnchecked): array {
    $c = (int) $r['code'];
    if ($c === 0)              { $anyUnchecked = true; return ['warn', $L ? 'जाँच नहीं हुई' : 'not checked']; }
    if ($c >= 200 && $c < 300)   return $r['noindex'] ? ['warn', 'noindex'] : ['ok', 'live · index'];
    if ($c >= 300 && $c < 400)   return ['warn', 'redirect ' . $c];
    return ['bad', ($L ? 'गड़बड़ ' : 'error ') . $c];
};
?><!doctype html>
<html lang="<?= OTT_LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>OTTGuru · Admin · <?= $L ? 'पेज + index' : 'Pages' ?></title>
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
  <a class="alogout" href="<?= $e($selfPath) ?>?view=pages"><?= $L ? '↻ फिर जाँचें' : '↻ Re-check' ?></a>
  <a class="alogout" href="<?= $e($selfPath) ?>?logout=1"><?= $L ? 'लॉगआउट ↩' : 'Log out ↩' ?></a>
</div></div>

<main class="wrap" style="padding-top:22px;max-width:900px">

  <h1 style="font-size:24px;margin-bottom:4px"><?= $L ? 'पेज + Index status' : 'Pages & index' ?></h1>
  <p class="dim" style="margin:0 0 18px;max-width:66ch">
    <?= $L ? 'आपकी साइट के हर तरह के पेज, कितने URL हैं, और वे Google में index होने चाहिए या नहीं। साथ में ज़रूरी पेजों की अभी-अभी असली जाँच — खुल रहे हैं या नहीं।'
           : 'Every page type on your site, how many URLs, and whether they should be indexed. Plus a live check of the key pages right now.' ?>
  </p>

  <!-- ====== live health ====== -->
  <div class="panel">
    <div class="ph"><h3><?= $L ? 'ज़रूरी पेज — अभी live?' : 'Key pages — live now?' ?></h3>
      <span class="t"><?= $L ? 'असली जाँच' : 'real check' ?> · <?= $e(date('H:i')) ?></span></div>
    <div style="overflow-x:auto"><table class="atable">
      <tr><th></th><th><?= $L ? 'पेज' : 'page' ?></th><th>HTTP</th><th>ms</th><th>status</th></tr>
      <?php foreach ($check as $label => $url):
        $r = $live[$label] ?? ['code' => 0, 'ms' => 0, 'noindex' => false];
        [$cls, $txt] = $statusOf($r);
        $dotc = $cls === 'ok' ? 'var(--good)' : ($cls === 'warn' ? 'var(--warn)' : 'var(--pink)'); ?>
      <tr>
        <td class="n" style="color:<?= $dotc ?>;font-size:15px">●</td>
        <td><a href="<?= $e($url) ?>" target="_blank" rel="noopener"><?= $e($label) ?></a></td>
        <td class="n"><?= (int) $r['code'] ?: '—' ?></td>
        <td class="n dim"><?= (int) $r['ms'] ?></td>
        <td class="n" style="color:<?= $dotc ?>"><?= $e($txt) ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <p class="dim small" style="margin:12px 2px 0">
      <?= $L ? '🟢 live + index होने लायक़ · 🟡 live पर noindex (जैसे /search — यह जान-बूझकर है) या जाँच नहीं हुई · 🔴 असली गड़बड़ (4xx/5xx) — तुरंत देखिए।'
             : '🟢 live + indexable · 🟡 noindex (intentional) or not checked · 🔴 real error (4xx/5xx) — check now.' ?>
    </p>
    <?php if ($anyUnchecked): ?>
    <div class="okline" style="background:rgba(255,197,66,.1);border-color:rgba(255,197,66,.3);color:#ffd985;margin-top:10px">
      <?= $L ? 'कुछ पेज “जाँच नहीं हुई” दिखे — इसका मतलब वे down हैं ऐसा ज़रूरी नहीं। सर्वर कभी-कभी अपने ही पेज सीधे नहीं पढ़ पाता। ऊपर पेज के नाम पर क्लिक करके ख़ुद देख लीजिए — browser में खुलें तो सब ठीक है।'
             : 'Some pages show “not checked” — that doesn’t mean they’re down. The server can’t always fetch its own pages directly. Click a page name above to verify in your browser.' ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ====== page types ====== -->
  <div class="panel" style="margin-top:16px">
    <div class="ph"><h3><?= $L ? 'सारे page-type' : 'All page types' ?></h3>
      <span class="t"><?= $nf($totalIndex) ?> <?= $L ? 'index-योग्य URL' : 'indexable URLs' ?></span></div>
    <div style="overflow-x:auto"><table class="atable">
      <tr><th><?= $L ? 'तरह' : 'type' ?></th><th><?= $L ? 'उदाहरण' : 'example' ?></th><th><?= $L ? 'कितने' : 'count' ?></th><th>index?</th><th>sitemap?</th></tr>
      <?php foreach ($ptypes as [$name, $ex, $cnt, $idx]): ?>
      <tr>
        <td><?= $e($name) ?></td>
        <td class="dim"><span class="mono" style="font-size:12.5px"><?= $e($ex) ?></span></td>
        <td class="n"><?= $cnt === null ? '<span class="dim">—</span>' : $nf((int) $cnt) ?></td>
        <td class="n"><?= $idx ? '<span style="color:var(--good)">✓ index</span>' : '<span style="color:var(--warn)">noindex</span>' ?></td>
        <td class="n"><?= $idx ? '<span style="color:var(--good)">✓</span>' : '<span class="dim">—</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <p class="dim small" style="margin:12px 2px 0">
      <?= $L ? 'sitemap में सिर्फ़ वही पेज हैं जिन पर असल में कुछ है (ख़ाली/thin पेज नहीं — यही Google-deindex से बचाता है)। इसलिए “कितने” की गिनती बदलती रहती है जैसे-जैसे availability भरती है।'
             : 'The sitemap lists only pages with real content (no thin pages — this is what avoids Google deindex). So counts grow as availability fills.' ?>
    </p>
  </div>

  <!-- ====== IndexNow — तुरंत index के लिए सबमिट ====== -->
  <div class="panel" style="margin-top:16px">
    <div class="ph"><h3><?= $L ? 'तुरंत index के लिए सबमिट (IndexNow)' : 'Submit for indexing (IndexNow)' ?></h3>
      <span class="t">Bing · Yandex · Seznam …</span></div>

    <?php if ($flash === 'inon'): ?><div class="okline">✓ <?= $L ? 'IndexNow चालू हो गया।' : 'IndexNow enabled.' ?></div><?php endif; ?>
    <?php if ($flash === 'insent'):
      $c = (int) ($inLast['code'] ?? 0); $good = $c >= 200 && $c < 300; ?>
      <div class="okline"<?= $good ? '' : ' style="background:rgba(255,197,66,.1);border-color:rgba(255,197,66,.3);color:#ffd985"' ?>>
        <?= $good ? '✓ ' . ($L ? 'भेज दिया' : 'sent') : '⚠ ' . ($L ? 'भेजा' : 'sent') ?>
        — <?= (int) ($inLast['n'] ?? 0) ?> URL · HTTP <?= $c ?: '—' ?>
      </div>
    <?php endif; ?>

    <?php if ($inKey === ''): ?>
      <p class="dim" style="font-size:13.5px;margin:0 0 12px;max-width:70ch">
        <?= $L ? 'एक बार चालू कर दीजिए — फिर एक क्लिक में नए/बदले पेज सीधे search engines को “अभी देख लो” कह देंगे। कोई setup नहीं, key अपने-आप बनकर सुरक्षित रहती है।'
               : 'Turn it on once — then one click tells search engines to crawl new/changed pages. No setup; the key is generated and stored for you.' ?>
      </p>
      <form method="post" action="<?= $e($selfPath) ?>">
        <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
        <input type="hidden" name="do" value="indexnow_on">
        <button type="submit" class="watchbtn" style="box-shadow:none"><?= $L ? 'IndexNow चालू करें' : 'Enable IndexNow' ?></button>
      </form>
    <?php else:
      $kf   = $live['IndexNow key फ़ाइल'] ?? ['code' => 0];
      $kfOk = (int) $kf['code'] >= 200 && (int) $kf['code'] < 300; ?>
      <table class="atable" style="margin-bottom:14px">
        <tr><td><?= $L ? 'हालत' : 'status' ?></td><td class="n" style="color:var(--good)"><?= $L ? 'चालू ✓' : 'on ✓' ?></td></tr>
        <tr><td><?= $L ? 'key फ़ाइल live?' : 'key file live?' ?></td>
          <td class="n" style="color:<?= $kfOk ? 'var(--good)' : 'var(--warn)' ?>"><?= $kfOk ? 'HTTP ' . (int) $kf['code'] . ' ✓' : ($L ? 'जाँच नहीं' : 'not verified') ?></td></tr>
        <?php if (is_array($inLast)): ?>
        <tr><td><?= $L ? 'पिछली सबमिट' : 'last submit' ?></td><td class="n"><?= $e($inLast['at'] ?? '') ?> · <?= (int) ($inLast['n'] ?? 0) ?> URL · HTTP <?= (int) ($inLast['code'] ?? 0) ?: '—' ?></td></tr>
        <?php endif; ?>
      </table>
      <form method="post" action="<?= $e($selfPath) ?>">
        <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
        <input type="hidden" name="do" value="indexnow_recent">
        <button type="submit" class="watchbtn" style="box-shadow:none"><?= $L ? ('आज के ' . (int) $todayNew . ' नए/बदले पेज सबमिट करें') : ('Submit ' . (int) $todayNew . ' recent pages') ?></button>
      </form>
      <p class="dim small" style="margin:12px 2px 0">
        <?= $L ? 'किसी एक फ़िल्म का URL भेजना हो तो उसका inspector खोलिए → “index सबमिट करें”।'
               : 'For a single title, open its inspector → “Submit to index”.' ?>
      </p>
    <?php endif; ?>

    <p class="dim small" style="margin:12px 2px 0">
      ⚠ <?= $L ? 'IndexNow, Bing/Yandex आदि तक पहुँचता है। Google अभी IndexNow नहीं लेता — Google के लिए नीचे “असली Google index” (sitemap + Search Console) देखिए।'
              : 'IndexNow reaches Bing/Yandex etc. Google doesn’t support IndexNow yet — for Google use sitemap + Search Console below.' ?>
    </p>
  </div>

  <!-- ====== sitemap + GSC ====== -->
  <div class="agrid" style="margin-top:16px">
    <div class="panel">
      <div class="ph"><h3>Sitemap</h3></div>
      <table class="atable">
        <tr><td><?= $L ? 'कुल index-योग्य URL' : 'total indexable URLs' ?></td><td class="n"><?= $nf($totalIndex) ?></td></tr>
        <tr><td>sitemap.xml</td><td class="n"><a href="<?= $e($base) ?>/sitemap.xml" target="_blank" rel="noopener"><?= $L ? 'खोलें ↗' : 'open ↗' ?></a></td></tr>
        <tr><td>robots.txt</td><td class="n"><a href="<?= $e($base) ?>/robots.txt" target="_blank" rel="noopener"><?= $L ? 'खोलें ↗' : 'open ↗' ?></a></td></tr>
      </table>
    </div>
    <div class="panel">
      <div class="ph"><h3><?= $L ? 'असली Google index' : 'Real Google index' ?></h3></div>
      <p class="dim" style="font-size:13.5px;margin:0 0 10px">
        <?= $L ? '“Google ने असल में कितने पेज index किए” — यह सिर्फ़ Google Search Console बताता है (कोई साइट ख़ुद नहीं जान सकती)। वहाँ sitemap जमा कर दीजिए और “Pages” रिपोर्ट देखिए।'
               : 'The true “how many pages Google indexed” only Search Console can tell. Submit the sitemap there and see the “Pages” report.' ?>
      </p>
      <a class="badge" href="https://search.google.com/search-console" target="_blank" rel="noopener">Search Console ↗</a>
    </div>
  </div>

</main>
</body>
</html>
