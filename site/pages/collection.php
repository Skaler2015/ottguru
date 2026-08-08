<?php
/**
 * COLLECTION पेज — /collection/{id}  (franchise: "सारी धूम फिल्में" आदि)
 * पूरी तरह auto (TMDB collection से)। index.php से: $want_cid (int)
 */
declare(strict_types=1);

$country = $CFG['country'] ?? 'IN';
$L = OTT_LANG === 'hi';
$cid = (int) $want_cid;

$AV = "JOIN availability a ON a.title_id = t.id AND a.is_current = 1
       AND a.country = ? AND a.offer_type IN ('flatrate','ads','free')";

$titles = [];
$cname  = '';
try {
    $titles = all($PDO, "
        SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.vote_average,
               tc.name AS cname
          FROM title_collections tc
          JOIN titles t ON t.id = tc.title_id $AV
         WHERE tc.collection_id = ?
         ORDER BY t.release_year, t.popularity DESC", [$country, $cid]);
} catch (Throwable $e) {
    $titles = [];   // title_collections table अभी नहीं
}
if ($titles !== []) {
    $cname = (string) $titles[0]['cname'];
}
if ($cname === '') {
    not_found();
}
// "The X Collection" → साफ़ शीर्षक
$clean = trim(preg_replace('/\s*(Collection|Series)\s*$/i', '', $cname)) ?: $cname;

$crumbs = [
    ['name' => $L ? 'होम' : 'Home', 'url' => '/'],
    ['name' => $clean, 'url' => '/collection/' . $cid],
];
page_header([
    'title'       => $L ? "$clean — सारी फिल्में, अभी OTT पर" : "$clean — all films, on OTT now",
    'description' => $L ? "$clean franchise की सारी फिल्में जो अभी भारत में OTT पर उपलब्ध हैं — कहाँ देखें, किस क्रम में। OTT गुरु।"
                        : "All films in the $clean franchise available on OTT in India now — where to watch, in order. OTT Guru.",
    'canonical'   => '/collection/' . $cid,
    'noindex'     => count($titles) < 2,
    'breadcrumb'  => $crumbs,
    'jsonld'      => ['@context' => 'https://schema.org', '@type' => 'CollectionPage',
                      'name' => $clean, 'url' => 'https://ottguru.in/collection/' . $cid],
]);
crumbs($crumbs);
?>
<div class="phead" style="padding-bottom:14px">
  <span class="eyebrow"><?= $L ? 'फ़्रैंचाइज़ी' : 'Franchise' ?></span>
  <h1 style="margin:4px 0 4px"><?= h($clean) ?> <span class="dim" style="font-weight:600;font-size:.55em">(<?= count($titles) ?>)</span></h1>
  <p class="dim" style="margin:0"><?= $L ? 'इस franchise की वे फिल्में जो अभी किसी OTT पर हैं — रिलीज़ के क्रम में।' : 'Films in this franchise on an OTT right now — in release order.' ?></p>
</div>
<?php render_title_grid($titles); ?>
<?php page_footer(); ?>
