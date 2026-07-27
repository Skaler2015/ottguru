<?php
/**
 * होमपेज — आँकड़े (query 10), platform की सूची, इस हफ़्ते नया, चर्चित titles।
 * यह title/provider पन्नों तक पहुँचने का दरवाज़ा है।
 */
declare(strict_types=1);

$country = $CFG['country'] ?? 'IN';

// ---- query 10: आँकड़े ---------------------------------------------------------
$stats = one($PDO, "
    SELECT
      (SELECT COUNT(*) FROM titles)                                            AS titles,
      (SELECT COUNT(*) FROM providers WHERE is_active = 1)                     AS platforms,
      (SELECT COUNT(*) FROM availability WHERE is_current = 1)                 AS abhi_uplabdh,
      (SELECT COUNT(*) FROM availability_changes)                              AS itihas,
      (SELECT COUNT(*) FROM availability_changes
        WHERE changed_on >= (CURDATE() - INTERVAL 7 DAY) AND change_type='added') AS is_hafte_naya") ?? [];

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
     LIMIT 24",
    [$country]);

// ---- इस हफ़्ते नया (query 4 का सब-platform रूप) --------------------------------
$naya = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type,
           c.changed_on, p.name AS pname
      FROM availability_changes c
      JOIN titles t    ON t.id = c.title_id
      JOIN providers p ON p.id = c.provider_id
     WHERE c.country = ?
       AND c.change_type = 'added'
       AND c.offer_type IN ('flatrate','ads','free')
       AND c.changed_on >= (CURDATE() - INTERVAL 7 DAY)
     ORDER BY c.changed_on DESC
     LIMIT 12",
    [$country]);

// ---- चर्चित titles --------------------------------------------------------------
$hot = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.popularity
      FROM availability a
      JOIN titles t ON t.id = a.title_id
     WHERE a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
     ORDER BY t.popularity DESC
     LIMIT 18",
    [$country]);

page_header([
    'canonical' => '/',
    'jsonld'    => [
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        'name'     => 'OTT Guru',
        'url'      => 'https://ottguru.in/',
        'inLanguage' => 'hi',
    ],
]);
?>

<h1>कौन सी फिल्म किस OTT पर है?</h1>
<p class="dim">Netflix, Prime Video, JioHotstar, ZEE5, SonyLIV — सब एक जगह।
   और सिर्फ़ "कहाँ है" नहीं: <b>कब आई, कहाँ-कहाँ रही, कब हटी</b> — पूरा इतिहास।</p>

<?php if ($stats !== [] && (int) ($stats['titles'] ?? 0) > 0): ?>
<div class="stats">
  <div class="stat"><b><?= h(hindi_num((int) $stats['titles'])) ?></b><span>फिल्में और सीरीज़</span></div>
  <div class="stat"><b><?= h(hindi_num((int) $stats['platforms'])) ?></b><span>OTT platforms</span></div>
  <div class="stat"><b><?= h(hindi_num((int) $stats['abhi_uplabdh'])) ?></b><span>अभी उपलब्ध</span></div>
  <div class="stat"><b><?= h(hindi_num((int) $stats['is_hafte_naya'])) ?></b><span>इस हफ़्ते नई आईं</span></div>
</div>
<?php endif; ?>

<?php if ($provs !== []): ?>
<h2>Platform चुनिए</h2>
<div class="chips">
  <?php foreach ($provs as $p): ?>
  <a class="chip" href="<?= h(provider_url($p)) ?>">
    <?php $logo = tmdb_img($p['logo_path'], 'w92'); ?>
    <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt=""><?php endif; ?>
    <?= h($p['name']) ?> <span class="dim">(<?= (int) $p['kitne'] ?>)</span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($naya !== []): ?>
<h2>इस हफ़्ते OTT पर नया आया</h2>
<div class="newrow">
  <?php foreach ($naya as $t): ?>
  <a class="card" href="<?= h(title_url($t)) ?>">
    <?php $img = tmdb_img($t['poster_path'], 'w342'); ?>
    <?php if ($img !== null): ?>
      <img loading="lazy" src="<?= h($img) ?>" alt="<?= h($t['title']) ?> का poster">
    <?php else: ?>
      <span class="noposter"><?= h(mb_substr($t['title'], 0, 40, 'UTF-8')) ?></span>
    <?php endif; ?>
    <span class="card-t"><?= h($t['title']) ?></span>
    <span class="newdate"><?= h($t['pname']) ?> पर</span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($hot !== []): ?>
<h2>अभी चर्चा में</h2>
<?php render_title_grid($hot); ?>
<?php endif; ?>

<?php page_footer(); ?>
