<?php
/**
 * CURATED LIST — /list/{slug}  (top-rated · hidden-gems · trending · new)
 * पूरी तरह auto: DB से हिसाब, कोई manual डेटा नहीं। genre.php का ही सलीक़ा।
 * index.php से: $want_slug
 */
declare(strict_types=1);

$country  = $CFG['country'] ?? 'IN';
$per_page = 40;
$L        = OTT_LANG === 'hi';

// सिर्फ़ अभी उपलब्ध titles (thin से बचाव)
$AV = "JOIN availability a ON a.title_id = t.id AND a.is_current = 1
       AND a.country = ? AND a.offer_type IN ('flatrate','ads','free')";

$lists = [
    'top-rated' => [
        'h' => $L ? 'सबसे बढ़िया रेटिंग' : 'Top rated',
        'd' => $L ? 'दर्शकों की सबसे ऊँची रेटिंग वाली फिल्में और सीरीज़ — जो अभी भारत में OTT पर हैं।'
                  : 'The highest-rated movies and shows on OTT in India right now.',
        'where' => 't.vote_count >= 50', 'order' => 't.vote_average DESC, t.vote_count DESC',
    ],
    'hidden-gems' => [
        'h' => $L ? 'छुपे रत्न' : 'Hidden gems',
        'd' => $L ? 'बढ़िया रेटिंग पर कम चर्चित — शानदार पर छूट गई फिल्में/सीरीज़, अभी OTT पर।'
                  : 'Highly rated but under-the-radar — great titles you may have missed, on OTT now.',
        'where' => 't.vote_average >= 7 AND t.vote_count BETWEEN 20 AND 400', 'order' => 't.vote_average DESC, t.popularity DESC',
    ],
    'trending' => [
        'h' => $L ? 'अभी ट्रेंडिंग' : 'Trending now',
        'd' => $L ? 'इस समय भारत में सबसे ज़्यादा देखी-खोजी जा रहीं — अभी OTT पर।'
                  : 'Most-watched and searched right now in India — on OTT.',
        'where' => '1', 'order' => 't.popularity DESC',
    ],
    'new' => [
        'h' => $L ? 'नई रिलीज़' : 'New releases',
        'd' => $L ? 'हाल में रिलीज़ हुई फिल्में और सीरीज़ जो अभी OTT पर उपलब्ध हैं।'
                  : 'Recently released movies and shows available on OTT now.',
        'where' => 't.release_year >= (YEAR(CURDATE()) - 1)', 'order' => 't.release_year DESC, t.popularity DESC',
    ],
];

$slug = $want_slug ?? '';
if (!isset($lists[$slug])) {
    not_found();
}
$def  = $lists[$slug];
$page = max(1, (int) ($_GET['page'] ?? 1));
$base = '/list/' . $slug;

$total = (int) scalar($PDO, "SELECT COUNT(DISTINCT t.id) FROM titles t $AV WHERE {$def['where']}", [$country]);
$pages = max(1, (int) ceil($total / $per_page));
$page  = min($page, $pages);

$titles = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.vote_average
      FROM titles t $AV
     WHERE {$def['where']}
     ORDER BY {$def['order']}
     LIMIT $per_page OFFSET " . (($page - 1) * $per_page), [$country]);

$crumbs = [
    ['name' => $L ? 'होम' : 'Home', 'url' => '/'],
    ['name' => $L ? 'ब्राउज़' : 'Browse', 'url' => '/browse'],
    ['name' => $def['h'], 'url' => $base],
];
page_header([
    'title'       => $def['h'] . ($L ? ' — OTT पर, भारत' : ' — on OTT in India'),
    'description' => $def['d'],
    'canonical'   => $base,
    'noindex'     => $page > 1 || $total < 3,
    'breadcrumb'  => $crumbs,
    'jsonld'      => ['@context' => 'https://schema.org', '@type' => 'CollectionPage',
                      'name' => $def['h'], 'url' => 'https://ottguru.in' . $base],
]);
crumbs($crumbs);
?>

<div class="phead" style="padding-bottom:16px">
  <span class="eyebrow"><?= $L ? 'सूची' : 'List' ?></span>
  <h1 style="margin:4px 0 6px"><?= h($def['h']) ?> <span class="dim" style="font-weight:600;font-size:.6em">(<?= $total ?>)</span></h1>
  <p class="dim" style="max-width:64ch;margin:0"><?= h($def['d']) ?></p>
</div>

<div class="chips" style="margin-bottom:16px">
  <?php foreach ($lists as $s => $l): ?><a class="chip <?= $s === $slug ? 'on' : '' ?>" href="/list/<?= h($s) ?>"><?= h($l['h']) ?></a><?php endforeach; ?>
</div>

<?php if ($titles === []): ?>
  <div class="offer-none"><?= h(t('इस चुनाव में अभी कुछ नहीं मिला।')) ?></div>
<?php else: ?>
  <?php render_title_grid($titles); ?>
<?php endif; ?>

<?php if ($pages > 1): ?>
<div class="pager">
  <?php $qs = fn (int $p) => $base . ($p > 1 ? '?page=' . $p : ''); ?>
  <?php if ($page > 1): ?><a href="<?= h($qs($page - 1)) ?>"><?= h(t('← पिछला')) ?></a><?php endif; ?>
  <span class="pageno"><?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= h($qs($page + 1)) ?>"><?= h(t('अगला →')) ?></a><?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
