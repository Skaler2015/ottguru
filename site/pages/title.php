<?php
/**
 * TITLE पन्ना — "यह फिल्म कहाँ देखें" + उपलब्धता का पूरा इतिहास
 * रास्ता: /movie/{slug} या /series/{slug}
 * queries.sql की query 1 (अभी कहाँ है) और 6 (इतिहास) यहीं चलती हैं।
 *
 * index.php से मिलता है: $want_slug, $want_type
 */
declare(strict_types=1);

$title = one($PDO, 'SELECT * FROM titles WHERE slug = ?', [$want_slug]);
if ($title === null) {
    not_found();
}

// slug सही पर media_type का prefix गलत (movie की जगह series) → canonical पर भेजो।
// slug कभी बदलता नहीं, इसलिए यह redirect हमेशा सही रहेगा।
if ($title['media_type'] !== $want_type) {
    header('Location: ' . title_url($title), true, 301);
    exit;
}

$country = $CFG['country'] ?? 'IN';

// ---- query 1: अभी कहाँ है --------------------------------------------------
$offers = all($PDO, "
    SELECT p.id AS provider_id, p.slug, p.name, p.logo_path, a.offer_type, a.watch_link, a.first_seen
      FROM availability a
      JOIN providers p ON p.id = a.provider_id
     WHERE a.title_id = ?
       AND a.country = ?
       AND a.is_current = 1
     ORDER BY FIELD(a.offer_type, 'flatrate','ads','free','rent','buy'),
              p.display_priority",
    [(int) $title['id'], $country]);

$stream = array_values(array_filter($offers, fn ($o) => in_array($o['offer_type'], ['flatrate','ads','free'], true)));
$paisa  = array_values(array_filter($offers, fn ($o) => in_array($o['offer_type'], ['rent','buy'], true)));

// ---- query 6: इतिहास (spells + घटनाएँ) --------------------------------------
$spells = all($PDO, "
    SELECT p.name, p.slug AS pslug, a.offer_type, a.first_seen, a.last_seen, a.is_current
      FROM availability a
      JOIN providers p ON p.id = a.provider_id
     WHERE a.title_id = ? AND a.country = ?
     ORDER BY a.is_current DESC, a.first_seen",
    [(int) $title['id'], $country]);

$events = all($PDO, "
    SELECT c.changed_on, c.change_type, p.name, c.offer_type
      FROM availability_changes c
      JOIN providers p ON p.id = c.provider_id
     WHERE c.title_id = ? AND c.country = ?
     ORDER BY c.changed_on DESC, c.id DESC",
    [(int) $title['id'], $country]);

// ---- फिल्म की भाषाएँ (यह dub नहीं है — CLAUDE.md §5) -------------------------
// जो भाषा original भी है और spoken भी, वो एक ही बार दिखे — (मूल) के साथ
$langs    = all($PDO, "
    SELECT lang_code, kind FROM title_languages
     WHERE title_id = ? ORDER BY kind = 'original' DESC, lang_code",
    [(int) $title['id']]);
$original = array_column(array_filter($langs, fn ($l) => $l['kind'] === 'original'), 'lang_code');
$langs    = array_values(array_filter($langs,
    fn ($l) => $l['kind'] === 'original' || !in_array($l['lang_code'], $original, true)));

