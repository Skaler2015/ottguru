<?php
/**
 * CHANGES पेज — "क्या आया, क्या हटा"। यही बार-बार ट्रैफ़िक लाएगा।
 *   /naya            सब platforms पर इस हफ़्ते नया  (queries.sql #4 का सब-platform रूप)
 *   /naya/netflix    सिर्फ़ Netflix पर नया
 *   /hata            हाल में क्या हटा (30 दिन)      (queries.sql #5)
 *   /hata/netflix    सिर्फ़ Netflix से हटा
 * /hata पर हर title के आगे "अब कहाँ है" भी दिखता है — यह हमारे ही डेटा से
 * निकलता है, JustWatch पर यह नहीं मिलता।
 * /naya (सब-platform) पर query #7 की "platform बदलने वाली" कहानियाँ भी हैं।
 *
 * index.php से मिलता है: $want_mode ('added'|'removed'), $want_slug (या null)
 */
declare(strict_types=1);

$country  = $CFG['country'] ?? 'IN';
$is_added = $want_mode === 'added';
$days     = $is_added ? 7 : 30;   // queries.sql #4 और #5 की खिड़कियाँ

$prov = null;
if ($want_slug !== null) {
    $prov = one($PDO, 'SELECT * FROM providers WHERE slug = ? AND is_active = 1', [$want_slug]);
    if ($prov === null) {
        not_found();
    }
}

$prov_sql  = $prov !== null ? ' AND c.provider_id = ? ' : '';
$prov_args = $prov !== null ? [(int) $prov['id']] : [];

// added पर offer की शर्त (#4 की तरह); removed पर नहीं (#5 की तरह) —
// किराये से हटना भी हटना ही है
$offer_sql = $is_added ? " AND c.offer_type IN ('flatrate','ads','free') " : '';

// एक title एक ही दिन कई platforms पर आए/हटे तो card एक ही बने —
// GROUP BY से platforms की गिनती साथ आती है (Uncharted 5 बार नहीं दिखेगी)
$rows = all($PDO, "
    SELECT t.id AS tid, t.slug, t.title, t.release_year, t.poster_path,
           t.media_type, c.changed_on,
           MIN(p.name) AS pname,
           COUNT(DISTINCT c.provider_id) AS pkitne
      FROM availability_changes c
      JOIN titles t    ON t.id = c.title_id
      JOIN providers p ON p.id = c.provider_id
     WHERE c.country = ?
       AND c.change_type = ?
       $offer_sql
       $prov_sql
       AND c.changed_on >= (CURDATE() - INTERVAL $days DAY)
     GROUP BY t.id, c.changed_on
     ORDER BY c.changed_on DESC, MAX(t.popularity) DESC
     LIMIT 200",
    array_merge([$country, $want_mode], $prov_args));

// ---- /hata: हटे titles अब कहाँ हैं — एक ही query में सबके लिए ----------------
$ab_kahan = [];
if (!$is_added && $rows !== []) {
    $ids = array_values(array_unique(array_map(fn ($r) => (int) $r['tid'], $rows)));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (all($PDO, "
        SELECT a.title_id, p.name, p.slug
          FROM availability a
          JOIN providers p ON p.id = a.provider_id
         WHERE a.title_id IN ($in)
           AND a.country = ?
           AND a.is_current = 1
           AND a.offer_type IN ('flatrate','ads','free')
         ORDER BY p.display_priority",
        array_merge($ids, [$country])) as $r) {
        $ab_kahan[(int) $r['title_id']][] = $r;
    }
}

// ---- /naya (सब-platform): query #7 — एक ही दिन एक से हटी, दूसरे पर आई ---------
$badli = [];
if ($is_added && $prov === null) {
    $badli = all($PDO, "
        SELECT t.slug, t.title, t.media_type, t.release_year,
               pOut.name AS gaya_yahan_se, pIn.name AS aaya_yahan, c1.changed_on
          FROM availability_changes c1
          JOIN availability_changes c2
               ON c2.title_id = c1.title_id
              AND c2.changed_on = c1.changed_on
              AND c2.change_type = 'added'
              AND c2.provider_id <> c1.provider_id
          JOIN titles t       ON t.id = c1.title_id
          JOIN providers pOut ON pOut.id = c1.provider_id
          JOIN providers pIn  ON pIn.id  = c2.provider_id
         WHERE c1.change_type = 'removed'
           AND c1.country = ?
           AND c1.offer_type IN ('flatrate','ads','free')
           AND c2.offer_type IN ('flatrate','ads','free')
           AND c1.changed_on >= (CURDATE() - INTERVAL 60 DAY)
         ORDER BY c1.changed_on DESC
         LIMIT 30",
        [$country]);
}

// ---- तारीख़ के हिसाब से गुच्छे — "22 जुलाई 2026" के नीचे उस दिन की सारी ---------
$by_date = [];
foreach ($rows as $r) {
    $by_date[$r['changed_on']][] = $r;
}

