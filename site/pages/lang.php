<?php
/**
 * भाषा पेज — "Netflix पर हिंदी फिल्में" (queries.sql #3)
 * रास्ता: /platform/{provider}/{bhasha}-movies या .../{bhasha}-series
 *
 * ध्यान (CLAUDE.md §5): यह **फिल्म की भाषा** है, dub नहीं। "इस OTT पर हिंदी
 * ऑडियो मिलेगी या नहीं" अलग सवाल है — वो Streaming Availability जुड़ने के
 * बाद आएगा। दोनों को मिलाकर कभी नहीं दिखाना।
 *
 * thin-content से बचाव: 0 titles → 404; 5 से कम → noindex.
 *
 * index.php से मिलता है: $want_slug, $want_lang ('hi'), $lang_slug ('hindi'), $want_type
 */
declare(strict_types=1);

$prov = one($PDO, 'SELECT * FROM providers WHERE slug = ? AND is_active = 1', [$want_slug]);
if ($prov === null) {
    not_found();
}

$country  = $CFG['country'] ?? 'IN';
$per_page = 40;
$page     = max(1, (int) ($_GET['page'] ?? 1));

$total = (int) scalar($PDO, "
    SELECT COUNT(DISTINCT t.id)
      FROM availability a
      JOIN titles t          ON t.id = a.title_id
      JOIN title_languages l ON l.title_id = t.id
     WHERE a.provider_id = ?
       AND a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
       AND l.lang_code = ?
       AND t.media_type = ?",
    [(int) $prov['id'], $country, $want_lang, $want_type]);

// जिस जोड़ पर कुछ है ही नहीं, वो पन्ना है ही नहीं — Google के लिए भी, यूज़र के लिए भी
if ($total === 0) {
    not_found();
}

$pages = max(1, (int) ceil($total / $per_page));
$page  = min($page, $pages);

$titles = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type,
           t.popularity
      FROM availability a
      JOIN titles t          ON t.id = a.title_id
      JOIN title_languages l ON l.title_id = t.id
     WHERE a.provider_id = ?
       AND a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
       AND l.lang_code = ?
       AND t.media_type = ?
     ORDER BY t.popularity DESC
     LIMIT $per_page OFFSET " . (($page - 1) * $per_page),
    [(int) $prov['id'], $country, $want_lang, $want_type]);

// ---- meta ---------------------------------------------------------------------
$bhasha     = lang_label($want_lang);
$kya        = $want_type === 'tv' ? t('वेब सीरीज़ (सूची)') : t('फिल्में');
$h1         = tf('%1$s पर %2$s %3$s', $prov['name'], $bhasha, $kya);
$self       = provider_url($prov) . '/' . $lang_slug . '-' . ($want_type === 'tv' ? 'series' : 'movies');
$dusra_type = $want_type === 'tv' ? 'movies' : 'series';

// दूसरी तरफ़ (फिल्में ↔ सीरीज़) कुछ है तो ही उसका लिंक दिखाना
$dusra_total = (int) scalar($PDO, "
    SELECT COUNT(DISTINCT t.id)
      FROM availability a
      JOIN titles t          ON t.id = a.title_id
      JOIN title_languages l ON l.title_id = t.id
     WHERE a.provider_id = ?
       AND a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
       AND l.lang_code = ?
       AND t.media_type = ?",
    [(int) $prov['id'], $country, $want_lang, $want_type === 'tv' ? 'movie' : 'tv']);

page_header([
    'title'       => $h1 . ' (' . $total . ')',
    'description' => tf('%1$s India पर अभी %2$d %3$s %4$s सब्सक्रिप्शन में हैं — पूरी सूची, रोज़ अपडेट। OTT गुरु पर।',
                        $prov['name'], $total, $bhasha, $kya),
    'canonical'   => $self,
    'image'       => tmdb_img($prov['logo_path'], 'w154'),
    'noindex'     => $total < 5 || $page > 1,   // बहुत छोटा या paginated पन्ना index न हो
    'jsonld'      => [
        '@context' => 'https://schema.org',
        '@type'    => 'CollectionPage',
        'name'     => $h1,
        'url'      => 'https://ottguru.in' . $self,
    ],
]);
?>

<div class="phead">
  <div class="phead-top">
    <?php $logo = tmdb_img($prov['logo_path'], 'w154'); ?>
    <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt="<?= h($prov['name']) ?> logo"><?php else: ?><span class="lg"><?= h(mb_substr($prov['name'], 0, 2, 'UTF-8')) ?></span><?php endif; ?>
    <div>
      <h1><?= h($h1) ?></h1>
      <div class="sub"><?= tf('अभी %s · रोज़ अपडेट', '<b>' . $total . '</b>') ?></div>
    </div>
  </div>
  <div class="sublinks">
    <a href="<?= h(provider_url($prov)) ?>"><?= h(tf('%s की पूरी सूची →', $prov['name'])) ?></a>
    <a href="/naya/<?= h(rawurlencode($prov['slug'])) ?>"><?= h(t('इस हफ़्ते नया आया →')) ?></a>
  </div>
</div>

<div class="tabs">
  <span class="tabs-label dim small"><?= h($bhasha) ?>:</span>
  <a class="<?= $want_type === 'movie' ? 'on' : '' ?>"
     href="<?= h(provider_url($prov) . '/' . $lang_slug . '-movies') ?>"><?= h(t('फिल्में (tab)')) ?></a>
  <?php if ($dusra_total > 0 || $want_type === 'tv'): ?>
  <a class="<?= $want_type === 'tv' ? 'on' : '' ?>"
     href="<?= h(provider_url($prov) . '/' . $lang_slug . '-series') ?>"><?= h(t('वेब सीरीज़ (tab)')) ?></a>
  <?php endif; ?>
</div>

<?php render_title_grid($titles); ?>

<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a href="<?= h($self . ($page - 1 > 1 ? '?page=' . ($page - 1) : '')) ?>"><?= h(t('← पिछला')) ?></a><?php endif; ?>
  <span class="here"><?= h(tf('पन्ना %d / %d', $page, $pages)) ?></span>
  <?php if ($page < $pages): ?><a href="<?= h($self . '?page=' . ($page + 1)) ?>"><?= h(t('अगला →')) ?></a><?php endif; ?>
</div>
<?php endif; ?>

<div class="note">
  <?= h(tf('यह सूची %1$s में बनी %2$s दिखाती है (मूल या बोली गई भाषा)। "%3$s पर %1$s ऑडियो (dub) मिलेगी या नहीं" — वह जानकारी अलग है और जल्द जुड़ेगी।',
      $bhasha, $kya, $prov['name'])) ?>
</div>

<?php page_footer(); ?>
