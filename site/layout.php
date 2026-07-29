<?php
/**
 * हर पन्ने का साझा ढाँचा। पन्ने page_header() से शुरू और
 * page_footer() पर ख़त्म होते हैं — बीच में सिर्फ़ अपना content छापते हैं।
 */
declare(strict_types=1);

/**
 * $opt:
 *   title       — <title> (साइट का नाम अपने-आप जुड़ जाता है)
 *   description — meta description
 *   canonical   — canonical path, जैसे '/movie/jawan-2023'
 *   image       — og:image का पूरा URL
 *   jsonld      — schema.org array (जैसा है वैसा छप जाएगा)
 *   noindex     — true = robots को मना करना (ख़ाली/paginated पन्नों के लिए)
 */
function page_header(array $opt = []): void
{
    $site  = 'OTT Guru';
    $title = isset($opt['title']) ? $opt['title'] . ' — ' . $site : t('OTT Guru — कौन सी फिल्म किस OTT पर है');
    $desc  = $opt['description'] ?? t('कौन सी फिल्म या वेब सीरीज़ किस OTT platform पर है — Netflix, Prime Video, Hotstar, ZEE5, SonyLIV। platform बदलने का पूरा इतिहास सिर्फ़ यहाँ।');
    ?>
<!doctype html>
<html lang="<?= OTT_LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h(mb_substr($desc, 0, 300, 'UTF-8')) ?>">
<?php if (!empty($opt['noindex'])): ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>
<?php if (!empty($opt['canonical'])): ?>
<link rel="canonical" href="https://ottguru.in<?= h($opt['canonical']) ?>">
<?php endif; ?>
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h(mb_substr($desc, 0, 200, 'UTF-8')) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="<?= OTT_LANG === 'hi' ? 'hi_IN' : 'en_IN' ?>">
<?php if (!empty($opt['image'])): ?>
<meta property="og:image" content="<?= h($opt['image']) ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css">
<?php if (!empty($opt['jsonld'])): ?>
<script type="application/ld+json"><?= json_encode($opt['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
</head>
<body>
<header class="top">
  <div class="wrap top-in">
    <a class="logo" href="/">OTT<span>Guru</span></a>
    <nav class="topnav">
      <a class="tlink" href="/"><?= h(t('होम')) ?></a>
      <a class="tlink" href="/naya"><?= h(t('नया आया')) ?></a>
      <a class="tlink" href="/hata"><?= h(t('क्या हटा')) ?></a>
      <a class="nav-search" href="/search" aria-label="<?= h(t('खोजें')) ?>"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></a>
      <span class="langsw">
        <a class="<?= OTT_LANG === 'en' ? 'on' : '' ?>" href="<?= h(lang_switch_url('en')) ?>">EN</a><!--
     --><a class="<?= OTT_LANG === 'hi' ? 'on' : '' ?>" href="<?= h(lang_switch_url('hi')) ?>">हिं</a>
      </span>
    </nav>
  </div>
</header>
<main class="wrap">
<?php
}

function page_footer(): void
{
    ?>
</main>
<footer class="foot">
  <div class="wrap">
    <div class="fgrid">
      <div class="fcol">
        <a class="logo" href="/">OTT<span>Guru</span></a>
        <p class="about"><?= t('OTT गुरु — कौन सी फिल्म किस platform पर है, और कब से कब तक थी। उपलब्धता रोज़ जाँची जाती है, फिर भी देखने से पहले app में पुष्टि कर लें।') ?></p>
        <form class="news" action="/" method="get" onsubmit="return false">
          <input type="email" placeholder="you@email.com" aria-label="Email">
          <button type="submit"><?= h(t('सब्सक्राइब')) ?></button>
        </form>
      </div>
      <div class="fcol"><h4><?= h(t('खोजें और देखें')) ?></h4>
        <a href="/search"><?= h(t('खोज')) ?></a>
        <a href="/naya"><?= h(t('नया आया')) ?></a>
        <a href="/hata"><?= h(t('क्या हटा')) ?></a>
        <a href="/"><?= h(t('होम')) ?></a></div>
      <div class="fcol"><h4>Platforms</h4>
        <a href="/platform/netflix">Netflix</a>
        <a href="/platform/prime-video">Prime Video</a>
        <a href="/platform/jiohotstar">JioHotstar</a>
        <a href="/platform/zee5">ZEE5</a></div>
      <div class="fcol"><h4><?= h(t('कंपनी')) ?></h4>
        <a href="/"><?= h(t('हमारे बारे में')) ?></a>
        <a href="/sitemap.xml">Sitemap</a>
        <a href="/"><?= h(t('निजता')) ?></a>
        <a href="/"><?= h(t('शर्तें')) ?></a></div>
      <div class="fcol"><h4><?= h(t('जुड़ें')) ?></h4>
        <a href="/">WhatsApp</a><a href="/">Instagram</a><a href="/">X</a><a href="/">YouTube</a></div>
    </div>
    <div class="fbot">
      <span>© 2026 OTTGuru · <?= OTT_LANG === 'hi' ? 'सीकर, राजस्थान में बना' : 'Made in Sikar, Rajasthan' ?> 🇮🇳</span>
      <span class="tmdb"><?= h(t('फिल्मों-सीरीज़ का डेटा और posters')) ?>
        <a href="https://www.themoviedb.org/" rel="noopener" target="_blank">TMDB</a><?= OTT_LANG === 'hi' ? '.' : '.' ?>
        Not endorsed or certified by TMDB.</span>
    </div>
  </div>
</footer>

<nav class="bnav">
  <a href="/" class="on"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 10l9-7 9 7v9a2 2 0 0 1-2 2h-4v-6H9v6H5a2 2 0 0 1-2-2z"/></svg><?= h(t('होम')) ?></a>
  <a href="/search"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg><?= h(t('खोज')) ?></a>
  <a href="/naya"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg><?= h(t('नया आया')) ?></a>
  <a href="/hata"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg><?= h(t('क्या हटा')) ?></a>
  <a href="<?= h(lang_switch_url(OTT_LANG === 'hi' ? 'en' : 'hi')) ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg><?= OTT_LANG === 'hi' ? 'EN' : 'हिं' ?></a>
</nav>
</body>
</html>
<?php
}

/** posters की grid — provider पन्ने और होमपेज दोनों इस्तेमाल करते हैं */
function render_title_grid(array $titles): void
{
    echo '<div class="grid">';
    foreach ($titles as $t) {
        $img = tmdb_img($t['poster_path'] ?? null, 'w342');
        echo '<a class="card" href="' . h(title_url($t)) . '">';
        if ($img !== null) {
            echo '<img loading="lazy" src="' . h($img) . '" alt="' . h(tf('%s का poster', $t['title'])) . '">';
        } else {
            echo '<span class="noposter">' . h(mb_substr($t['title'], 0, 40, 'UTF-8')) . '</span>';
        }
        echo '<span class="card-t">' . h($t['title']) . '</span>';
        echo '<span class="card-y">' . h((string) ($t['release_year'] ?? '')) ;
        if (isset($t['media_type'])) {
            echo ' · ' . h(media_label($t['media_type']));
        }
        echo '</span></a>';
    }
    echo '</div>';
}
