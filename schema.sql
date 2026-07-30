-- ============================================================================
--  OTT Guru — डेटाबेस ढाँचा
--  MySQL 8 / MariaDB 10.4+  ·  InnoDB  ·  utf8mb4
-- ----------------------------------------------------------------------------
--  डिज़ाइन के तीन नियम जो पूरे ढाँचे में गुँथे हैं:
--   1. "कोशिश हुई" और "सफल हुई" अलग-अलग दर्ज होते हैं
--      (providers_last_checked  vs  providers_last_success)
--   2. फेल हुई कॉल को कभी "डेटा नहीं है" नहीं माना जाता
--   3. availability_changes append-only है और उस पर कोई CASCADE नहीं —
--      यही आपकी सबसे कीमती संपत्ति है, इसे कुछ भी मिटा न सके
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- 1. providers — OTT सेवाओं की मास्टर सूची
--    install.php इसे TMDB से अपने-आप भर देता है (सही IDs के साथ)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS providers (
  id                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tmdb_provider_id  INT UNSIGNED      NULL,
  slug              VARCHAR(60)       NOT NULL,
  name              VARCHAR(120)      NOT NULL,
  logo_path         VARCHAR(160)      NULL,
  is_active         TINYINT(1)        NOT NULL DEFAULT 1,
  display_priority  SMALLINT          NOT NULL DEFAULT 100,
  created_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_slug (slug),
  UNIQUE KEY uq_provider_tmdb (tmdb_provider_id),
  KEY ix_provider_active (is_active, display_priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. provider_aliases — गंदे नामों की सफ़ाई
--    TMDB एक ही सेवा को कई नामों से भेजता है:
--      'Amazon Prime Video'  'Amazon Prime Video with Ads'
--      'MX Player'           'Amazon MX Player'
--      'ManoramaMax'         'ManoramaMAX Amazon Channel'
--    alias यहाँ लिखते ही सब एक provider_id पर मिल जाते हैं
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_aliases (
  id               INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  provider_id      SMALLINT UNSIGNED  NOT NULL,
  alias_norm       VARCHAR(160)       NOT NULL COMMENT 'lowercase, सिर्फ़ a-z0-9',
  alias_raw        VARCHAR(160)       NOT NULL COMMENT 'जैसा API से आया',
  tmdb_provider_id INT UNSIGNED       NULL COMMENT 'TMDB का अपना id, अगर यह alias TMDB से आया',
  source           ENUM('tmdb','sa','manual') NOT NULL DEFAULT 'manual',
  implies_ads      TINYINT(1)         NOT NULL DEFAULT 0 COMMENT '1 = ad-supported tier',
  created_at       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alias (alias_norm),
  UNIQUE KEY uq_alias_tmdb (tmdb_provider_id),
  KEY ix_alias_provider (provider_id),
  CONSTRAINT fk_alias_provider FOREIGN KEY (provider_id)
    REFERENCES providers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. titles — फिल्में और सीरीज़
--    tier तय करता है कि कितनी बार जाँचा जाएगा (1=रोज़ 2=हफ़्ता 3=महीना)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS titles (
  id                     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  tmdb_id                INT UNSIGNED  NOT NULL,
  media_type             ENUM('movie','tv') NOT NULL,
  imdb_id                VARCHAR(15)   NULL,
  slug                   VARCHAR(280)  NOT NULL,
  title                  VARCHAR(255)  NOT NULL,
  original_title         VARCHAR(255)  NULL,
  original_language      VARCHAR(8)    NULL,
  overview               TEXT          NULL,
  release_date           DATE          NULL,
  release_year           SMALLINT      NULL,
  runtime                SMALLINT      NULL,
  status                 VARCHAR(40)   NULL,
  poster_path            VARCHAR(160)  NULL,
  backdrop_path          VARCHAR(160)  NULL,
  popularity             DECIMAL(12,4) NOT NULL DEFAULT 0,
  vote_average           DECIMAL(4,2)  NOT NULL DEFAULT 0,
  vote_count             INT UNSIGNED  NOT NULL DEFAULT 0,
  is_adult               TINYINT(1)    NOT NULL DEFAULT 0,

  -- ---- sync नियंत्रण ----
  tier                   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  detail_last_success    DATETIME      NULL,
  providers_last_checked DATETIME      NULL COMMENT 'कोशिश कब हुई (फेल भी गिनती है)',
  providers_last_success DATETIME      NULL COMMENT 'सफल कब हुई — poller यही देखता है',
  providers_fail_streak  SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_title_tmdb (media_type, tmdb_id),
  UNIQUE KEY uq_title_slug (slug),
  KEY ix_poll (tier, providers_last_success),
  KEY ix_popularity (popularity),
  KEY ix_year (release_year),
  KEY ix_lang (original_language),
  KEY ix_detail (detail_last_success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. title_languages — TMDB की भाषा जानकारी
--    ध्यान: यह फिल्म की भाषा है, "इस OTT पर कौन सी dub है" नहीं।
--    वो जानकारी आगे provider_audio में आएगी (Streaming Availability से)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS title_languages (
  title_id   INT UNSIGNED NOT NULL,
  lang_code  VARCHAR(8)   NOT NULL,
  kind       ENUM('original','spoken') NOT NULL,
  PRIMARY KEY (title_id, lang_code, kind),
  KEY ix_lang_lookup (lang_code, kind),
  CONSTRAINT fk_tl_title FOREIGN KEY (title_id)
    REFERENCES titles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. availability — "अभी कहाँ है" + "कब से कब तक था"
--    is_current=0 वाली पंक्तियाँ मिटाई नहीं जातीं — वही इतिहास है
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS availability (
  id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  title_id          INT UNSIGNED      NOT NULL,
  provider_id       SMALLINT UNSIGNED NOT NULL,
  offer_type        ENUM('flatrate','ads','free','rent','buy') NOT NULL,
  country           CHAR(2)           NOT NULL DEFAULT 'IN',
  raw_provider_name VARCHAR(160)      NULL COMMENT 'जैसा API से आया — कुछ न खोए',
  watch_link        VARCHAR(500)      NULL,
  first_seen        DATE              NOT NULL,
  last_seen         DATE              NOT NULL,
  is_current        TINYINT(1)        NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_avail (title_id, provider_id, offer_type, country),
  KEY ix_avail_browse (provider_id, offer_type, is_current),
  KEY ix_avail_title (title_id, is_current),
  KEY ix_avail_lastseen (last_seen),
  CONSTRAINT fk_av_title FOREIGN KEY (title_id)
    REFERENCES titles (id) ON DELETE CASCADE,
  CONSTRAINT fk_av_provider FOREIGN KEY (provider_id)
    REFERENCES providers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. availability_changes — असली ख़ज़ाना (append-only, कोई FOREIGN KEY नहीं)
--    इस पर कोई CASCADE नहीं है, ताकि कुछ भी इसे गलती से न मिटा सके।
--    "इस हफ़्ते Netflix पर क्या आया", "Prime से क्या हट रहा है",
--    "यह फिल्म पहले कहाँ थी" — सब इसी एक टेबल से निकलेगा
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS availability_changes (
  id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  title_id     INT UNSIGNED      NOT NULL,
  provider_id  SMALLINT UNSIGNED NOT NULL,
  offer_type   ENUM('flatrate','ads','free','rent','buy') NOT NULL,
  country      CHAR(2)           NOT NULL DEFAULT 'IN',
  change_type  ENUM('added','removed') NOT NULL,
  changed_on   DATE              NOT NULL,
  detected_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  run_id       INT UNSIGNED      NULL COMMENT 'किस sync run ने पकड़ा',
  PRIMARY KEY (id),
  KEY ix_ch_date (changed_on),
  KEY ix_ch_provider (provider_id, change_type, changed_on),
  KEY ix_ch_title (title_id, detected_at),
  KEY ix_ch_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. sync_runs — हर दौड़ का हिसाब (सेहत जाँचने के लिए)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_runs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  job             VARCHAR(40)  NOT NULL,
  status          ENUM('running','done','failed','halted') NOT NULL DEFAULT 'running',
  started_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at     DATETIME     NULL,
  titles_seen     INT UNSIGNED NOT NULL DEFAULT 0,
  api_calls       INT UNSIGNED NOT NULL DEFAULT 0,
  api_failures    INT UNSIGNED NOT NULL DEFAULT 0,
  changes_added   INT UNSIGNED NOT NULL DEFAULT 0,
  changes_removed INT UNSIGNED NOT NULL DEFAULT 0,
  note            TEXT         NULL,
  PRIMARY KEY (id),
  KEY ix_run_job (job, started_at),
  KEY ix_run_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. sync_state — कर्सर और सेटिंग (queue pointer यहीं रहता है)
--    इसी वजह से लंबा काम टुकड़ों में हो पाता है और timeout नहीं मारता
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sync_state (
  k          VARCHAR(60) NOT NULL,
  v          TEXT        NULL,
  updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  TMDB का अतिरिक्त मेटाडेटा (cast, genre, trailer, certification)
--  ये सब TMDB से आता है, इसलिए disposable है — इन पर CASCADE है, sync हर बार
--  title के लिए ताज़ा भर देता है। availability_changes की तरह "ख़ज़ाना" नहीं।
--  मौजूदा टेबलें बिलकुल नहीं छुईं — सिर्फ़ नई जोड़ी गई हैं (backward compatible)।
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 9. genres — TMDB genre मास्टर (base response में id+नाम आते हैं)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS genres (
  id       SMALLINT UNSIGNED NOT NULL COMMENT 'TMDB genre id — स्थिर रहता है',
  name_en  VARCHAR(60)  NOT NULL,
  slug     VARCHAR(60)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_genre_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. title_genres — कौन सी फिल्म किन genres में
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS title_genres (
  title_id INT UNSIGNED      NOT NULL,
  genre_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (title_id, genre_id),
  KEY ix_tg_genre (genre_id),
  CONSTRAINT fk_tg_title FOREIGN KEY (title_id)
    REFERENCES titles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. people — cast/crew मास्टर (एक व्यक्ति कई titles में; /person पेज के लिए भी)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS people (
  id           INT UNSIGNED NOT NULL COMMENT 'TMDB person id',
  name         VARCHAR(160) NOT NULL,
  profile_path VARCHAR(160) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12. title_credits — cast + crew (sync हर बार title के लिए delete+reinsert)
--     role = cast में किरदार का नाम, crew में job (Director/Writer आदि)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS title_credits (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title_id    INT UNSIGNED    NOT NULL,
  person_id   INT UNSIGNED    NOT NULL,
  credit_kind ENUM('cast','crew') NOT NULL,
  role        VARCHAR(200)    NULL,
  ord         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_tc_title (title_id, credit_kind, ord),
  KEY ix_tc_person (person_id),
  CONSTRAINT fk_tc_title FOREIGN KEY (title_id)
    REFERENCES titles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 13. title_videos — trailer/teaser (सिर्फ़ YouTube key; फ़ाइल नहीं)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS title_videos (
  id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title_id INT UNSIGNED    NOT NULL,
  yt_key   VARCHAR(24)     NOT NULL,
  name     VARCHAR(200)    NULL,
  kind     VARCHAR(30)     NULL COMMENT 'Trailer / Teaser / Clip',
  ord      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_video (title_id, yt_key),
  CONSTRAINT fk_tv_title FOREIGN KEY (title_id)
    REFERENCES titles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 14. title_meta — 1:1 अतिरिक्त जानकारी (titles टेबल को छुए बिना)
--     certification = भारत की सेंसर रेटिंग; digital_date = OTT/digital रिलीज़
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS title_meta (
  title_id      INT UNSIGNED NOT NULL,
  certification VARCHAR(16)  NULL,
  tagline       VARCHAR(300) NULL,
  digital_date  DATE         NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (title_id),
  CONSTRAINT fk_tm_title FOREIGN KEY (title_id)
    REFERENCES titles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 15. provider_audio — "इस OTT पर यह title किन ऑडियो/dub भाषाओं में है"
--     Streaming Availability API से आता है। ⚠️ यह TMDB की title-भाषा से अलग है
--     (CLAUDE.md §5) — दोनों को कभी मिलाकर मत दिखाइए।
--     disposable: SA sync हर बार title के लिए ताज़ा भरता है (delete+reinsert), CASCADE।
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provider_audio (
  title_id    INT UNSIGNED      NOT NULL,
  provider_id SMALLINT UNSIGNED NOT NULL,
  lang_code   VARCHAR(8)        NOT NULL,
  PRIMARY KEY (title_id, provider_id, lang_code),
  KEY ix_pa_prov (provider_id, lang_code),
  CONSTRAINT fk_pa_title FOREIGN KEY (title_id)
    REFERENCES titles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
