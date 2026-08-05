<?php
/**
 * BROWSE + FILTERS — डिस्कवरी का दिल  (Feature 3)
 * रास्ता: /browse?type=&genre=&lang=&platform=&year=&offer=&sort=&page=
 * "मुझे Netflix पर हिंदी thriller चाहिए" — यहीं से।
 *
 * SEO: filter लगते ही noindex (faceted-nav duplicate जाल से बचाव — Google का
 * क्लासिक penalty)। असली SEO landing हमारे genre/platform/language hub पेज हैं।
 * सारे मान parameterized/whitelisted — कोई SQL injection नहीं।
 */
declare(strict_types=1);

$country  = $CFG['country'] ?? 'IN';
$per_page = 36;

// ---------------------------------------------------------- filters (सुरक्षित)
$f_type = (string) ($_GET['type'] ?? '');
if (!in_array($f_type, ['movie', 'tv'], true)) {
    $f_type = '';
}
$f_genre = trim((string) ($_GET['genre'] ?? ''));
$f_lang  = trim((string) ($_GET['lang'] ?? ''));
if (!preg_match('/^[a-z]{2,3}$/', $f_lang)) {
    $f_lang = '';
}
$f_plat  = trim((string) ($_GET['platform'] ?? ''));
$f_year  = (int) ($_GET['year'] ?? 0);
if ($f_year < 1900 || $f_year > (int) date('Y') + 3) {
    $f_year = 0;
}
$f_offer = (string) ($_GET['offer'] ?? '');
if (!in_array($f_offer, ['flatrate', 'free', 'rent', 'buy'], true)) {
    $f_offer = '';
}
$f_sort = (string) ($_GET['sort'] ?? 'pop');
$page   = max(1, (int) ($_GET['page'] ?? 1));

// slug → id (मान्य करना; न मिले तो filter गिरा दो)
$genreRow = null;
if ($f_genre !== '') {
    try {
        $genreRow = one($PDO, 'SELECT id, name_en, slug FROM genres WHERE slug = ?', [$f_genre]);
    } catch (Throwable $e) {
        $genreRow = null;
    }
    if ($genreRow === null) {
        $f_genre = '';
    }
}
$provRow = null;
if ($f_plat !== '') {
    $provRow = one($PDO, 'SELECT id, name, slug FROM providers WHERE slug = ? AND is_active = 1', [$f_plat]);
    if ($provRow === null) {
        $f_plat = '';
    }
}

// ---------------------------------------------------------- query (dynamic, parameterized)
$joins = '';
$where = ['a.is_current = 1', 'a.country = ?'];
$args  = [$country];

if ($f_offer !== '') {
    $where[] = 'a.offer_type = ?';
    $args[]  = $f_offer;
} else {
    $where[] = "a.offer_type IN ('flatrate','ads','free')";   // default = streaming
}
if ($provRow !== null) {
    $where[] = 'a.provider_id = ?';
    $args[]  = (int) $provRow['id'];
}
if ($f_type !== '') {
    $where[] = 't.media_type = ?';
    $args[]  = $f_type;
}
if ($f_year > 0) {
    $where[] = 't.release_year = ?';
    $args[]  = $f_year;
}
if ($f_lang !== '') {
    $where[] = 't.original_language = ?';
    $args[]  = $f_lang;
}
if ($genreRow !== null) {
    $joins  .= ' JOIN title_genres tg ON tg.title_id = t.id ';   // (join में कोई param नहीं)
    $where[] = 'tg.genre_id = ?';
    $args[]  = (int) $genreRow['id'];
}

$from = "FROM titles t JOIN availability a ON a.title_id = t.id $joins WHERE " . implode(' AND ', $where);

$sortMap = [
    'pop'    => 't.popularity DESC',
    'rating' => 't.vote_average DESC, t.vote_count DESC',
    'new'    => 't.release_year DESC, t.popularity DESC',
    'az'     => 't.title ASC',
];
$orderBy = $sortMap[$f_sort] ?? $sortMap['pop'];
if (!isset($sortMap[$f_sort])) {
    $f_sort = 'pop';
}

$total = (int) scalar($PDO, "SELECT COUNT(DISTINCT t.id) $from", $args);
$pages = max(1, (int) ceil($total / $per_page));
$page  = min($page, $pages);

$titles = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.vote_average, t.popularity
      $from
     ORDER BY $orderBy
     LIMIT $per_page OFFSET " . (($page - 1) * $per_page), $args);

// ---------------------------------------------------------- filter dropdown विकल्प
$opt_genres = [];
try {
    $opt_genres = all($PDO, 'SELECT slug, name_en FROM genres ORDER BY name_en');
} catch (Throwable $e) {
}
$opt_provs = all($PDO, 'SELECT slug, name FROM providers WHERE is_active = 1 ORDER BY display_priority, name');
$opt_langs = ['hi', 'en', 'ta', 'te', 'ml', 'kn', 'bn', 'mr', 'pa', 'gu', 'ko', 'ja', 'es'];
$yr_now    = (int) date('Y');

