<?php
/**
 * हर पन्ने का साझा ढाँचा। पन्ने page_header() से शुरू और
 * page_footer() पर ख़त्म होते हैं — बीच में सिर्फ़ अपना content छापते हैं।
 */
declare(strict_types=1);

/**
 * mega-menu का डेटा — genres / platforms / languages (सब असली, उपलब्ध titles वाले)।
 * हर पेज पर nav बनता है, पर page-cache के कारण ये queries सिर्फ़ cache-miss पर चलती हैं।
 * try/catch — कोई table न हो तो वो column चुपचाप छूट जाता है।
 */
function nav_mega(): array
{
    global $PDO, $CFG;
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $country = $CFG['country'] ?? 'IN';
    $out = ['genres' => [], 'platforms' => [], 'langs' => []];
    try {
        $out['platforms'] = all($PDO, "SELECT p.slug, p.name
              FROM providers p
              JOIN availability a ON a.provider_id = p.id AND a.is_current = 1
                                 AND a.country = ? AND a.offer_type IN ('flatrate','ads','free')
             WHERE p.is_active = 1
             GROUP BY p.id ORDER BY p.display_priority, COUNT(DISTINCT a.title_id) DESC LIMIT 10", [$country]);
    } catch (Throwable $e) { /* providers न हों — असंभव, पर सुरक्षित */ }
    try {
        $out['genres'] = all($PDO, "SELECT g.slug, g.name_en, COUNT(DISTINCT t.id) n
              FROM title_genres tg
              JOIN genres g ON g.id = tg.genre_id
              JOIN titles t ON t.id = tg.title_id
              JOIN availability a ON a.title_id = t.id AND a.is_current = 1
                                 AND a.country = ? AND a.offer_type IN ('flatrate','ads','free')
             GROUP BY g.id HAVING n >= 3 ORDER BY n DESC LIMIT 12", [$country]);
    } catch (Throwable $e) { /* genres table अभी नहीं */ }
    try {
        $out['langs'] = all($PDO, "SELECT l.lang_code, COUNT(DISTINCT t.id) n
              FROM availability a
              JOIN titles t ON t.id = a.title_id
              JOIN title_languages l ON l.title_id = t.id
             WHERE a.country = ? AND a.is_current = 1 AND a.offer_type IN ('flatrate','ads','free')
             GROUP BY l.lang_code ORDER BY n DESC LIMIT 8", [$country]);
    } catch (Throwable $e) { /* title_languages न हो — असंभव */ }
    return $cache = $out;
}

/**
 * $opt:
 *   title       — <title> (साइट का नाम अपने-आप जुड़ जाता है)
 *   description — meta description
 *   canonical   — canonical path, जैसे '/movie/jawan-2023'
 *   image       — og:image का पूरा URL
 *   image_alt   — og:image का alt (न हो तो title)
 *   og_type     — og:type (default 'website'; title पेज 'video.movie'/'video.tv_show')
 *   jsonld      — schema.org array (जैसा है वैसा छप जाएगा)
 *   breadcrumb  — [['name'=>..,'url'=>path], …] → BreadcrumbList schema अपने-आप
 *   noindex     — true = robots को मना करना (ख़ाली/paginated पन्नों के लिए)
 */
function page_header(array $opt = []): void
{
    $site  = 'OTT Guru';
    $title = isset($opt['title']) ? $opt['title'] . ' — ' . $site : t('OTT Guru — कौन सी फिल्म किस OTT पर है');
    $desc  = $opt['description'] ?? t('कौन सी फिल्म या वेब सीरीज़ किस OTT platform पर है — Netflix, Prime Video, Hotstar, ZEE5, SonyLIV। platform बदलने का पूरा इतिहास सिर्फ़ यहाँ।');
    $descS = mb_substr($desc, 0, 200, 'UTF-8');
    $ogType = $opt['og_type'] ?? 'website';
    $ogUrl  = !empty($opt['canonical']) ? 'https://ottguru.in' . $opt['canonical'] : null;
    $img    = $opt['image'] ?? null;
    $imgAlt = $opt['image_alt'] ?? $title;
    ?>
<!doctype html>
<html lang="<?= OTT_LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0e1a21">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h(mb_substr($desc, 0, 300, 'UTF-8')) ?>">
<?php if (!empty($opt['noindex'])): ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>
<?php if (!empty($opt['canonical'])): ?>
<link rel="canonical" href="https://ottguru.in<?= h($opt['canonical']) ?>">
<?php endif; ?>
<meta property="og:site_name" content="OTT Guru">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($descS) ?>">
<meta property="og:type" content="<?= h($ogType) ?>">
<meta property="og:locale" content="<?= OTT_LANG === 'hi' ? 'hi_IN' : 'en_IN' ?>">
<?php if ($ogUrl !== null): ?>
<meta property="og:url" content="<?= h($ogUrl) ?>">
<?php endif; ?>
<?php if ($img !== null): ?>
<meta property="og:image" content="<?= h($img) ?>">
<meta property="og:image:alt" content="<?= h($imgAlt) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $img !== null ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= h($title) ?>">
<meta name="twitter:description" content="<?= h($descS) ?>">
<?php if ($img !== null): ?>
<meta name="twitter:image" content="<?= h($img) ?>">
<meta name="twitter:image:alt" content="<?= h($imgAlt) ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://image.tmdb.org">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css">
<?php if (!empty($opt['jsonld'])): ?>
<script type="application/ld+json"><?= json_encode($opt['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
<?php if (!empty($opt['breadcrumb'])): ?>
<script type="application/ld+json"><?= json_encode(breadcrumb_schema($opt['breadcrumb']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
</head>
<body>
<header class="top">
  <div class="wrap top-in">
    <a class="logo" href="/">OTT<span>Guru</span></a>
    <nav class="topnav">
      <a class="tlink" href="/"><?= h(t('होम')) ?></a>
      <?php $mega = nav_mega(); ?>
      <span class="hasmega">
        <a class="tlink" href="/browse" aria-haspopup="true"><?= h(t('ब्राउज़')) ?>
          <svg class="mchev" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m6 9 6 6 6-6"/></svg></a>
        <div class="mega" role="menu" aria-label="<?= h(t('ब्राउज़')) ?>">
          <?php if ($mega['genres'] !== []): ?>
          <div class="mega-col">
            <div class="mega-h"><?= OTT_LANG === 'hi' ? 'श्रेणियाँ' : 'Genres' ?></div>
            <?php foreach ($mega['genres'] as $g): ?><a role="menuitem" href="/genre/<?= h(rawurlencode($g['slug'])) ?>"><?= h($g['name_en']) ?><span class="mn"><?= (int) $g['n'] ?></span></a><?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($mega['platforms'] !== []): ?>
          <div class="mega-col">
            <div class="mega-h">Platforms</div>
            <?php foreach ($mega['platforms'] as $p): ?><a role="menuitem" href="/platform/<?= h(rawurlencode($p['slug'])) ?>"><?= h($p['name']) ?></a><?php endforeach; ?>
            <a class="mega-all" href="/compare"><?= OTT_LANG === 'hi' ? 'plan तुलना →' : 'Compare plans →' ?></a>
          </div>
          <?php endif; ?>
          <?php if ($mega['langs'] !== []): ?>
          <div class="mega-col">
            <div class="mega-h"><?= OTT_LANG === 'hi' ? 'भाषाएँ' : 'Languages' ?></div>
            <?php foreach ($mega['langs'] as $l): ?><a role="menuitem" href="/browse?lang=<?= h(rawurlencode($l['lang_code'])) ?>"><?= h(lang_label($l['lang_code'])) ?></a><?php endforeach; ?>
            <a class="mega-all" href="/browse"><?= OTT_LANG === 'hi' ? 'सभी फ़िल्टर →' : 'All filters →' ?></a>
          </div>
          <?php endif; ?>
        </div>
      </span>
      <a class="tlink" href="/naya"><?= h(t('नया आया')) ?></a>
      <a class="tlink" href="/hata"><?= h(t('क्या हटा')) ?></a>
      <a class="tlink" href="/wishlist" aria-label="<?= h(OTT_LANG === 'hi' ? 'वॉचलिस्ट' : 'Wishlist') ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg></a>
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
        <a href="/platform/zee5">ZEE5</a>
        <a href="/compare"><?= h(OTT_LANG === 'hi' ? 'plan तुलना' : 'Compare plans') ?></a></div>
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
  <a href="/browse"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg><?= h(t('ब्राउज़')) ?></a>
  <a href="/search"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg><?= h(t('खोज')) ?></a>
  <a href="/naya"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg><?= h(t('नया आया')) ?></a>
  <a href="/hata"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg><?= h(t('क्या हटा')) ?></a>
  <a href="<?= h(lang_switch_url(OTT_LANG === 'hi' ? 'en' : 'hi')) ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg><?= OTT_LANG === 'hi' ? 'EN' : 'हिं' ?></a>
</nav>

<?php /* command-palette — / से या search icon से खुलता है; live poster suggestions */ ?>
<div class="cmdk" id="cmdk" hidden role="dialog" aria-modal="true" aria-label="<?= h(t('खोजें')) ?>"
     data-ph="<?= h(t('फिल्म, सीरीज़ या platform खोजिए…')) ?>"
     data-empty="<?= h(OTT_LANG === 'hi' ? 'कुछ नहीं मिला — नाम अंग्रेज़ी में लिखकर देखिए।' : 'Nothing found — try the English spelling.') ?>"
     data-recent="<?= h(OTT_LANG === 'hi' ? 'हाल की खोज' : 'Recent') ?>">
  <div class="cmdk-back" data-cmdk-close></div>
  <div class="cmdk-box">
    <div class="cmdk-in">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      <input id="cmdk-input" type="text" autocomplete="off" spellcheck="false" aria-label="<?= h(t('खोजें')) ?>" aria-controls="cmdk-results">
      <kbd class="cmdk-esc">Esc</kbd>
    </div>
    <div class="cmdk-results" id="cmdk-results" role="listbox"></div>
    <div class="cmdk-foot">
      <span><kbd>↑</kbd><kbd>↓</kbd> <?= OTT_LANG === 'hi' ? 'चुनें' : 'navigate' ?></span>
      <span><kbd>↵</kbd> <?= OTT_LANG === 'hi' ? 'खोलें' : 'open' ?></span>
      <span><kbd>/</kbd> <?= OTT_LANG === 'hi' ? 'खोज' : 'search' ?></span>
    </div>
  </div>
</div>
<script>
/* command-palette — live suggest + keyboard + recent (localStorage) */
(function(){
  var root=document.getElementById('cmdk'); if(!root) return;
  var input=document.getElementById('cmdk-input'), res=document.getElementById('cmdk-results');
  var emptyTxt=root.dataset.empty, recentTxt=root.dataset.recent, LS='ottg_recent';
  input.placeholder=root.dataset.ph;
  var open=false, items=[], active=-1, timer=null, ctrl=null;
  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  function recent(){ try{return JSON.parse(localStorage.getItem(LS)||'[]')}catch(e){return[]} }
  function pushRecent(it){ try{var r=recent().filter(function(x){return x.url!==it.url});
    r.unshift({url:it.url,title:it.title,meta:it.meta,img:it.img,rate:it.rate});
    localStorage.setItem(LS,JSON.stringify(r.slice(0,6)));}catch(e){} }
  function render(list,isRecent){
    items=list||[]; active=-1;
    if(!items.length){ res.innerHTML='<div class="cmdk-msg">'+(input.value.trim()?esc(emptyTxt):'')+'</div>'; return; }
    var head=isRecent?'<div class="cmdk-head">'+esc(recentTxt)+'</div>':'';
    res.innerHTML=head+items.map(function(it,i){
      var img=it.img?'<img src="'+esc(it.img)+'" alt="" loading="lazy">':'<span class="cmdk-ph"></span>';
      var rate=it.rate?'<span class="cmdk-rate">★ '+esc(it.rate)+'</span>':'';
      return '<a class="cmdk-item" role="option" href="'+esc(it.url)+'" data-i="'+i+'">'+img+
        '<span class="cmdk-tw"><b>'+esc(it.title)+'</b><span class="cmdk-m">'+esc(it.meta)+'</span></span>'+rate+'</a>';
    }).join('');
  }
  function show(){ root.hidden=false; document.body.style.overflow='hidden';
    requestAnimationFrame(function(){root.classList.add('on')}); open=true;
    input.value=''; render(recent(),true); input.focus(); }
  function hide(){ root.classList.remove('on'); open=false; document.body.style.overflow='';
    if(ctrl){ctrl.abort();ctrl=null;} setTimeout(function(){root.hidden=true;res.innerHTML='';},170); }
  function skel(){ var row='<div class="cmdk-skrow"><span class="skel cmdk-skimg"></span>'+
    '<span class="cmdk-sktw"><span class="skel"></span><span class="skel"></span></span></div>';
    res.innerHTML='<div class="cmdk-sk">'+row+row+row+row+'</div>'; }
  function fetchQ(q){ if(ctrl)ctrl.abort(); ctrl=('AbortController'in window)?new AbortController():null;
    skel();
    fetch('/suggest?q='+encodeURIComponent(q),ctrl?{signal:ctrl.signal}:{})
      .then(function(r){return r.json()}).then(function(d){ if(d.q!==input.value.trim())return; render(d.items,false); })
      .catch(function(){}); }
  input.addEventListener('input',function(){ var q=input.value.trim(); clearTimeout(timer);
    if(q.length<2){ render(recent(),true); return; } timer=setTimeout(function(){fetchQ(q)},160); });
  function move(d){ var els=res.querySelectorAll('.cmdk-item'); if(!els.length)return;
    active=(active+d+els.length)%els.length;
    els.forEach(function(e,i){e.classList.toggle('active',i===active); if(i===active)e.scrollIntoView({block:'nearest'});}); }
  function go(a){ var i=+a.dataset.i; if(items[i])pushRecent(items[i]); location.href=a.getAttribute('href'); }
  input.addEventListener('keydown',function(e){
    if(e.key==='ArrowDown'){e.preventDefault();move(1);}
    else if(e.key==='ArrowUp'){e.preventDefault();move(-1);}
    else if(e.key==='Enter'){ var els=res.querySelectorAll('.cmdk-item');
      if(active>=0&&els[active]){go(els[active]);} else if(input.value.trim()){location.href='/search?q='+encodeURIComponent(input.value.trim());} }
    else if(e.key==='Escape'){hide();} });
  res.addEventListener('click',function(e){ var a=e.target.closest('.cmdk-item'); if(a){e.preventDefault();go(a);} });
  root.addEventListener('mousedown',function(e){ if(e.target.hasAttribute('data-cmdk-close'))hide(); });
  document.querySelectorAll('.nav-search, .bnav a[href="/search"], [data-cmdk-open]').forEach(function(el){
    el.addEventListener('click',function(e){ e.preventDefault(); show(); }); });
  addEventListener('keydown',function(e){ if(open)return; var t=e.target,tag=(t.tagName||'').toLowerCase();
    if(e.key==='/'&&tag!=='input'&&tag!=='textarea'&&!t.isContentEditable){ e.preventDefault(); show(); } });
})();
</script>
<script>
/* wishlist + recently-viewed — localStorage, बिना login (§19)। */
(function(){
  function get(k){try{return JSON.parse(localStorage.getItem(k)||'[]')}catch(e){return[]}}
  function set(k,v){try{localStorage.setItem(k,JSON.stringify(v.slice(0,60)))}catch(e){}}
  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  var SEEN='ottg_seen', WISH='ottg_wish';
  function inList(k,u){return get(k).some(function(x){return x.url===u})}
  function record(k,it,max){var a=get(k).filter(function(x){return x.url!==it.url});a.unshift(it);set(k,a.slice(0,max||40));}
  function toggle(k,it){var a=get(k),f=false,i;for(i=0;i<a.length;i++){if(a[i].url===it.url){a.splice(i,1);f=true;break;}}if(!f)a.unshift(it);set(k,a);return !f;}
  function cardHTML(it){
    var img=it.img?'<img loading="lazy" src="'+esc(it.img)+'" alt="">':'<span class="noposter">'+esc(it.title)+'</span>';
    return '<a class="card" href="'+esc(it.url)+'"><span class="cposter">'+img+'</span>'+
      '<span class="card-t">'+esc(it.title)+'</span><span class="card-y">'+esc(it.meta||'')+'</span></a>';
  }
  function fillGrid(sel,k){document.querySelectorAll(sel).forEach(function(box){
    var list=get(k), host=box.querySelector('[data-grid]'), empty=box.querySelector('[data-empty]');
    if(!list.length){ if(empty)empty.hidden=false; return; }
    if(empty)empty.hidden=true;
    if(host)host.innerHTML=list.map(cardHTML).join('');
    box.hidden=false;
  });}
  // wishlist button(s) + recently-viewed record (title page)
  document.querySelectorAll('.wish-btn').forEach(function(btn){
    var it; try{it=JSON.parse(btn.dataset.wish);}catch(e){return;}
    if(btn.dataset.seen) record(SEEN,it,30);
    function paint(on){ btn.classList.toggle('on',on); btn.setAttribute('aria-pressed',on?'true':'false');
      var t=btn.querySelector('.wt'); if(t)t.textContent=on?btn.dataset.added:btn.dataset.add; }
    paint(inList(WISH,it.url));
    btn.addEventListener('click',function(){ paint(toggle(WISH,it)); });
  });
  fillGrid('[data-seen-rail]',SEEN);   // home: हाल में देखी
  fillGrid('[data-wish-list]',WISH);   // /wishlist पेज
})();
/* sticky nav — ऊपर पारदर्शी, नीचे scroll पर glass। rAF से हल्का। */
(function(){
  var top=document.querySelector('.top'); if(!top) return;
  var tick=false;
  function upd(){ top.classList.toggle('scrolled', window.scrollY>8); tick=false; }
  addEventListener('scroll',function(){ if(!tick){ tick=true; requestAnimationFrame(upd); } },{passive:true});
  upd();
})();
</script>
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
        // poster एक clipped wrapper में — ताकि hover पर image ज़ूम हो, कोने साफ़ रहें
        echo '<span class="cposter">';
        if ($img !== null) {
            echo '<img loading="lazy" src="' . h($img) . '" alt="' . h(tf('%s का poster', $t['title'])) . '">';
        } else {
            echo '<span class="noposter">' . h(mb_substr($t['title'], 0, 40, 'UTF-8')) . '</span>';
        }
        $va = (float) ($t['vote_average'] ?? 0);
        if ($va > 0) {
            echo '<span class="crate">★ ' . number_format($va, 1) . '</span>';
        }
        echo '</span>';
        echo '<span class="card-tx">';
        echo '<span class="card-t">' . h($t['title']) . '</span>';
        echo '<span class="card-y">' . h((string) ($t['release_year'] ?? '')) ;
        if (isset($t['media_type'])) {
            echo ' · ' . h(media_label($t['media_type']));
        }
        echo '</span></span></a>';
    }
    echo '</div>';
}
