<?php
/**
 * GENRES HUB — /genres  (सारी श्रेणियाँ एक जगह; internal-linking + SEO)
 * पूरी तरह auto (DB से)। हर श्रेणी → /genre/{slug}
 */
declare(strict_types=1);

$country = $CFG['country'] ?? 'IN';
$L = OTT_LANG === 'hi';

$genres = [];
try {
    $genres = all($PDO, "
        SELECT g.slug, g.name_en, COUNT(DISTINCT t.id) n
          FROM title_genres tg
          JOIN genres g ON g.id = tg.genre_id
          JOIN titles t ON t.id = tg.title_id
          JOIN availability a ON a.title_id = t.id AND a.is_current = 1
                             AND a.country = ? AND a.offer_type IN ('flatrate','ads','free')
         GROUP BY g.id HAVING n >= 3 ORDER BY n DESC", [$country]);
} catch (Throwable $e) { /* genres table अभी नहीं */ }

$crumbs = [
    ['name' => $L ? 'होम' : 'Home', 'url' => '/'],
    ['name' => $L ? 'श्रेणियाँ' : 'Genres', 'url' => '/genres'],
];
page_header([
    'title'       => $L ? 'श्रेणी से खोजिए — OTT पर, भारत' : 'Browse by genre — on OTT, India',
    'description' => $L ? 'हर श्रेणी की फिल्में और वेब सीरीज़ जो अभी भारत में OTT पर हैं — Action, Drama, Thriller, Comedy और बहुत कुछ।'
                        : 'Movies and web series by genre available on OTT in India — Action, Drama, Thriller, Comedy and more.',
    'canonical'   => '/genres',
    'noindex'     => $genres === [],
    'breadcrumb'  => $crumbs,
]);
crumbs($crumbs);
?>
<div class="phead" style="padding-bottom:14px">
  <span class="eyebrow"><?= $L ? 'श्रेणियाँ' : 'Genres' ?></span>
  <h1 style="margin:4px 0 4px"><?= $L ? 'श्रेणी से खोजिए' : 'Browse by genre' ?></h1>
  <p class="dim" style="margin:0"><?= $L ? 'हर श्रेणी में वे titles जो अभी किसी OTT पर उपलब्ध हैं।' : 'Titles in each genre that are on an OTT right now.' ?></p>
</div>

<?php if ($genres === []): ?>
  <div class="offer-none"><?= $L ? 'श्रेणी-डेटा अभी तैयार हो रहा है।' : 'Genre data is being prepared.' ?></div>
<?php else: ?>
<div class="gchips">
  <?php foreach ($genres as $g): ?>
  <a class="gchip" href="/genre/<?= h(rawurlencode($g['slug'])) ?>"><?= h($g['name_en']) ?><span class="c"><?= (int) $g['n'] ?></span></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