$filtered = $f_type !== '' || $f_genre !== '' || $f_lang !== '' || $f_plat !== ''
    || $f_year > 0 || $f_offer !== '' || $f_sort !== 'pop' || $page > 1;

// ---------------------------------------------------------- meta (filter से बनता शीर्षक)
$bits = [];
if ($f_lang !== '')  { $bits[] = lang_label($f_lang); }
if ($genreRow)       { $bits[] = $genreRow['name_en']; }
$noun = $f_type === 'tv' ? t('वेब सीरीज़') : ($f_type === 'movie' ? t('फिल्में') : t('फिल्में और सीरीज़'));
$h1   = trim(implode(' ', $bits) . ' ' . $noun);
if ($provRow) { $h1 .= ' — ' . $provRow['name']; }

$crumbs = [
    ['name' => OTT_LANG === 'hi' ? 'होम' : 'Home', 'url' => '/'],
    ['name' => OTT_LANG === 'hi' ? 'ब्राउज़' : 'Browse', 'url' => '/browse'],
];

page_header([
    'title'       => $filtered ? tf('%s — अभी OTT पर', $h1) : t('Browse — फिल्में और सीरीज़ फ़िल्टर कीजिए'),
    'description' => tf('%s को platform, भाषा, genre, साल और रेटिंग से फ़िल्टर कीजिए — अभी भारत में OTT पर क्या-क्या है, एक जगह। OTT गुरु।', $noun),
    'canonical'   => '/browse',
    'noindex'     => $filtered,   // filtered views index न हों (faceted-nav जाल से बचाव)
    'breadcrumb'  => $crumbs,
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'CollectionPage',
        'name' => 'Browse — OTTGuru', 'url' => 'https://ottguru.in/browse',
    ],
]);
crumbs($crumbs);

// active filters को URL में बनाए रखने का helper (pager/sort के लिए)
$q_with = static function (array $over) use ($f_type, $f_genre, $f_lang, $f_plat, $f_year, $f_offer, $f_sort): string {
    $q = array_merge([
        'type' => $f_type, 'genre' => $f_genre, 'lang' => $f_lang, 'platform' => $f_plat,
        'year' => $f_year ?: '', 'offer' => $f_offer, 'sort' => $f_sort !== 'pop' ? $f_sort : '',
    ], $over);
    $q = array_filter($q, fn ($v) => $v !== '' && $v !== 0);
    return '/browse' . ($q !== [] ? '?' . http_build_query($q) : '');
};
?>

<div class="browsehead">
  <span class="eyebrow"><?= OTT_LANG === 'hi' ? 'डिस्कवर' : 'Discover' ?></span>
  <h1><?= $filtered ? h($h1) : h(t('क्या देखें? फ़िल्टर कीजिए')) ?></h1>
  <p class="dim"><?= h(tf('%s titles इस चुनाव में', number_format($total))) ?></p>
</div>

