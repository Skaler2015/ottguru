<?php
/**
 * होमपेज — "OTT Intelligence" look (चरण 3)।
 * सब कुछ असली DB से: आँकड़े, ट्रेंडिंग, platform cards, नया आया, और
 * एक छोटा analytics पैनल (सबसे ज़्यादा जुड़ीं + ट्रेंडिंग भाषाएँ)।
 * कोई नक़ली संख्या नहीं — जो डेटा हमारे पास है वही दिखता है।
 */
declare(strict_types=1);

$country = $CFG['country'] ?? 'IN';

// ---- आँकड़े (query 10 + इस हफ़्ते हटीं) ----------------------------------------
$stats = one($PDO, "
    SELECT
      (SELECT COUNT(*) FROM titles)                                            AS titles,
      (SELECT COUNT(*) FROM providers WHERE is_active = 1)                     AS platforms,
      (SELECT COUNT(*) FROM availability WHERE is_current = 1)                 AS abhi_uplabdh,
      (SELECT COUNT(*) FROM availability_changes)                              AS itihas,
      (SELECT COUNT(DISTINCT title_id) FROM availability_changes
        WHERE changed_on >= (CURDATE() - INTERVAL 7 DAY) AND change_type='added')   AS is_hafte_naya,
      (SELECT COUNT(DISTINCT title_id) FROM availability_changes
        WHERE changed_on >= (CURDATE() - INTERVAL 7 DAY) AND change_type='removed') AS is_hafte_hata") ?? [];

// ---- platforms जिन पर सच में कुछ है ------------------------------------------
$provs = all($PDO, "
    SELECT p.slug, p.name, p.logo_path, COUNT(DISTINCT a.title_id) AS kitne
      FROM providers p
      JOIN availability a ON a.provider_id = p.id
                         AND a.is_current = 1
                         AND a.country = ?
                         AND a.offer_type IN ('flatrate','ads','free')
     WHERE p.is_active = 1
     GROUP BY p.id
     ORDER BY p.display_priority, kitne DESC
     LIMIT 12",
    [$country]);

// हर platform पर इस हफ़्ते कितनी नई जुड़ीं — अलग query, फिर PHP में जोड़ते हैं
$naya7 = [];
foreach (all($PDO, "
    SELECT provider_id, COUNT(DISTINCT title_id) AS n
      FROM availability_changes
     WHERE country = ? AND change_type = 'added'
       AND changed_on >= (CURDATE() - INTERVAL 7 DAY)
     GROUP BY provider_id", [$country]) as $r) {
    $naya7[(int) $r['provider_id']] = (int) $r['n'];
}
// provider_id provs में नहीं है — slug से नक़्शा बनाना पड़े तो id चाहिए; जोड़ लेते हैं
$prov_ids = [];
foreach (all($PDO, "SELECT id, slug FROM providers WHERE is_active = 1") as $r) {
    $prov_ids[$r['slug']] = (int) $r['id'];
}

// ---- ट्रेंडिंग (चर्चित) -----------------------------------------------------------
$hot = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type,
           t.vote_average, t.popularity
      FROM availability a
      JOIN titles t ON t.id = a.title_id
     WHERE a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
     ORDER BY t.popularity DESC
     LIMIT 18",
    [$country]);

// ---- इस हफ़्ते नया आया (dedup) --------------------------------------------------
$naya = all($PDO, "
    SELECT t.slug, t.title, t.release_year, t.poster_path, t.media_type,
           MAX(c.changed_on) AS changed_on, MIN(p.name) AS pname,
           COUNT(DISTINCT c.provider_id) AS pkitne
      FROM availability_changes c
      JOIN titles t    ON t.id = c.title_id
      JOIN providers p ON p.id = c.provider_id
     WHERE c.country = ? AND c.change_type = 'added'
       AND c.offer_type IN ('flatrate','ads','free')
       AND c.changed_on >= (CURDATE() - INTERVAL 7 DAY)
     GROUP BY t.id
     ORDER BY changed_on DESC, MAX(t.popularity) DESC
     LIMIT 12",
    [$country]);

// ---- analytics: इस महीने सबसे ज़्यादा जुड़ीं (platform-वार) ----------------------
$most_added = all($PDO, "
    SELECT p.name, COUNT(DISTINCT c.title_id) AS n
      FROM availability_changes c
      JOIN providers p ON p.id = c.provider_id
     WHERE c.country = ? AND c.change_type = 'added'
       AND c.offer_type IN ('flatrate','ads','free')
       AND c.changed_on >= (CURDATE() - INTERVAL 30 DAY)
     GROUP BY p.id
     ORDER BY n DESC
     LIMIT 5",
    [$country]);

// ---- analytics: ट्रेंडिंग भाषाएँ (अभी उपलब्ध titles में) --------------------------
$top_langs = all($PDO, "
    SELECT l.lang_code, COUNT(DISTINCT t.id) AS n
      FROM availability a
      JOIN titles t          ON t.id = a.title_id
      JOIN title_languages l ON l.title_id = t.id
     WHERE a.country = ? AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
     GROUP BY l.lang_code
     ORDER BY n DESC
     LIMIT 6",
    [$country]);

page_header([
    'canonical' => '/',
    'jsonld'    => [
        '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'OTTGuru',
        'url' => 'https://ottguru.in/', 'inLanguage' => OTT_LANG,
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => 'https://ottguru.in/search?q={q}',
            'query-input' => 'required name=q',
        ],
    ],
]);
?>

