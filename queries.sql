-- ============================================================================
--  तैयार queries — चरण 2 (वेबसाइट) में सीधे काम आएँगी
--  हर query के ऊपर लिखा है कि यह कौन सा पन्ना बनाएगी।
--  सब पर ज़रूरी indexes schema.sql में पहले से मौजूद हैं।
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. TITLE पन्ना — "यह फिल्म कहाँ देखें"
--    /movie/jawan-2023
-- ----------------------------------------------------------------------------
SELECT p.slug, p.name, p.logo_path, a.offer_type, a.watch_link, a.first_seen
  FROM availability a
  JOIN providers p ON p.id = a.provider_id
  JOIN titles t    ON t.id = a.title_id
 WHERE t.slug = 'jawan-2023'
   AND a.country = 'IN'
   AND a.is_current = 1
 ORDER BY FIELD(a.offer_type, 'flatrate','ads','free','rent','buy'),
          p.display_priority;


-- ----------------------------------------------------------------------------
-- 2. PROVIDER पन्ना — "Netflix India पर उपलब्ध सब कुछ"
--    /platform/netflix
-- ----------------------------------------------------------------------------
SELECT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.vote_average
  FROM availability a
  JOIN titles t    ON t.id = a.title_id
  JOIN providers p ON p.id = a.provider_id
 WHERE p.slug = 'netflix'
   AND a.country = 'IN'
   AND a.is_current = 1
   AND a.offer_type IN ('flatrate','ads','free')
 ORDER BY t.popularity DESC
 LIMIT 40 OFFSET 0;


-- ----------------------------------------------------------------------------
-- 3. भाषा + PROVIDER — "Netflix पर हिंदी फिल्में"
--    /platform/netflix/hindi-movies
--    ध्यान: यह फिल्म की भाषा है। "इस OTT पर हिंदी dub है या नहीं" —
--    वो जानकारी Streaming Availability जुड़ने के बाद आएगी।
-- ----------------------------------------------------------------------------
SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path
  FROM availability a
  JOIN titles t          ON t.id = a.title_id
  JOIN providers p       ON p.id = a.provider_id
  JOIN title_languages l ON l.title_id = t.id
 WHERE p.slug = 'netflix'
   AND a.is_current = 1
   AND a.offer_type IN ('flatrate','ads','free')
   AND l.lang_code = 'hi'
   AND t.media_type = 'movie'
 ORDER BY t.popularity DESC
 LIMIT 40;


-- ----------------------------------------------------------------------------
-- 4. "इस हफ़्ते Netflix पर क्या नया आया"  ← बार-बार ट्रैफ़िक लाने वाला पन्ना
--    /naya/netflix
-- ----------------------------------------------------------------------------
SELECT t.slug, t.title, t.release_year, t.poster_path, c.changed_on, c.offer_type
  FROM availability_changes c
  JOIN titles t    ON t.id = c.title_id
  JOIN providers p ON p.id = c.provider_id
 WHERE p.slug = 'netflix'
   AND c.change_type = 'added'
   AND c.offer_type IN ('flatrate','ads','free')
   AND c.changed_on >= (CURDATE() - INTERVAL 7 DAY)
 ORDER BY c.changed_on DESC, t.popularity DESC;


-- ----------------------------------------------------------------------------
-- 5. "हाल में क्या हटा"  — /hata/netflix
-- ----------------------------------------------------------------------------
SELECT t.slug, t.title, t.release_year, c.changed_on
  FROM availability_changes c
  JOIN titles t    ON t.id = c.title_id
  JOIN providers p ON p.id = c.provider_id
 WHERE p.slug = 'netflix'
   AND c.change_type = 'removed'
   AND c.changed_on >= (CURDATE() - INTERVAL 30 DAY)
 ORDER BY c.changed_on DESC;


-- ----------------------------------------------------------------------------
-- 6. एक title का पूरा इतिहास  ← यही आपकी असली USP है
--    "यह फिल्म जनवरी 2024 से मार्च 2026 तक Netflix पर थी, अब ZEE5 पर है"
--    JustWatch पर भी यह नहीं मिलता। और यह डेटा कोई कॉपी नहीं कर सकता —
--    उसे भी शुरू से इतने महीने इंतज़ार करना पड़ेगा।
-- ----------------------------------------------------------------------------
SELECT p.name, a.offer_type, a.first_seen, a.last_seen, a.is_current
  FROM availability a
  JOIN providers p ON p.id = a.provider_id
  JOIN titles t    ON t.id = a.title_id
 WHERE t.slug = 'jawan-2023' AND a.country = 'IN'
 ORDER BY a.is_current DESC, a.first_seen;

