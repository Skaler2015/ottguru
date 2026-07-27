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

/** 2024-01-12 → "12 जनवरी 2024" */
function hindi_date(?string $ymd): string
{
    static $mah = [1=>'जनवरी','फ़रवरी','मार्च','अप्रैल','मई','जून',
                   'जुलाई','अगस्त','सितंबर','अक्टूबर','नवंबर','दिसंबर'];
    if (nz($ymd) === null) {
        return '—';
    }
    $ts = strtotime($ymd);
    if ($ts === false) {
        return '—';
    }
    return (int) date('j', $ts) . ' ' . $mah[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** सिर्फ़ "जनवरी 2024" — इतिहास की पट्टी के लिए */
function hindi_month(?string $ymd): string
{
    static $mah = [1=>'जनवरी','फ़रवरी','मार्च','अप्रैल','मई','जून',
                   'जुलाई','अगस्त','सितंबर','अक्टूबर','नवंबर','दिसंबर'];
    if (nz($ymd) === null) {
        return '—';
    }
    $ts = strtotime($ymd);
    return $ts === false ? '—' : $mah[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

function offer_label(string $t): string
{
    return match ($t) {
        'flatrate' => 'सब्सक्रिप्शन में',
        'ads'      => 'विज्ञापन के साथ',
        'free'     => 'मुफ़्त',
        'rent'     => 'किराये पर',
        'buy'      => 'ख़रीदकर',
        default    => $t,
    };
}

function media_label(string $t): string
{
    return $t === 'tv' ? 'वेब सीरीज़' : 'फिल्म';
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

/** भाषा कोड → हिंदी नाम (भारत में आम भाषाएँ; बाक़ी कोड जैसे के तैसे) */
function lang_label(string $code): string
{
    static $map = [
        'hi' => 'हिंदी',    'en' => 'अंग्रेज़ी', 'ta' => 'तमिल',    'te' => 'तेलुगु',
        'ml' => 'मलयालम',  'kn' => 'कन्नड़',   'bn' => 'बांग्ला',  'mr' => 'मराठी',
        'pa' => 'पंजाबी',   'gu' => 'गुजराती', 'or' => 'ओड़िया',   'as' => 'असमिया',
        'ur' => 'उर्दू',    'ne' => 'नेपाली',  'ko' => 'कोरियाई', 'ja' => 'जापानी',
        'fr' => 'फ़्रेंच',   'es' => 'स्पैनिश', 'de' => 'जर्मन',    'zh' => 'चीनी',
        'it' => 'इतालवी',  'ru' => 'रूसी',    'th' => 'थाई',     'tr' => 'तुर्की',
        'id' => 'इंडोनेशियाई', 'ar' => 'अरबी', 'pt' => 'पुर्तगाली', 'sa' => 'संस्कृत',
    ];
    return $map[$code] ?? strtoupper($code);
}

/** देवनागरी अंक — आँकड़ों की पट्टी थोड़ी अपनी सी लगे */
function hindi_num(int|string $n): string
{
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