<form class="fbar" method="get" action="/browse">
  <select name="type" class="fsel">
    <option value=""><?= h(t('सब')) ?></option>
    <option value="movie" <?= $f_type === 'movie' ? 'selected' : '' ?>><?= h(t('फिल्में (tab)')) ?></option>
    <option value="tv" <?= $f_type === 'tv' ? 'selected' : '' ?>><?= h(t('वेब सीरीज़ (tab)')) ?></option>
  </select>

  <select name="genre" class="fsel">
    <option value=""><?= OTT_LANG === 'hi' ? 'सभी genre' : 'All genres' ?></option>
    <?php foreach ($opt_genres as $g): ?><option value="<?= h($g['slug']) ?>" <?= $f_genre === $g['slug'] ? 'selected' : '' ?>><?= h($g['name_en']) ?></option><?php endforeach; ?>
  </select>

  <select name="lang" class="fsel">
    <option value=""><?= OTT_LANG === 'hi' ? 'सभी भाषाएँ' : 'All languages' ?></option>
    <?php foreach ($opt_langs as $lc): ?><option value="<?= h($lc) ?>" <?= $f_lang === $lc ? 'selected' : '' ?>><?= h(lang_label($lc)) ?></option><?php endforeach; ?>
  </select>

  <select name="platform" class="fsel">
    <option value=""><?= OTT_LANG === 'hi' ? 'सभी OTT' : 'All platforms' ?></option>
    <?php foreach ($opt_provs as $p): ?><option value="<?= h($p['slug']) ?>" <?= $f_plat === $p['slug'] ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
  </select>

  <select name="year" class="fsel">
    <option value=""><?= OTT_LANG === 'hi' ? 'कोई भी साल' : 'Any year' ?></option>
    <?php for ($y = $yr_now + 1; $y >= 1970; $y--): ?><option value="<?= $y ?>" <?= $f_year === $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?>
  </select>

  <select name="offer" class="fsel">
    <option value=""><?= OTT_LANG === 'hi' ? 'सब्सक्रिप्शन/मुफ़्त' : 'Streaming/free' ?></option>
    <option value="flatrate" <?= $f_offer === 'flatrate' ? 'selected' : '' ?>><?= h(t('सब्सक्रिप्शन में')) ?></option>
    <option value="free" <?= $f_offer === 'free' ? 'selected' : '' ?>><?= h(t('मुफ़्त')) ?></option>
    <option value="rent" <?= $f_offer === 'rent' ? 'selected' : '' ?>><?= h(t('किराये पर')) ?></option>
    <option value="buy" <?= $f_offer === 'buy' ? 'selected' : '' ?>><?= h(t('ख़रीदकर')) ?></option>
  </select>

  <select name="sort" class="fsel">
    <option value="pop" <?= $f_sort === 'pop' ? 'selected' : '' ?>><?= OTT_LANG === 'hi' ? 'चर्चित' : 'Popular' ?></option>
    <option value="rating" <?= $f_sort === 'rating' ? 'selected' : '' ?>><?= OTT_LANG === 'hi' ? 'रेटिंग' : 'Top rated' ?></option>
    <option value="new" <?= $f_sort === 'new' ? 'selected' : '' ?>><?= OTT_LANG === 'hi' ? 'नए' : 'Newest' ?></option>
    <option value="az" <?= $f_sort === 'az' ? 'selected' : '' ?>>A–Z</option>
  </select>

  <button type="submit" class="fapply"><?= OTT_LANG === 'hi' ? 'लागू करें' : 'Apply' ?></button>
  <?php if ($filtered): ?><a class="fclear" href="/browse"><?= OTT_LANG === 'hi' ? 'साफ़ करें' : 'Clear' ?></a><?php endif; ?>
</form>

<?php if ($titles === []): ?>
  <div class="wl-empty" data-empty style="display:block">
    <div class="wl-ico" aria-hidden="true"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></div>
    <h3><?= OTT_LANG === 'hi' ? 'इस चुनाव में कुछ नहीं मिला' : 'Nothing matches this selection' ?></h3>
    <p class="dim"><?= OTT_LANG === 'hi' ? 'कोई फ़िल्टर हटाकर देखिए।' : 'Try removing a filter.' ?></p>
    <a class="btn-grad" href="/browse" style="margin-top:14px"><?= OTT_LANG === 'hi' ? 'फ़िल्टर साफ़ करें' : 'Clear filters' ?></a>
  </div>
<?php else: ?>
  <div class="results-bar">
    <span class="dim small"><?= h(tf('%d नतीजे', $total)) ?></span>
    <div class="viewtoggle" role="group" aria-label="<?= h(OTT_LANG === 'hi' ? 'दृश्य' : 'View') ?>">
      <button type="button" class="vbtn" data-view="grid" aria-label="Grid"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></button>
      <button type="button" class="vbtn" data-view="list" aria-label="List"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></button>
      <button type="button" class="vbtn" data-view="compact" aria-label="Compact"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="4" height="4"/><rect x="10" y="3" width="4" height="4"/><rect x="17" y="3" width="4" height="4"/><rect x="3" y="10" width="4" height="4"/><rect x="10" y="10" width="4" height="4"/><rect x="17" y="10" width="4" height="4"/></svg></button>
    </div>
  </div>
  <div id="browse-results"><?php render_title_grid($titles); ?></div>
  <script>
  (function(){
    var box=document.getElementById('browse-results'); if(!box)return;
    var grid=box.querySelector('.grid'), btns=[].slice.call(document.querySelectorAll('.vbtn')), LS='ottg_view';
    function apply(v){ if(!grid)return; grid.classList.remove('list','compact'); if(v==='list'||v==='compact')grid.classList.add(v);
      btns.forEach(function(b){b.classList.toggle('on',b.dataset.view===v);b.setAttribute('aria-pressed',b.dataset.view===v);}); }
    var saved='grid'; try{saved=localStorage.getItem(LS)||'grid'}catch(e){}
    apply(saved);
    btns.forEach(function(b){b.addEventListener('click',function(){ apply(b.dataset.view); try{localStorage.setItem(LS,b.dataset.view)}catch(e){} });});
  })();
  </script>
<?php endif; ?>

<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a href="<?= h($q_with(['page' => $page - 1 > 1 ? $page - 1 : ''])) ?>"><?= h(t('← पिछला')) ?></a><?php endif; ?>
  <span class="here"><?= h(tf('पन्ना %d / %d', $page, $pages)) ?></span>
  <?php if ($page < $pages): ?><a href="<?= h($q_with(['page' => $page + 1])) ?>"><?= h(t('अगला →')) ?></a><?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
