<?php
declare(strict_types=1);

/** URL के लिए साफ़ slug */
function slugify(string $s): string
{
    $s = trim($s);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && trim($t) !== '') {
            $s = $t;
        }
    }
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9\p{Devanagari}]+/u', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s === '' ? 'title' : mb_substr($s, 0, 200, 'UTF-8');
}

/**
 * provider नाम का सामान्यीकरण — यही 'Amazon Prime Video' और
 * 'Amazon Prime Video with Ads' को एक ही सेवा बनाता है।
 */
function norm_name(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
}

/** नाम से पता चलता है कि यह ad-supported tier है */
function name_implies_ads(string $s): bool
{
    $n = norm_name($s);
    return str_contains($n, 'withads') || str_contains($n, 'adsupported') || str_contains($n, 'freewithads');
}

/**
 * जिन नामों में 'with Ads' / 'Amazon Channel' जैसा पूँछ लगा है,
 * उसे हटाकर मूल सेवा का नाम निकालना — alias अपने-आप बन जाते हैं।
 */
function base_service_name(string $s): string
{
    $x = preg_replace('/\s*(with ads|amazon channel|apple tv channel|channel)\s*$/i', '', trim($s));
    return trim((string) $x) !== '' ? trim((string) $x) : trim($s);
}

/**
 * tier — कितनी बार जाँचना है।
 * 1 = चर्चित या 120 दिन से नया  (रोज़)
 * 2 = ठीक-ठाक या 3 साल से नया   (हफ़्ते में)
 * 3 = बाक़ी long tail            (महीने में)
 */
function compute_tier(float $popularity, ?string $releaseDate): int
{
    $days = 999999;
    if ($releaseDate !== null && $releaseDate !== '' && $releaseDate !== '0000-00-00') {
        $ts = strtotime($releaseDate);
        if ($ts !== false) {
            $days = (int) floor((time() - $ts) / 86400);
        }
    }
    if ($popularity >= 20.0 || ($days >= -60 && $days <= 120)) {
        return 1;
    }
    if ($popularity >= 5.0 || $days <= 365 * 3) {
        return 2;
    }
    return 3;
}

function nz(?string $s): ?string
{
    if ($s === null) {
        return null;
    }
    $s = trim($s);
    return $s === '' ? null : $s;
}

function ymd(?string $s): ?string
{
    $s = nz($s);
    if ($s === null) {
        return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : null;
}

function ms_now(): float
{
    return microtime(true);
}

function fmt_secs(float $s): string
{
    return number_format($s, 1) . 's';
}