<section class="hero">
  <span class="kicker"><span class="pulse"></span><?= h(t('रोज़ रात अपने-आप जाँचा जाता है')) ?></span>
  <?php if (OTT_LANG === 'hi'): ?>
    <h1>भारत का <span class="g">OTT इंटेलिजेंस</span> प्लेटफ़ॉर्म</h1>
  <?php else: ?>
    <h1>India's <span class="g">OTT Intelligence</span> Platform</h1>
  <?php endif; ?>
  <p class="sub"><?= t('खोजिए, तुलना कीजिए और नज़र रखिए — कौन सी फिल्म किस platform पर है, और सबसे ख़ास: <b>कब से कब तक कहाँ थी</b>।') ?></p>

  <form class="hsearch" action="/search" method="get" role="search">
    <div class="inner">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input name="q" placeholder="<?= h(t('फिल्म, सीरीज़ या platform खोजिए…')) ?>" aria-label="<?= h(t('खोजें')) ?>" autocomplete="off">
      <button type="submit"><?= h(t('खोजें')) ?></button>
    </div>
  </form>

  <?php if ($provs !== []): ?>
  <div class="pbadges">
    <?php foreach (array_slice($provs, 0, 10) as $p): $lg = tmdb_img($p['logo_path'], 'w45'); ?>
      <a class="pbadge" href="<?= h(provider_url($p)) ?>">
        <?php if ($lg !== null): ?><img class="lg" style="object-fit:cover" src="<?= h($lg) ?>" alt=""><?php endif; ?>
        <?= h($p['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php if ($stats !== [] && (int) ($stats['titles'] ?? 0) > 0): ?>
<?php $fmt = fn(int $x) => number_format($x); ?>
<div class="intel">
  <div class="istat"><div class="n" data-to="<?= (int) $stats['titles'] ?>"><?= $fmt((int) $stats['titles']) ?></div><div class="l"><?= h(t('फिल्में और सीरीज़')) ?></div><div class="d">TMDB</div></div>
  <div class="istat"><div class="n" data-to="<?= (int) $stats['platforms'] ?>"><?= $fmt((int) $stats['platforms']) ?></div><div class="l">OTT platforms</div><div class="d"><?= OTT_LANG === 'hi' ? 'भारत' : 'India' ?></div></div>
  <div class="istat"><div class="n" data-to="<?= (int) $stats['abhi_uplabdh'] ?>"><?= $fmt((int) $stats['abhi_uplabdh']) ?></div><div class="l"><?= h(t('अभी उपलब्ध')) ?></div><div class="d"><?= OTT_LANG === 'hi' ? 'लाइव' : 'live' ?></div></div>
  <div class="istat"><div class="n up" data-to="<?= (int) $stats['is_hafte_naya'] ?>"><?= $fmt((int) $stats['is_hafte_naya']) ?></div><div class="l"><?= h(t('इस हफ़्ते नई आईं')) ?></div><div class="d up">+</div></div>
  <div class="istat"><div class="n down" data-to="<?= (int) $stats['is_hafte_hata'] ?>"><?= $fmt((int) $stats['is_hafte_hata']) ?></div><div class="l"><?= h(t('इस हफ़्ते हटीं')) ?></div><div class="d down">−</div></div>
  <div class="istat"><div class="n" data-to="<?= (int) $stats['itihas'] ?>"><?= $fmt((int) $stats['itihas']) ?></div><div class="l"><?= h(t('इतिहास में दर्ज बदलाव')) ?></div><div class="d"><?= OTT_LANG === 'hi' ? 'सिर्फ़ हमारे पास' : 'only here' ?></div></div>
</div>
<?php endif; ?>

<?php if ($hot !== []): ?>
<section>
  <div class="head">
    <div><span class="eyebrow"><?= OTT_LANG === 'hi' ? 'अभी भारत में' : 'Right now in India' ?></span><h2><?= h(t('अभी चर्चा में')) ?></h2></div>
  </div>
  <div class="rail">
    <?php foreach ($hot as $t): $img = tmdb_img($t['poster_path'], 'w342'); ?>
    <a class="pcard" href="<?= h(title_url($t)) ?>">
      <?php if ($img !== null): ?><img loading="lazy" src="<?= h($img) ?>" alt="<?= h(tf('%s का poster', $t['title'])) ?>"><?php else: ?><span class="noposter"><?= h(mb_substr($t['title'], 0, 40, 'UTF-8')) ?></span><?php endif; ?>
      <?php if ((float) $t['vote_average'] > 0): ?><span class="rate">★ <b><?= number_format((float) $t['vote_average'], 1) ?></b></span><?php endif; ?>
      <span class="ov">
        <span class="t"><?= h($t['title']) ?></span>
        <span class="m"><?= h((string) ($t['release_year'] ?? '')) ?> · <?= h(media_label($t['media_type'])) ?></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($naya !== []): ?>
<section>
  <div class="head">
    <div><span class="eyebrow"><?= OTT_LANG === 'hi' ? 'पिछले 7 दिन' : 'Last 7 days' ?></span><h2><?= h(t('इस हफ़्ते OTT पर नया आया')) ?></h2></div>
    <a class="link" href="/naya"><?= h(t('सब देखिए →')) ?></a>
  </div>
  <div class="rail">
    <?php foreach ($naya as $t): $img = tmdb_img($t['poster_path'], 'w342'); ?>
    <a class="pcard" href="<?= h(title_url($t)) ?>">
      <?php if ($img !== null): ?><img loading="lazy" src="<?= h($img) ?>" alt="<?= h(tf('%s का poster', $t['title'])) ?>"><?php else: ?><span class="noposter"><?= h(mb_substr($t['title'], 0, 40, 'UTF-8')) ?></span><?php endif; ?>
      <span class="ov">
        <span class="t"><?= h($t['title']) ?></span>
        <span class="tag g"><?= (int) $t['pkitne'] > 1 ? h(tf('%d platforms पर', (int) $t['pkitne'])) : h(tf('%s पर', $t['pname'])) ?></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($provs !== []): ?>
<section>
  <div class="head">
    <div><span class="eyebrow"><?= OTT_LANG === 'hi' ? 'कवरेज' : 'Coverage' ?></span><h2><?= h(t('टॉप OTT platforms')) ?></h2>
      <p class="dim"><?= h(t('असली title-गिनती और इस हफ़्ते की हलचल — रोज़ अपडेट।')) ?></p></div>
    <a class="link" href="/"><?= h(tf('सभी %d platforms →', (int) ($stats['platforms'] ?? count($provs)))) ?></a>
  </div>
  <div class="pgrid">
    <?php foreach (array_slice($provs, 0, 8) as $p):
      $pid = $prov_ids[$p['slug']] ?? 0; $n7 = $naya7[$pid] ?? 0; $lg = tmdb_img($p['logo_path'], 'w92'); ?>
    <a class="gcard" href="<?= h(provider_url($p)) ?>">
      <div class="top">
        <?php if ($lg !== null): ?><img class="lg" style="object-fit:cover" src="<?= h($lg) ?>" alt=""><?php else: ?><span class="lg" style="background:linear-gradient(135deg,var(--blue),var(--pink))"><?= h(mb_substr($p['name'], 0, 2, 'UTF-8')) ?></span><?php endif; ?>
        <span><b><?= h($p['name']) ?></b><span class="sub"><?= OTT_LANG === 'hi' ? 'भारत · आज अपडेट' : 'India · updated today' ?></span></span>
      </div>
      <div class="rows">
        <div><div class="k">Titles</div><div class="v"><?= (int) $p['kitne'] ?></div></div>
        <div><div class="k"><?= OTT_LANG === 'hi' ? '7 दिन में' : 'in 7 days' ?></div><div class="v <?= $n7 > 0 ? 'up' : '' ?>"><?= $n7 > 0 ? '+' . $n7 : '—' ?></div></div>
      </div>
      <span class="cta"><?= h(t('देखें →')) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($most_added !== [] || $top_langs !== []): ?>
<section>
  <div class="head">
    <div><span class="eyebrow"><?= OTT_LANG === 'hi' ? 'इंटेलिजेंस' : 'Intelligence' ?></span><h2><?= h(t('एक नज़र में OTT बाज़ार')) ?></h2>
      <p class="dim"><?= h(t('पूरी तरह हमारे अपने ट्रैक किए डेटा से — यह संख्या कहीं और नहीं मिलेगी।')) ?></p></div>
  </div>
  <div class="grid2">
    <?php if ($most_added !== []): $mx = max(array_map(fn($r) => (int) $r['n'], $most_added)) ?: 1; ?>
    <div class="panel">
      <div class="ph"><h3><?= h(t('इस महीने सबसे ज़्यादा जुड़ीं')) ?></h3><span class="t"><?= OTT_LANG === 'hi' ? 'platform-वार' : 'by platform' ?></span></div>
      <div class="bars">
        <?php foreach ($most_added as $r): ?>
        <div class="barrow"><span class="nm"><?= h($r['name']) ?></span>
          <span class="track"><span class="fill" style="width:<?= (int) round((int) $r['n'] / $mx * 100) ?>%"></span></span>
          <span class="val"><?= (int) $r['n'] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($top_langs !== []): $lmx = max(array_map(fn($r) => (int) $r['n'], $top_langs)) ?: 1; ?>
    <div class="panel">
      <div class="ph"><h3><?= h(t('ट्रेंडिंग भाषाएँ')) ?></h3><span class="t"><?= OTT_LANG === 'hi' ? 'अभी उपलब्ध' : 'available now' ?></span></div>
      <div class="bars">
        <?php foreach ($top_langs as $r): ?>
        <div class="barrow"><span class="nm"><?= h(lang_label($r['lang_code'])) ?></span>
          <span class="track"><span class="fill" style="width:<?= (int) round((int) $r['n'] / $lmx * 100) ?>%"></span></span>
          <span class="val"><?= (int) $r['n'] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<script>
(function(){
  var rm = matchMedia('(prefers-reduced-motion:reduce)').matches;
  function up(el){var to=+el.dataset.to; if(rm){el.textContent=to.toLocaleString('en-IN');return;}
    var t0=null,d=1000; function s(t){t0=t0||t;var p=Math.min(1,(t-t0)/d);
      el.textContent=Math.round(to*(1-Math.pow(1-p,3))).toLocaleString('en-IN');
      if(p<1)requestAnimationFrame(s);} requestAnimationFrame(s);}
  var io=new IntersectionObserver(function(es,o){es.forEach(function(e){if(e.isIntersecting){
    up(e.target);o.unobserve(e.target);}});},{threshold:.4});
  document.querySelectorAll('.istat .n').forEach(function(el){io.observe(el);});
})();
</script>
<?php page_footer(); ?>