// ---- meta ---------------------------------------------------------------------
$pname = $prov !== null ? $prov['name'] : null;
if ($is_added) {
    $h1   = $pname !== null ? tf('%s पर इस हफ़्ते नया आया', $pname) : t('इस हफ़्ते OTT पर नया आया');
    $desc = tf('%s पर पिछले %d दिनों में कौन सी फिल्में और वेब सीरीज़ नई आईं — रोज़ अपडेट, OTT गुरु पर।',
        $pname ?? 'Netflix, Prime Video, JioHotstar, ZEE5, SonyLIV', $days);
} else {
    $h1   = $pname !== null ? tf('%s से हाल में क्या हटा', $pname) : t('OTT से हाल में क्या हटा');
    $desc = tf('%s से पिछले %d दिनों में कौन सी फिल्में हटीं और अब कहाँ देख सकते हैं — OTT गुरु पर।',
        $pname ?? 'OTT platforms', $days);
}

$self = '/' . ($is_added ? 'naya' : 'hata') . ($prov !== null ? '/' . $prov['slug'] : '');

page_header([
    'title'       => $h1,
    'description' => $desc,
    'canonical'   => $self,
    'noindex'     => $rows === [],   // ख़ाली पन्ना index न हो — thin content
    'jsonld'      => [
        '@context' => 'https://schema.org',
        '@type'    => 'CollectionPage',
        'name'     => $h1,
        'url'      => 'https://ottguru.in' . $self,
    ],
]);
?>

<div style="margin-top:8px">
  <span class="eyebrow"><?= h($is_added ? t('नया आया') : t('क्या हटा')) ?> · <?= h(tf('पिछले %d दिन', $days)) ?></span>
  <h1 style="margin-top:8px"><?= h($h1) ?></h1>
  <p class="dim" style="margin-top:8px">
    <?php if ($prov !== null): ?>
      <a href="<?= h(provider_url($prov)) ?>"><?= h(tf('%s पर पूरी सूची →', $prov['name'])) ?></a>
    <?php else: ?>
      <?= h(t('उपलब्धता रोज़ जाँची जाती है')) ?>
    <?php endif; ?>
  </p>
</div>

<?php if ($rows === []): ?>
  <div class="offer-none" style="margin-top:16px">
    <?= h(tf('पिछले %d दिनों में यहाँ कोई बदलाव दर्ज नहीं हुआ।', $days)) ?>
    <a href="/"><?= h(t('होमपेज पर चलिए')) ?></a>
  </div>
<?php endif; ?>

<?php foreach ($by_date as $date => $din_ke): ?>
<section>
  <div class="head"><div>
    <span class="eyebrow"><?= h(hindi_date($date)) ?></span>
    <h2 style="font-size:19px"><?= $is_added ? h(tf('%d जुड़ीं', count($din_ke))) : h(tf('%d हटीं', count($din_ke))) ?></h2>
  </div></div>
  <div class="rail">
    <?php foreach ($din_ke as $t): $img = tmdb_img($t['poster_path'], 'w342');
      $pjagah = (int) $t['pkitne'] > 1 ? tf('%d platforms', (int) $t['pkitne']) : $t['pname']; ?>
    <a class="pcard" href="<?= h(title_url($t)) ?>">
      <?php if ($img !== null): ?><img loading="lazy" src="<?= h($img) ?>" alt="<?= h(tf('%s का poster', $t['title'])) ?>"><?php else: ?><span class="noposter"><?= h(mb_substr($t['title'], 0, 40, 'UTF-8')) ?></span><?php endif; ?>
      <span class="ov">
        <span class="t"><?= h($t['title']) ?></span>
        <?php if ($is_added): ?>
          <span class="tag g"><?= h(tf('%s पर आई', $pjagah)) ?></span>
        <?php else: ?>
          <span class="tag p"><?= h(tf('%s से हटी', $pjagah)) ?></span>
          <?php $ab = $ab_kahan[(int) $t['tid']] ?? []; ?>
          <?php if ($ab !== []): ?>
            <span class="tag g"><?= h(tf('अब %s पर', implode(', ', array_column($ab, 'name')))) ?></span>
          <?php else: ?>
            <span class="m"><?= h(t('अभी कहीं और नहीं')) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<?php if ($badli !== []): ?>
<section>
  <div class="head"><div>
    <span class="eyebrow"><?= OTT_LANG === 'hi' ? 'platform बदलीं' : 'switched platform' ?></span>
    <h2><?= h(t('एक platform से दूसरे पर गईं')) ?></h2>
    <p class="dim"><?= h(t('एक ही दिन एक जगह से हटीं और दूसरी पर आ गईं — plan बदलने से पहले यह देख लीजिए।')) ?></p>
  </div></div>
  <div class="panel">
    <ul class="history" style="margin:0;border-left-color:var(--line2)">
      <?php foreach ($badli as $b): ?>
      <li class="now">
        <span class="h-when"><?= h(hindi_date($b['changed_on'])) ?></span> —
        <a href="<?= h(title_url($b)) ?>"><b><?= h($b['title']) ?></b></a>:
        <?= tf('%1$s से %2$s पर', h($b['gaya_yahan_se']), '<b>' . h($b['aaya_yahan']) . '</b>') ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<?php if ($prov === null): ?>
<div class="note">
  <?= h(t('किसी एक platform का हिसाब चाहिए? platform के पन्ने पर "नया आया / हटा" के लिंक हैं —')) ?>
  <a href="/"><?= h(t('होमपेज से platform चुनिए')) ?></a>
</div>
<?php endif; ?>

<?php page_footer(); ?>
