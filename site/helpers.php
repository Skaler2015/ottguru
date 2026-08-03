<?php
/**
 * वेबसाइट के छोटे-छोटे औज़ार — escaping, TMDB images, हिंदी तारीख़ें, लेबल।
 */
declare(strict_types=1);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** posters/logos हमेशा TMDB CDN से — अपने सर्वर पर कभी नहीं (CLAUDE.md §8) */
function tmdb_img(?string $path, string $size = 'w342'): ?string
{
    $path = nz($path);
    return $path === null ? null : 'https://image.tmdb.org/t/p/' . $size . $path;
}

/** महीनों के नाम — UI की भाषा के हिसाब से */
function month_names(): array
{
    static $hi = [1=>'जनवरी','फ़रवरी','मार्च','अप्रैल','मई','जून',
                  'जुलाई','अगस्त','सितंबर','अक्टूबर','नवंबर','दिसंबर'];
    static $en = [1=>'January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    return OTT_LANG === 'hi' ? $hi : $en;
}

/** 2024-01-12 → "12 जनवरी 2024" / "12 January 2024" */
function hindi_date(?string $ymd): string
{
    if (nz($ymd) === null) {
        return '—';
    }
    $ts = strtotime($ymd);
    if ($ts === false) {
        return '—';
    }
    return (int) date('j', $ts) . ' ' . month_names()[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** सिर्फ़ "जनवरी 2024" / "January 2024" — इतिहास की पट्टी के लिए */
function hindi_month(?string $ymd): string
{
    if (nz($ymd) === null) {
        return '—';
    }
    $ts = strtotime($ymd);
    return $ts === false ? '—' : month_names()[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

function offer_label(string $t): string
{
    return match ($t) {
        'flatrate' => t('सब्सक्रिप्शन में'),
        'ads'      => t('विज्ञापन के साथ'),
        'free'     => t('मुफ़्त'),
        'rent'     => t('किराये पर'),
        'buy'      => t('ख़रीदकर'),
        default    => $t,
    };
}

function media_label(string $t): string
{
    return $t === 'tv' ? t('वेब सीरीज़') : t('फिल्म');
}

/** title के canonical पन्ने का रास्ता */
function title_url(array $t): string
{
    return ($t['media_type'] === 'tv' ? '/series/' : '/movie/') . rawurlencode($t['slug']);
}

function provider_url(array $p): string
{
    return '/platform/' . rawurlencode($p['slug']);
}

/** व्यक्ति (कलाकार/निर्देशक) के पन्ने का रास्ता — id authoritative, slug SEO के लिए */
function person_url(array $p): string
{
    return '/person/' . (int) $p['id'] . '/' . rawurlencode(slugify((string) $p['name']));
}

/**
 * BreadcrumbList schema — $items: [['name'=>..,'url'=>path|null], …]।
 * आख़िरी item मौजूदा पन्ना (url हो भी सकता है, न भी)। page_header को
 * 'breadcrumb' में यही array देते हैं; वो JSON-LD बना देता है।
 */
function breadcrumb_schema(array $items): array
{
    $list = [];
    foreach (array_values($items) as $i => $it) {
        $node = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => (string) $it['name']];
        if (!empty($it['url'])) {
            $node['item'] = 'https://ottguru.in' . $it['url'];
        }
        $list[] = $node;
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/**
 * दिखने वाला breadcrumb — schema से मेल खाता (Google यही चाहता है)।
 * पन्ने page_header के ठीक बाद body में इसे बुलाते हैं।
 */
function crumbs(array $items): void
{
    $items = array_values($items);
    $last  = count($items) - 1;
    echo '<nav class="crumbs" aria-label="breadcrumb">';
    foreach ($items as $i => $it) {
        if ($i > 0) {
            echo '<span class="sep" aria-hidden="true">/</span>';
        }
        if (!empty($it['url']) && $i !== $last) {
            echo '<a href="' . h($it['url']) . '">' . h((string) $it['name']) . '</a>';
        } else {
            echo '<span class="cur">' . h((string) $it['name']) . '</span>';
        }
    }
    echo "</nav>\n";
}

/**
 * "अभी देखें" बटन का असली destination।
 *   1. सबसे पहले sync से मिला deep link (सीधे उस OTT पर वही मूवी)
 *   2. न मिले तो उसी OTT पर title के नाम की search — यूज़र दो क्लिक में मूवी तक
 *   3. वो भी न बने तो अपना platform पन्ना (बटन कभी टूटा हुआ न रहे)
 * $offer में watch_link + slug/name; $title में मूल नाम search के लिए।
 */
function watch_url(array $offer, array $title): ?string
{
    $link = trim((string) ($offer['watch_link'] ?? ''));
    // TMDB/JustWatch का "watch" link असली per-provider deep link नहीं है — हर OTT के
    // लिए वही होता है और themoviedb.org पर खुलता है। उसे छोड़कर नीचे OTT की search पर जाओ।
    // (असली deep link — जैसे netflix.com/… — मिले तो वही चलेगा।)
    if ($link !== '' && preg_match('~^https?://[^/]*(themoviedb\.org|themoviedb\.com|justwatch\.com)~i', $link) !== 1) {
        return $link;
    }

    // हर OTT का search पैटर्न — {q} पर title का encoded नाम बैठता है।
    static $search = [
        'netflix'     => 'https://www.netflix.com/search?q={q}',
        'prime-video' => 'https://www.primevideo.com/search/ref=atv_nb_sug?phrase={q}',
        'jiohotstar'  => 'https://www.hotstar.com/in/search?q={q}',
        'zee5'        => 'https://www.zee5.com/search?q={q}',
        'sonyliv'     => 'https://www.sonyliv.com/search?searchTerm={q}',
        'mx-player'   => 'https://www.mxplayer.in/search?q={q}',
        'jiocinema'   => 'https://www.hotstar.com/in/search?q={q}',
        'youtube'     => 'https://www.youtube.com/results?search_query={q}',
        'apple-tv'    => 'https://tv.apple.com/search?term={q}',
        'google-play-movies' => 'https://play.google.com/store/search?c=movies&q={q}',
    ];

    $slug = (string) ($offer['slug'] ?? '');
    $name = trim((string) ($title['title'] ?? ''));
    if ($name !== '' && isset($search[$slug])) {
        return str_replace('{q}', rawurlencode($name), $search[$slug]);
    }

    // कोई ठिकाना नहीं — अपना platform पन्ना ही सही
    return $slug !== '' ? '/platform/' . rawurlencode($slug) : null;
}

/** भाषा कोड → UI की भाषा में नाम (भारत में आम भाषाएँ; बाक़ी कोड जैसे के तैसे) */
function lang_label(string $code): string
{
    static $hi = [
        'hi' => 'हिंदी',    'en' => 'अंग्रेज़ी', 'ta' => 'तमिल',    'te' => 'तेलुगु',
        'ml' => 'मलयालम',  'kn' => 'कन्नड़',   'bn' => 'बांग्ला',  'mr' => 'मराठी',
        'pa' => 'पंजाबी',   'gu' => 'गुजराती', 'or' => 'ओड़िया',   'as' => 'असमिया',
        'ur' => 'उर्दू',    'ne' => 'नेपाली',  'ko' => 'कोरियाई', 'ja' => 'जापानी',
        'fr' => 'फ़्रेंच',   'es' => 'स्पैनिश', 'de' => 'जर्मन',    'zh' => 'चीनी',
        'it' => 'इतालवी',  'ru' => 'रूसी',    'th' => 'थाई',     'tr' => 'तुर्की',
        'id' => 'इंडोनेशियाई', 'ar' => 'अरबी', 'pt' => 'पुर्तगाली', 'sa' => 'संस्कृत',
    ];
    static $en = [
        'hi' => 'Hindi',     'en' => 'English',  'ta' => 'Tamil',      'te' => 'Telugu',
        'ml' => 'Malayalam', 'kn' => 'Kannada',  'bn' => 'Bengali',    'mr' => 'Marathi',
        'pa' => 'Punjabi',   'gu' => 'Gujarati', 'or' => 'Odia',       'as' => 'Assamese',
        'ur' => 'Urdu',      'ne' => 'Nepali',   'ko' => 'Korean',     'ja' => 'Japanese',
        'fr' => 'French',    'es' => 'Spanish',  'de' => 'German',     'zh' => 'Chinese',
        'it' => 'Italian',   'ru' => 'Russian',  'th' => 'Thai',       'tr' => 'Turkish',
        'id' => 'Indonesian','ar' => 'Arabic',   'pt' => 'Portuguese', 'sa' => 'Sanskrit',
    ];
    $map = OTT_LANG === 'hi' ? $hi : $en;
    return $map[$code] ?? strtoupper($code);
}

/** देवनागरी अंक — सिर्फ़ हिंदी UI में; अंग्रेज़ी में अंक जैसे के तैसे */
function hindi_num(int|string $n): string
{
    if (OTT_LANG !== 'hi') {
        return (string) $n;
    }
    return strtr((string) $n, ['0'=>'०','1'=>'१','2'=>'२','3'=>'३','4'=>'४',
                               '5'=>'५','6'=>'६','7'=>'७','8'=>'८','9'=>'९']);
}

/**
 * भाषा पेजों के URL-टुकड़े — /platform/netflix/hindi-movies का 'hindi'।
 * सिर्फ़ यही भाषाएँ पेज बनाती हैं; बाक़ी कोड आए तो 404 (thin पेजों से बचाव)।
 */
function lang_slugs(): array
{
    return [
        'hindi'     => 'hi', 'english'  => 'en', 'tamil'    => 'ta', 'telugu'  => 'te',
        'malayalam' => 'ml', 'kannada'  => 'kn', 'bengali'  => 'bn', 'marathi' => 'mr',
        'punjabi'   => 'pa', 'gujarati' => 'gu', 'korean'   => 'ko', 'japanese'=> 'ja',
        'spanish'   => 'es', 'urdu'     => 'ur',
    ];
}

/** lang_code → URL-टुकड़ा ('hi' → 'hindi'); नक़्शे में न हो तो null */
function lang_page_slug(string $code): ?string
{
    static $rev = null;
    $rev ??= array_flip(lang_slugs());
    return $rev[$code] ?? null;
}

/** 404 भेजकर पन्ना दिखाना */
function not_found(): never
{
    global $PDO, $CFG;
    http_response_code(404);
    require OTT_ROOT . '/site/pages/404.php';
    exit;
}