-- वही, घटनाओं की शक्ल में
SELECT c.changed_on, c.change_type, p.name, c.offer_type
  FROM availability_changes c
  JOIN providers p ON p.id = c.provider_id
  JOIN titles t    ON t.id = c.title_id
 WHERE t.slug = 'jawan-2023'
 ORDER BY c.changed_on, c.id;


-- ----------------------------------------------------------------------------
-- 7. "platform बदलने वाली" कहानियाँ — एक ही दिन एक जगह से हटा, दूसरी पर आया
--    इससे "यह फिल्म अब Hotstar से ZEE5 पर चली गई" वाले पन्ने बनते हैं
-- ----------------------------------------------------------------------------
SELECT t.slug, t.title,
       pOut.name AS gaya_yahan_se,
       pIn.name  AS aaya_yahan,
       c1.changed_on
  FROM availability_changes c1
  JOIN availability_changes c2
       ON c2.title_id = c1.title_id
      AND c2.changed_on = c1.changed_on
      AND c2.change_type = 'added'
      AND c2.provider_id <> c1.provider_id
  JOIN titles t     ON t.id = c1.title_id
  JOIN providers pOut ON pOut.id = c1.provider_id
  JOIN providers pIn  ON pIn.id  = c2.provider_id
 WHERE c1.change_type = 'removed'
   AND c1.offer_type IN ('flatrate','ads','free')
   AND c2.offer_type IN ('flatrate','ads','free')
   AND c1.changed_on >= (CURDATE() - INTERVAL 60 DAY)
 ORDER BY c1.changed_on DESC;


-- ----------------------------------------------------------------------------
-- 8. एक से ज़्यादा platform पर मौजूद titles
--    "यह फिल्म तीन जगह है — किस पर आपके plan में मुफ़्त है?"
-- ----------------------------------------------------------------------------
SELECT t.slug, t.title, COUNT(DISTINCT a.provider_id) AS kitne,
       GROUP_CONCAT(DISTINCT p.name ORDER BY p.display_priority) AS kahan
  FROM availability a
  JOIN titles t    ON t.id = a.title_id
  JOIN providers p ON p.id = a.provider_id
 WHERE a.is_current = 1 AND a.offer_type IN ('flatrate','ads','free')
 GROUP BY t.id
HAVING kitne >= 2
 ORDER BY kitne DESC, t.popularity DESC
 LIMIT 50;


-- ----------------------------------------------------------------------------
-- 9. SITEMAP — सिर्फ़ वही पन्ने जिन पर असल में कुछ है
--    ख़ाली पन्ने sitemap में डालना Google की नज़र में "thin content" है
-- ----------------------------------------------------------------------------
SELECT t.slug, t.media_type, GREATEST(t.updated_at, COALESCE(MAX(a.last_seen), t.updated_at)) AS lastmod
  FROM titles t
  JOIN availability a ON a.title_id = t.id AND a.is_current = 1
 GROUP BY t.id
 ORDER BY t.popularity DESC;


-- ----------------------------------------------------------------------------
-- 10. होमपेज के आँकड़े
-- ----------------------------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM titles)                                            AS titles,
  (SELECT COUNT(*) FROM providers WHERE is_active = 1)                     AS platforms,
  (SELECT COUNT(*) FROM availability WHERE is_current = 1)                 AS abhi_uplabdh,
  (SELECT COUNT(*) FROM availability_changes)                              AS itihas,
  (SELECT COUNT(*) FROM availability_changes
    WHERE changed_on >= (CURDATE() - INTERVAL 7 DAY) AND change_type='added') AS is_hafte_naya;


-- ----------------------------------------------------------------------------
-- 11. रख-रखाव — अनजाने provider नाम जिनके alias जोड़ने हैं
--     status.php पर भी दिखते हैं
-- ----------------------------------------------------------------------------
SELECT v FROM sync_state WHERE k = 'unknown_providers';

-- नया alias जोड़ने का तरीक़ा:
-- INSERT INTO provider_aliases (provider_id, alias_norm, alias_raw, source)
-- SELECT id, 'koinayaott', 'कोई नया OTT', 'manual' FROM providers WHERE slug = 'netflix';


-- ----------------------------------------------------------------------------
-- 12. रख-रखाव — जो titles लगातार विफल हो रहे हैं (जाँच के लायक़)
-- ----------------------------------------------------------------------------
SELECT id, tmdb_id, media_type, title, providers_fail_streak,
       providers_last_checked, providers_last_success
  FROM titles
 WHERE providers_fail_streak >= 3
 ORDER BY providers_fail_streak DESC
 LIMIT 50;