// ---- अतिरिक्त TMDB मेटाडेटा (genre, cast/crew, trailer, certification) --------
// नई tables से; अगर सर्वर पर अभी migrate नहीं चला (tables नहीं बनीं) तो पन्ना
// बिना इनके भी पूरा चलता है — इसलिए try/catch में। sync भरने के बाद अपने-आप दिखेंगे।
$tid_i  = (int) $title['id'];
$genres = $cast = $crew = $videos = [];
$meta   = null;
try {
    $genres = all($PDO, "SELECT g.name_en, g.slug FROM title_genres tg
                          JOIN genres g ON g.id = tg.genre_id
                         WHERE tg.title_id = ? ORDER BY g.name_en", [$tid_i]);
    $cast   = all($PDO, "SELECT p.id, p.name, p.profile_path, tc.role FROM title_credits tc
                          JOIN people p ON p.id = tc.person_id
                         WHERE tc.title_id = ? AND tc.credit_kind = 'cast'
                         ORDER BY tc.ord", [$tid_i]);
    $crew   = all($PDO, "SELECT p.id, p.name, tc.role FROM title_credits tc
                          JOIN people p ON p.id = tc.person_id
                         WHERE tc.title_id = ? AND tc.credit_kind = 'crew'
                         ORDER BY tc.ord", [$tid_i]);
    $videos = all($PDO, "SELECT yt_key, name, kind FROM title_videos
                         WHERE title_id = ? ORDER BY ord", [$tid_i]);
    $meta   = one($PDO, "SELECT certification, tagline, digital_date FROM title_meta
                         WHERE title_id = ?", [$tid_i]);
} catch (Throwable $e) {
    // नई tables अभी मौजूद नहीं — कोई बात नहीं, बाक़ी पन्ना ज्यों का त्यों
}

// collection/franchise (Feature 7) — table/डेटा न हो तो null
$collection = null;
try {
    $collection = one($PDO, "SELECT collection_id, name FROM title_collections WHERE title_id = ?", [$tid_i]);
} catch (Throwable $e) { /* title_collections अभी नहीं */ }

// ---- dub/ऑडियो भाषा — किस OTT पर कौन सी (Streaming Availability से) ----------
// ⚠️ यह फिल्म की भाषा (title_languages) से अलग है — CLAUDE.md §5, कभी मिलाना नहीं।
// सिर्फ़ तभी दिखेगा जब असली डेटा भरा हो (SA चालू + परखा) — कोई झूठा दावा नहीं।
$dubByProv = [];
try {
    foreach (all($PDO, "SELECT p.name, pa.lang_code FROM provider_audio pa
                          JOIN providers p ON p.id = pa.provider_id
                          JOIN availability a ON a.provider_id = p.id AND a.title_id = pa.title_id AND a.is_current = 1
                         WHERE pa.title_id = ?
                         ORDER BY p.display_priority, pa.lang_code", [$tid_i]) as $r) {
        $dubByProv[$r['name']][] = $r['lang_code'];
    }
} catch (Throwable $e) {
    // provider_audio अभी नहीं — कोई बात नहीं
}

// ---- मैन्युअल डेटा: इस title जिन OTT पर है उनका सबसे सस्ता plan + telecom बंडल --
// (§1 — कोई API नहीं देता।) provider_id पर keyed; try/catch — tables/डेटा न हों तो चुप।
$planMin = [];    // provider_id => ['price'=>, 'name'=>, 'tv_allowed'=>]
$bundleBy = [];   // provider_id => [ ['operator'=>, 'plan_price'=>], … ]
$provIds = array_values(array_unique(array_map(fn ($o) => (int) $o['provider_id'], $offers)));
if ($provIds !== []) {
    $in = implode(',', array_fill(0, count($provIds), '?'));
    try {
        // हर provider का सबसे सस्ता plan (एक ही row) — कीमत, नाम, TV allowed
        foreach (all($PDO, "SELECT pp.provider_id, pp.name, pp.price_inr, pp.period, pp.tv_allowed
                              FROM provider_plans pp
                              JOIN (SELECT provider_id, MIN(price_inr) mp FROM provider_plans
                                     WHERE provider_id IN ($in) GROUP BY provider_id) m
                                ON m.provider_id = pp.provider_id AND m.mp = pp.price_inr
                             WHERE pp.provider_id IN ($in)", array_merge($provIds, $provIds)) as $r) {
            if (!isset($planMin[(int) $r['provider_id']])) {
                $planMin[(int) $r['provider_id']] = $r;
            }
        }
        foreach (all($PDO, "SELECT provider_id, operator, plan_price FROM telecom_bundles
                             WHERE provider_id IN ($in) ORDER BY plan_price", $provIds) as $r) {
            $bundleBy[(int) $r['provider_id']][] = $r;
        }
    } catch (Throwable $e) {
        // provider_plans / telecom_bundles अभी नहीं — कोई बात नहीं
    }
}

$directors = array_values(array_filter($crew, fn ($c) => $c['role'] === 'Director'));
$writers   = array_values(array_filter($crew,
    fn ($c) => in_array($c['role'], ['Writer', 'Screenplay', 'Story', 'Creator'], true)));
$trailer   = $videos[0] ?? null;
$cert      = nz((string) ($meta['certification'] ?? ''));

// ---- सिफ़ारिशें: मिलती-जुलती (शेयर्ड genres) + इसी director की ------------------
// सिर्फ़ वे जो अभी OTT पर हैं (देखने लायक़ + thin नहीं)। tables/डेटा न हों तो खाली।
$AVX = "EXISTS (SELECT 1 FROM availability a WHERE a.title_id = t.id AND a.is_current = 1
        AND a.country = ? AND a.offer_type IN ('flatrate','ads','free'))";
$similar = $byDirector = [];
try {
    if ($genres !== []) {
        $similar = all($PDO, "
            SELECT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.vote_average,
                   t.popularity, COUNT(*) AS shared
              FROM title_genres tg
              JOIN title_genres tg2 ON tg2.genre_id = tg.genre_id AND tg2.title_id <> tg.title_id
              JOIN titles t ON t.id = tg2.title_id
             WHERE tg.title_id = ? AND t.media_type = ? AND $AVX
             GROUP BY t.id
             ORDER BY shared DESC, t.popularity DESC
             LIMIT 12", [$tid_i, $title['media_type'], $country]);
    }
    $dirIds = array_values(array_unique(array_map(fn ($c) => (int) $c['id'], $directors)));
    if ($dirIds !== []) {
        $in = implode(',', array_fill(0, count($dirIds), '?'));
        $byDirector = all($PDO, "
            SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type,
                   t.vote_average, t.popularity
              FROM title_credits tc
              JOIN titles t ON t.id = tc.title_id
             WHERE tc.person_id IN ($in) AND tc.credit_kind = 'crew'
               AND tc.role IN ('Director','Creator') AND t.id <> ? AND $AVX
             ORDER BY t.popularity DESC
             LIMIT 12", array_merge($dirIds, [$tid_i, $country]));
    }
} catch (Throwable $e) {
    // नई tables अभी नहीं — सिफ़ारिशें छोड़ दो
}

// ---- meta / schema.org -------------------------------------------------------
$is_tv    = $title['media_type'] === 'tv';
$year     = nz((string) ($title['release_year'] ?? '')) ;
$h1       = $title['title'] . ($year !== null ? ' (' . $year . ')' : '');
$now_on   = array_values(array_unique(array_map(fn ($o) => $o['name'], $stream)));

if ($now_on !== []) {
    $desc = tf(
        '%s अभी %s पर देखी जा सकती है। कब आई, कहाँ-कहाँ रही — पूरा इतिहास OTT गुरु पर।',
        $title['title'],
        implode(', ', array_slice($now_on, 0, 3))
    );
} else {
    $desc = tf('%s अभी भारत में किसी OTT के सब्सक्रिप्शन में नहीं है। यह पहले कहाँ थी और कब हटी — पूरा इतिहास OTT गुरु पर।', $title['title']);
}

$node = [
    '@type' => $is_tv ? 'TVSeries' : 'Movie',
    'name'  => $title['title'],
    'url'   => 'https://ottguru.in' . title_url($title),
];
if (nz($title['original_title'] ?? null) !== null && $title['original_title'] !== $title['title']) {
    $node['alternateName'] = $title['original_title'];
}
if (nz($title['overview'] ?? null) !== null) {
    $node['description'] = $title['overview'];
}
if (nz($title['release_date'] ?? null) !== null) {
    $node['datePublished'] = $title['release_date'];
}
if (tmdb_img($title['poster_path'], 'w500') !== null) {
    $node['image'] = tmdb_img($title['poster_path'], 'w500');
}
if ((float) $title['vote_average'] > 0 && (int) $title['vote_count'] >= 10) {
    $node['aggregateRating'] = [
        '@type' => 'AggregateRating', 'ratingValue' => (float) $title['vote_average'],
        'bestRating' => 10, 'ratingCount' => (int) $title['vote_count'],
    ];
}
// नया मेटाडेटा — schema को असली डेटा से और मज़बूत करता है
if ($genres !== []) {
    $node['genre'] = array_map(fn ($g) => $g['name_en'], $genres);
}
if ($cast !== []) {
    $node['actor'] = array_map(fn ($c) => ['@type' => 'Person', 'name' => $c['name']],
        array_slice($cast, 0, 10));
}
if ($directors !== []) {
    $node['director'] = array_map(fn ($c) => ['@type' => 'Person', 'name' => $c['name']], $directors);
}
if ($cert !== null) {
    $node['contentRating'] = $cert;
}
if ($trailer !== null) {
    $thumb = tmdb_img($title['backdrop_path'] ?? null, 'w780') ?? tmdb_img($title['poster_path'], 'w500');
    $node['trailer'] = array_filter([
        '@type'        => 'VideoObject',
        'name'         => nz((string) ($trailer['name'] ?? '')) ?? ($title['title'] . ' Trailer'),
        'description'  => $title['title'],
        'embedUrl'     => 'https://www.youtube.com/embed/' . $trailer['yt_key'],
        'thumbnailUrl' => $thumb,
    ]);
}

// ---- FAQ — सिर्फ़ हमारे डेटा से (visible section + FAQPage schema, दोनों एक स्रोत) ----
$paisaNames = array_values(array_unique(array_map(fn ($o) => $o['name'], $paisa)));
$faqs = [];
$faqs[] = [
    'q' => tf('%s कहाँ देखें?', $title['title']),
    'a' => $now_on !== []
        ? tf('%s अभी %s पर सब्सक्रिप्शन में उपलब्ध है।', $title['title'], implode(', ', $now_on))
          . ($paisaNames !== [] ? ' ' . tf('किराये/ख़रीद पर भी: %s।', implode(', ', $paisaNames)) : '')
        : tf('%s अभी भारत में किसी OTT सब्सक्रिप्शन पर उपलब्ध नहीं है।', $title['title']),
];
if ($now_on !== []) {
    $faqs[] = ['q' => tf('क्या %s %s पर है?', $title['title'], $now_on[0]),
               'a' => tf('हाँ, %s अभी %s पर देखी जा सकती है।', $title['title'], $now_on[0])];
}
if (nz($title['release_date'] ?? null) !== null) {
    $faqs[] = ['q' => tf('%s कब रिलीज़ हुई?', $title['title']),
               'a' => tf('%s %s को रिलीज़ हुई थी।', $title['title'], hindi_date($title['release_date']))];
}
if (!$is_tv && (int) ($title['runtime'] ?? 0) > 0) {
    $faqs[] = ['q' => tf('%s की अवधि कितनी है?', $title['title']),
               'a' => tf('%s की अवधि लगभग %d मिनट है।', $title['title'], (int) $title['runtime'])];
}
if ($langs !== []) {
    $faqs[] = ['q' => tf('%s किन भाषाओं में है?', $title['title']),
               'a' => tf('%s इन भाषाओं में है: %s।', $title['title'],
                        implode(', ', array_map(fn ($l) => lang_label($l['lang_code']), $langs)))];
}

// ---- schema @graph: Movie/TVSeries + Breadcrumb + FAQ + Organization ----
// breadcrumb: होम → श्रेणी (फिल्म/सीरीज़, browse पर) → यह title। visible और schema
// एक ही $tcrumbs से बनते हैं ताकि हमेशा मेल खाएँ (Google यही चाहता है)।
$tcrumbs = [
    ['name' => OTT_LANG === 'hi' ? 'होम' : 'Home', 'url' => '/'],
    ['name' => media_label($title['media_type']), 'url' => '/browse?type=' . ($is_tv ? 'tv' : 'movie')],
    ['name' => $title['title'], 'url' => title_url($title)],
];
$graph = [$node];
$graph[] = breadcrumb_schema($tcrumbs);
if ($faqs !== []) {
    $graph[] = [
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($f) => [
            '@type' => 'Question', 'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ], $faqs),
    ];
}
$graph[] = ['@type' => 'Organization', 'name' => 'OTTGuru', 'url' => 'https://ottguru.in/'];

// पतला पेज? — अगर यह title कभी किसी OTT पर नहीं रही (न अभी offers, न कोई इतिहास),
// तो पेज पर सिर्फ़ TMDB overview बचता है = पतला/duplicate। ऐसे पेज noindex रखते हैं
// ताकि Google की नज़र में पूरी साइट का भरोसा बना रहे और अच्छे पेज index हों (CLAUDE.md §8)।
// जैसे ही providers-sync इसकी availability भर देगा, यह अपने-आप index होने लायक़ हो जाएगा।
$thinPage = ($stream === [] && $paisa === [] && $spells === [] && $events === []
             && $langs === [] && $cast === []);

page_header([
    'title'       => tf('%s कहाँ देखें', $h1),
    'description' => $desc,
    'canonical'   => title_url($title),
    'image'       => tmdb_img($title['poster_path'], 'w500'),
    'image_alt'   => tf('%s का poster', $title['title']),
    'og_type'     => $is_tv ? 'video.tv_show' : 'video.movie',
    'noindex'     => $thinPage,
    'jsonld'      => ['@context' => 'https://schema.org', '@graph' => $graph],
]);
crumbs($tcrumbs);
?>
<?php $bd = tmdb_img($title['backdrop_path'] ?? null, 'w1280'); ?>
<?php if ($bd !== null): ?><div class="t-backdrop" style="background-image:url('<?= h($bd) ?>')"></div><?php endif; ?>

<div class="t-head">
  <?php $poster = tmdb_img($title['poster_path'], 'w342'); ?>
  <?php if ($poster !== null): ?>
  <div class="t-poster"><img src="<?= h($poster) ?>" alt="<?= h(tf('%s का poster', $title['title'])) ?>"></div>
  <?php endif; ?>

  <div class="t-meta">
    <h1><?= h($title['title']) ?><?= $year !== null ? ' <span class="dim">(' . h($year) . ')</span>' : '' ?></h1>
    <p class="t-sub">
      <?= h(media_label($title['media_type'])) ?>
      <?php if (nz($title['original_title'] ?? null) !== null && $title['original_title'] !== $title['title']): ?>
        · <?= h(t('मूल नाम:')) ?> <b><?= h($title['original_title']) ?></b>
      <?php endif; ?>
      <?php if (!$is_tv && (int) ($title['runtime'] ?? 0) > 0): ?>
        · <?= h(tf('%d मिनट', (int) $title['runtime'])) ?>
      <?php endif; ?>
      <?php if ($cert !== null): ?>
        · <span class="cert" title="<?= h(t('भारत सेंसर रेटिंग')) ?>"><?= h($cert) ?></span>
      <?php endif; ?>
    </p>

    <?php if ($genres !== []): ?>
    <div class="chips">
      <?php foreach ($genres as $g): ?><a class="chip" href="/genre/<?= h(rawurlencode($g['slug'])) ?>"><?= h($g['name_en']) ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($collection !== null):
      $cclean = trim(preg_replace('/\s*(Collection|Series)\s*$/i', '', (string) $collection['name'])) ?: $collection['name']; ?>
    <a class="franchise-link" href="/collection/<?= (int) $collection['collection_id'] ?>">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M7 7V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v3"/></svg>
      <?= h(tf('%s का हिस्सा', $cclean)) ?> →
    </a>
    <?php endif; ?>

    <?php if ((float) $title['vote_average'] > 0):
      $pct = (int) round((float) $title['vote_average'] / 10 * 100);
      $dash = round(157.08 * $pct / 100, 1);
      $col = $pct >= 70 ? 'var(--good)' : ($pct >= 50 ? 'var(--warn)' : 'var(--pink)'); ?>
    <div class="scorebox">
      <div class="score">
        <svg width="58" height="58" viewBox="0 0 58 58">
          <circle cx="29" cy="29" r="25" fill="none" stroke="rgba(255,255,255,.09)" stroke-width="4"></circle>
          <circle cx="29" cy="29" r="25" fill="none" stroke="<?= $col ?>" stroke-width="4" stroke-linecap="round" stroke-dasharray="<?= $dash ?> 157.08"></circle>
        </svg>
        <span class="num"><?= number_format((float) $title['vote_average'], 1) ?><s>/10</s></span>
      </div>
      <div class="meta">
        <div><b>TMDB</b> <?= OTT_LANG === 'hi' ? 'यूज़र स्कोर' : 'user score' ?></div>
        <?php if ((int) $title['vote_count'] > 0): ?><div><?= number_format((int) $title['vote_count']) ?> <?= OTT_LANG === 'hi' ? 'वोट' : 'votes' ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($langs !== []): ?>
    <div class="badges">
      <?php foreach ($langs as $l): ?>
        <span class="badge"><?= h(lang_label($l['lang_code'])) ?><?= $l['kind'] === 'original' ? ' ' . h(t('(मूल)')) : '' ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    // wishlist + recently-viewed का item (client-side localStorage — बिना login)
    $pageItem = json_encode([
        'url'   => title_url($title),
        'title' => $title['title'],
        'img'   => tmdb_img($title['poster_path'], 'w185'),
        'meta'  => trim((string) ($year ?? '')) . ' · ' . media_label($title['media_type']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    <div class="t-actions">
      <button type="button" class="btn-ghost wish-btn" data-wish='<?= h($pageItem) ?>' data-seen="1" aria-pressed="false"
              data-add="<?= h(OTT_LANG === 'hi' ? 'वॉचलिस्ट में जोड़ें' : 'Add to Wishlist') ?>"
              data-added="<?= h(OTT_LANG === 'hi' ? 'वॉचलिस्ट में ✓' : 'In Wishlist ✓') ?>">
        <svg class="wi" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
        <span class="wt"><?= h(OTT_LANG === 'hi' ? 'वॉचलिस्ट में जोड़ें' : 'Add to Wishlist') ?></span>
      </button>
      <a class="btn-ghost" href="#share"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5 8.6 10.5"/></svg><?= h(t('शेयर करें')) ?></a>
    </div>

    <h2><?= h(t('अभी कहाँ देखें')) ?></h2>
    <?php if ($stream === [] && $paisa === []): ?>
      <div class="offer-none">
        <?= h(tf('यह %s अभी भारत में किसी OTT पर नहीं दिख रही।', media_label($title['media_type']))) ?>
        <?php if ($spells !== []): ?><?= h(t('नीचे इतिहास में देखिए यह पहले कहाँ थी।')) ?><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="offers">
        <?php foreach ($stream as $o): $pid = (int) $o['provider_id'];
          $pm = $planMin[$pid] ?? null; $bn = $bundleBy[$pid] ?? []; ?>
        <div class="offer">
          <?php $logo = tmdb_img($o['logo_path'], 'w92'); ?>
          <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt="<?= h($o['name']) ?>"><?php endif; ?>
          <div>
            <div class="o-name"><a href="/platform/<?= h(rawurlencode($o['slug'])) ?>"><?= h($o['name']) ?></a></div>
            <div class="o-type"><?= h(offer_label($o['offer_type'])) ?></div>
            <?php if ($pm !== null || $bn !== []): ?>
            <div class="o-plan">
              <?php if ($pm !== null): ?>
                <span class="op-price"><?= OTT_LANG === 'hi' ? '₹' . (int) $pm['price_inr'] . ' से' : 'From ₹' . (int) $pm['price_inr'] ?></span><?php if ((int) $pm['tv_allowed'] === 0): ?><span class="op-tv"><?= OTT_LANG === 'hi' ? 'सबसे सस्ता plan TV पर नहीं' : 'cheapest plan not on TV' ?></span><?php endif; ?>
              <?php endif; ?>
              <?php foreach ($bn as $b): ?>
                <span class="op-bundle"><?= h($b['operator']) ?> ₹<?= (int) $b['plan_price'] ?><?= OTT_LANG === 'hi' ? ' में' : '' ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="o-since"><?= h(tf('%s से यहाँ है', hindi_month($o['first_seen']))) ?></div>
          <?php $wl = watch_url($o, $title); ?>
          <?php if ($wl !== null): ?>
            <a class="watchbtn" href="<?= h($wl) ?>" rel="nofollow noopener" target="_blank"><?= h(t('अभी देखें')) ?> <span aria-hidden="true">↗</span></a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($paisa !== []): ?>
      <h2><?= h(t('किराये / ख़रीद पर')) ?></h2>
      <div class="offers">
        <?php foreach ($paisa as $o): ?>
        <div class="offer">
          <?php $logo = tmdb_img($o['logo_path'], 'w92'); ?>
          <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt="<?= h($o['name']) ?>"><?php endif; ?>
          <div>
            <div class="o-name"><a href="/platform/<?= h(rawurlencode($o['slug'])) ?>"><?= h($o['name']) ?></a></div>
            <div class="o-type"><?= h(offer_label($o['offer_type'])) ?></div>
          </div>
          <?php $wl = watch_url($o, $title); ?>
          <?php if ($wl !== null): ?>
            <a class="watchbtn" href="<?= h($wl) ?>" rel="nofollow noopener" target="_blank"><?= h(t('अभी देखें')) ?> <span aria-hidden="true">↗</span></a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php
// section tabs — scroll-spy sticky nav (सारा content DOM में रहता है, SEO सुरक्षित;
// no-JS पर भी ये सादे anchor-links हैं जो सही section पर ले जाते हैं)
$tabs = [];
if (nz($title['overview'] ?? null) !== null) { $tabs[] = ['s-story', OTT_LANG === 'hi' ? 'कहानी' : 'Story']; }
if ($cast !== []) { $tabs[] = ['s-cast', OTT_LANG === 'hi' ? 'कलाकार' : 'Cast']; }
if ($byDirector !== [] || $similar !== []) { $tabs[] = ['s-similar', OTT_LANG === 'hi' ? 'मिलती-जुलती' : 'Similar']; }
if ($spells !== [] || $events !== []) { $tabs[] = ['s-history', OTT_LANG === 'hi' ? 'इतिहास' : 'History']; }
$tabs[] = ['s-facts', OTT_LANG === 'hi' ? 'तथ्य' : 'Facts'];
?>
<?php if (count($tabs) > 2): ?>
<nav class="t-tabs" aria-label="<?= h(OTT_LANG === 'hi' ? 'सेक्शन' : 'Sections') ?>">
  <?php foreach ($tabs as [$tid, $tlbl]): ?>
  <a class="t-tab" href="#<?= h($tid) ?>" data-tab="<?= h($tid) ?>"><?= h($tlbl) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($dubByProv !== []): ?>
<h2><?= h(t('किस OTT पर कौन सी ऑडियो')) ?></h2>
<p class="dim small"><?= h(t('यह इस title की OTT पर मिलने वाली ऑडियो/dub है — फिल्म की मूल भाषा से अलग।')) ?></p>
<div class="offers">
  <?php foreach ($dubByProv as $pname => $dlangs): $dlangs = array_values(array_unique($dlangs)); ?>
  <div class="offer">
    <div>
      <div class="o-name"><?= h($pname) ?></div>
      <div class="badges" style="margin:7px 0 0">
        <?php foreach ($dlangs as $lc): ?><span class="badge"><?= h(lang_label($lc)) ?></span><?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (nz($title['overview'] ?? null) !== null): ?>
<h2 id="s-story"><?= h(t('कहानी')) ?></h2>
<?php if (nz($meta['tagline'] ?? null) !== null): ?><p class="tagline">“<?= h($meta['tagline']) ?>”</p><?php endif; ?>
<p><?= h($title['overview']) ?></p>
<?php endif; ?>

<?php if ($trailer !== null):
  $tthumb = tmdb_img($title['backdrop_path'] ?? null, 'w780') ?? tmdb_img($title['poster_path'], 'w500'); ?>
<h2><?= h(t('ट्रेलर')) ?></h2>
<div class="trailer" data-yt="<?= h($trailer['yt_key']) ?>" role="button" tabindex="0"
     aria-label="<?= h(tf('%s का ट्रेलर चलाएँ', $title['title'])) ?>">
  <?php if ($tthumb !== null): ?><img src="<?= h($tthumb) ?>" alt="<?= h(tf('%s ट्रेलर', $title['title'])) ?>" loading="lazy"><?php endif; ?>
  <span class="play" aria-hidden="true"></span>
</div>
<?php endif; ?>

<?php if ($cast !== []): ?>
<h2 id="s-cast"><?= h(t('कलाकार')) ?></h2>
<div class="castrow">
  <?php foreach ($cast as $c): ?>
  <a class="castcard" href="<?= h(person_url($c)) ?>">
    <?php $pf = tmdb_img($c['profile_path'] ?? null, 'w185'); ?>
    <div class="ph"><?php if ($pf !== null): ?><img src="<?= h($pf) ?>" alt="<?= h($c['name']) ?>" loading="lazy"><?php else: ?><span class="noimg"><?= h(mb_substr($c['name'], 0, 1)) ?></span><?php endif; ?></div>
    <div class="nm"><?= h($c['name']) ?></div>
    <?php if (nz($c['role'] ?? null) !== null): ?><div class="rl"><?= h($c['role']) ?></div><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// सिफ़ारिश rail — poster cards (homepage जैसा)
$rec_rail = function (array $items): void {
    echo '<div class="rail">';
    foreach ($items as $t) {
        $img = tmdb_img($t['poster_path'] ?? null, 'w342');
        echo '<a class="pcard" href="' . h(title_url($t)) . '">';
        echo $img !== null
            ? '<img loading="lazy" src="' . h($img) . '" alt="' . h(tf('%s का poster', $t['title'])) . '">'
            : '<span class="noposter">' . h(mb_substr($t['title'], 0, 40, 'UTF-8')) . '</span>';
        if ((float) $t['vote_average'] > 0) {
            echo '<span class="rate">★ <b>' . number_format((float) $t['vote_average'], 1) . '</b></span>';
        }
        echo '<span class="ov"><span class="t">' . h($t['title']) . '</span>'
           . '<span class="m">' . h((string) ($t['release_year'] ?? '')) . ' · ' . h(media_label($t['media_type'])) . '</span></span></a>';
    }
    echo '</div>';
};
?>

<?php if ($byDirector !== [] || $similar !== []): ?><span id="s-similar" class="s-anchor" aria-hidden="true"></span><?php endif; ?>
<?php if ($byDirector !== []): ?>
<h2><?= h(OTT_LANG === 'hi' ? ($is_tv ? 'इसी क्रिएटर की और' : 'इसी निर्देशक की और') : ($is_tv ? 'More from this creator' : 'More from this director')) ?></h2>
<?php $rec_rail($byDirector); ?>
<?php endif; ?>

<?php if ($similar !== []): ?>
<h2 style="margin-top:36px"><?= h(OTT_LANG === 'hi' ? 'मिलती-जुलती — अभी OTT पर' : 'More like this — on OTT now') ?></h2>
<?php $rec_rail($similar); ?>
<?php endif; ?>

<?php
// ---- इतिहास को एक animated timeline में: रिलीज़ → हर बदलाव → अभी ----------------
$tl = [];
if (nz($title['release_date'] ?? null) !== null) {
    $tl[] = ['when' => $title['release_date'], 'kind' => 'rel', 'html' => '<b>' . h(t('रिलीज़ हुई')) . '</b>'];
}
foreach (array_reverse($events) as $e) {   // $events DESC है → ASC कर लेते हैं
    if ($e['change_type'] === 'added') {
        $tl[] = ['when' => $e['changed_on'], 'kind' => 'add',
                 'html' => tf('%s पर आई', '<b>' . h($e['name']) . '</b>'),
                 'tag'  => offer_label($e['offer_type'])];
    } else {
        $tl[] = ['when' => $e['changed_on'], 'kind' => 'rm',
                 'html' => tf('%s से हटी', '<b>' . h($e['name']) . '</b>')];
    }
}
$now_names = array_values(array_unique(array_map(fn ($o) => $o['name'], $stream)));
if ($now_names !== []) {
    $tl[] = ['when' => null, 'kind' => 'now',
             'html' => tf('अभी %s पर', '<b>' . h(implode(', ', $now_names)) . '</b>'),
             'tag'  => t('लाइव')];
}

// ---- सफ़र का सार + platform-वार अवधि (Feature 9 — §1 की जान) -------------------
// $spells: is_current DESC, first_seen ASC पहले से sorted। सब्सक्रिप्शन (देखने लायक़)
// को rent/buy से अलग रखते हैं — सार सिर्फ़ "कहाँ देखी जा सकती है" पर बने।
$subOffers  = ['flatrate', 'ads', 'free'];
$curSub     = array_values(array_filter($spells, fn ($s) => (int) $s['is_current'] === 1 && in_array($s['offer_type'], $subOffers, true)));
$pastSpells = array_values(array_filter($spells, fn ($s) => (int) $s['is_current'] === 0));
$curSubNm   = array_values(array_unique(array_map(fn ($s) => $s['name'], $curSub)));
$pastNm     = array_values(array_unique(array_map(fn ($s) => $s['name'], $pastSpells)));
$platSeen   = count(array_unique(array_map(fn ($s) => $s['name'], $spells)));
$firstTrack = null;
foreach ($spells as $s) {
    if ($firstTrack === null || $s['first_seen'] < $firstTrack) {
        $firstTrack = $s['first_seen'];
    }
}
$hasMoved = $pastNm !== [];   // कभी platform बदला? तभी "सफ़र" कहने लायक़
?>
<?php if ($tl !== []): ?>
<h2 id="s-history"><?= h(t('उपलब्धता का इतिहास')) ?></h2>
<p class="dim small"><?= h(t('यह जानकारी सिर्फ़ OTT गुरु पर है — हम रोज़ जाँचते हैं कि कौन सी चीज़ किस platform पर आई और कब हटी।')) ?></p>

<?php if ($spells !== []): ?>
<div class="ahist">
  <?php /* सफ़र की एक-पंक्ति कहानी — सिर्फ़ असली डेटा से */ ?>
  <p class="ahist-lead">
    <?php if ($curSubNm !== [] && $hasMoved): ?>
      <?= OTT_LANG === 'hi'
          ? 'पहले <b>' . h(implode(', ', $pastNm)) . '</b> पर थी — अब <b>' . h(implode(', ', $curSubNm)) . '</b> पर।'
          : 'Previously on <b>' . h(implode(', ', $pastNm)) . '</b> — now on <b>' . h(implode(', ', $curSubNm)) . '</b>.' ?>
    <?php elseif ($curSubNm !== []): ?>
      <?= OTT_LANG === 'hi'
          ? 'अभी <b>' . h(implode(', ', $curSubNm)) . '</b> के सब्सक्रिप्शन में उपलब्ध।'
          : 'Currently streaming on <b>' . h(implode(', ', $curSubNm)) . '</b>.' ?>
    <?php elseif ($pastNm !== []): ?>
      <?= OTT_LANG === 'hi'
          ? 'अभी किसी OTT सब्सक्रिप्शन में नहीं — पहले <b>' . h(implode(', ', $pastNm)) . '</b> पर थी।'
          : 'Not on any OTT subscription right now — it was on <b>' . h(implode(', ', $pastNm)) . '</b>.' ?>
    <?php endif; ?>
  </p>
  <div class="ahist-stats">
    <span><b><?= (int) $platSeen ?></b> <?= OTT_LANG === 'hi' ? 'platforms देखे' : ($platSeen === 1 ? 'platform seen' : 'platforms seen') ?></span>
    <?php if ($firstTrack !== null): ?><span><?= OTT_LANG === 'hi' ? 'नज़र' : 'tracked since' ?> <b><?= h(hindi_month($firstTrack)) ?></b></span><?php endif; ?>
    <?php if ($events !== []): ?><span><b><?= count($events) ?></b> <?= OTT_LANG === 'hi' ? 'बदलाव दर्ज' : (count($events) === 1 ? 'change logged' : 'changes logged') ?></span><?php endif; ?>
  </div>

  <?php /* platform-वार spell — कब से कब तक, कितने समय (यही किसी और के पास नहीं) */ ?>
  <div class="spells">
    <?php foreach ($spells as $s): $cur = (int) $s['is_current'] === 1;
      $dur = $cur ? human_duration($s['first_seen']) : human_duration($s['first_seen'], $s['last_seen']); ?>
    <div class="spell <?= $cur ? 'cur' : 'past' ?>">
      <span class="sp-dot" aria-hidden="true"></span>
      <div class="sp-main">
        <div class="sp-top">
          <a class="sp-name" href="/platform/<?= h(rawurlencode($s['pslug'])) ?>"><?= h($s['name']) ?></a>
          <span class="sp-offer"><?= h(offer_label($s['offer_type'])) ?></span>
          <?php if ($cur): ?><span class="sp-badge live"><?= h(t('लाइव')) ?></span><?php else: ?><span class="sp-badge gone"><?= OTT_LANG === 'hi' ? 'हट गई' : 'removed' ?></span><?php endif; ?>
        </div>
        <div class="sp-when">
          <?= h(hindi_month($s['first_seen'])) ?> – <?= $cur ? h(OTT_LANG === 'hi' ? 'अब' : 'now') : h(hindi_month($s['last_seen'])) ?>
          <?php if ($dur !== ''): ?><span class="sp-dur">· <?= h($dur) ?></span><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="tlwrap" data-reveal>
  <div class="timeline">
    <div class="tline"><i></i></div>
    <?php foreach ($tl as $n): ?>
    <div class="tev <?= h($n['kind'] === 'rel' ? '' : $n['kind']) ?>">
      <span class="node"></span>
      <div class="when"><?= $n['when'] !== null ? h(hindi_date($n['when'])) : h(t('आज')) ?></div>
      <div class="what"><?= $n['html'] ?><?php if (!empty($n['tag'])): ?><span class="tag"><?= h($n['tag']) ?></span><?php endif; ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($langs !== [] && $dubByProv === []): ?>
<div class="note">
  <?= h(tf('ऊपर लिखी भाषाएँ इस %s की मूल/बोली गई भाषाएँ हैं। किस platform पर कौन सी ऑडियो (dub) मिलेगी — यह जानकारी जल्द जुड़ेगी।', media_label($title['media_type']))) ?>
</div>
<?php endif; ?>

<h2 id="s-facts"><?= h(t('फिल्म के तथ्य')) ?></h2>
<div class="facts">
  <?php $plink = fn ($c) => '<a href="' . h(person_url($c)) . '">' . h($c['name']) . '</a>'; ?>
  <?php if ($directors !== []): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? ($is_tv ? 'क्रिएटर' : 'निर्देशक') : ($is_tv ? 'Creator' : 'Director') ?></div><div class="v" style="font-size:14px"><?= implode(', ', array_map($plink, $directors)) ?></div></div><?php endif; ?>
  <?php if ($writers !== []): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'लेखक' : 'Writer' ?></div><div class="v" style="font-size:14px"><?= implode(', ', array_map($plink, array_slice($writers, 0, 3))) ?></div></div><?php endif; ?>
  <?php if ($year !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'रिलीज़ वर्ष' : 'Year' ?></div><div class="v"><?= h($year) ?></div></div><?php endif; ?>
  <div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'किस्म' : 'Type' ?></div><div class="v"><?= h(media_label($title['media_type'])) ?></div></div>
  <?php if ($cert !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'सेंसर रेटिंग' : 'Certification' ?></div><div class="v"><?= h($cert) ?></div></div><?php endif; ?>
  <?php if (!$is_tv && (int) ($title['runtime'] ?? 0) > 0): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'अवधि' : 'Runtime' ?></div><div class="v"><?= (int) $title['runtime'] ?> <?= OTT_LANG === 'hi' ? 'मिनट' : 'min' ?></div></div><?php endif; ?>
  <?php if ($original !== []): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'मूल भाषा' : 'Original language' ?></div><div class="v"><?= h(lang_label($original[0])) ?></div></div><?php endif; ?>
  <?php if (nz($title['release_date'] ?? null) !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'रिलीज़ तारीख़' : 'Release date' ?></div><div class="v" style="font-size:14px"><?= h(hindi_date($title['release_date'])) ?></div></div><?php endif; ?>
  <?php if (nz($meta['digital_date'] ?? null) !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'OTT/डिजिटल रिलीज़' : 'OTT/digital release' ?></div><div class="v" style="font-size:14px"><?= h(hindi_date($meta['digital_date'])) ?></div></div><?php endif; ?>
  <?php if (nz($title['status'] ?? null) !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'स्थिति' : 'Status' ?></div><div class="v" style="font-size:14px"><?= h($title['status']) ?></div></div><?php endif; ?>
</div>

<?php if ($faqs !== []): ?>
<h2><?= h(t('अक्सर पूछे सवाल')) ?></h2>
<div class="faq">
  <?php foreach ($faqs as $i => $f): ?>
  <details<?= $i === 0 ? ' open' : '' ?>><summary><?= h($f['q']) ?></summary><div class="a"><?= h($f['a']) ?></div></details>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$shareUrl = 'https://ottguru.in' . title_url($title);
$u = rawurlencode($shareUrl);
$txt = rawurlencode($title['title'] . ' — OTTGuru');
?>
<h2 id="share"><?= h(t('शेयर करें')) ?></h2>
<div class="share" data-url="<?= h($shareUrl) ?>">
  <a href="https://wa.me/?text=<?= $txt ?>%20<?= $u ?>" target="_blank" rel="noopener"><span class="ic" style="background:#25D366"></span>WhatsApp</a>
  <a href="https://t.me/share/url?url=<?= $u ?>&text=<?= $txt ?>" target="_blank" rel="noopener"><span class="ic" style="background:#2AABEE"></span>Telegram</a>
  <a href="https://twitter.com/intent/tweet?url=<?= $u ?>&text=<?= $txt ?>" target="_blank" rel="noopener"><span class="ic" style="background:#111"></span>X</a>
  <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $u ?>" target="_blank" rel="noopener"><span class="ic" style="background:#1877F2"></span>Facebook</a>
  <button type="button" class="s-copy"><span class="ic" style="background:var(--grad)"></span><?= h(t('लिंक कॉपी')) ?></button>
</div>

<script>
(function(){
  // section tabs — active tab को scroll के साथ highlight (smooth-scroll CSS से)
  var tabs = [].slice.call(document.querySelectorAll('.t-tab'));
  if (tabs.length){
    var secs = tabs.map(function(t){return document.getElementById(t.dataset.tab);}).filter(Boolean);
    var spy = new IntersectionObserver(function(es){
      es.forEach(function(e){ if(e.isIntersecting){
        var id = e.target.id;
        tabs.forEach(function(t){ t.classList.toggle('on', t.dataset.tab===id); });
      }});
    }, {rootMargin:'-42% 0px -52% 0px'});
    secs.forEach(function(s){ spy.observe(s); });
  }
  // timeline reveal — JS हो तभी छिपाकर animate (no-JS पर content दिखता ही रहे)
  var els = document.querySelectorAll('[data-reveal]');
  if (matchMedia('(prefers-reduced-motion:reduce)').matches){
    els.forEach(function(e){e.classList.add('in');});
  } else {
    els.forEach(function(e){e.classList.add('anim');});
    var io = new IntersectionObserver(function(es,o){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');o.unobserve(e.target);}});},{threshold:.15});
    els.forEach(function(e){io.observe(e);});
  }
  // trailer — क्लिक पर ही YouTube iframe लोड (तब तक कोई third-party नहीं)
  var tr = document.querySelector('.trailer[data-yt]');
  if (tr){
    var load = function(){
      var k = tr.getAttribute('data-yt');
      var f = document.createElement('iframe');
      f.src = 'https://www.youtube-nocookie.com/embed/' + k + '?autoplay=1&rel=0';
      f.title = 'Trailer'; f.allow = 'accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture';
      f.allowFullscreen = true; f.loading = 'lazy';
      tr.innerHTML = ''; tr.classList.add('on'); tr.removeAttribute('role'); tr.appendChild(f);
    };
    tr.addEventListener('click', load);
    tr.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); load(); } });
  }
  // share — copy link
  var sc = document.querySelector('.share .s-copy'), box = document.querySelector('.share');
  if (sc && box){ sc.addEventListener('click', function(){
    var url = box.getAttribute('data-url'), old = sc.lastChild.textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject())
      .then(function(){ sc.lastChild.textContent = <?= json_encode(t('कॉपी हो गया ✓'), JSON_UNESCAPED_UNICODE) ?>; })
      .catch(function(){ prompt(<?= json_encode(t('यह लिंक कॉपी कीजिए:'), JSON_UNESCAPED_UNICODE) ?>, url); })
      .finally(function(){ setTimeout(function(){ sc.lastChild.textContent = old; }, 1800); });
  }); }
})();
</script>
<?php page_footer(); ?>
