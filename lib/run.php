<?php
declare(strict_types=1);

function run_start(PDO $pdo, string $job): int
{
    q($pdo, 'INSERT INTO sync_runs (job, status) VALUES (?, "running")', [$job]);
    $id = (int) $pdo->lastInsertId();
    logline("=== $job शुरू (run #$id) ===");
    return $id;
}

function run_finish(PDO $pdo, int $runId, string $status, array $counts = [], ?string $note = null): void
{
    q($pdo, 'UPDATE sync_runs SET status = ?, finished_at = NOW(),
                titles_seen = ?, api_calls = ?, api_failures = ?,
                changes_added = ?, changes_removed = ?, note = ?
             WHERE id = ?', [
        $status,
        (int) ($counts['titles'] ?? 0),
        $GLOBALS['TMDB_CALLS'] ?? 0,
        $GLOBALS['TMDB_FAILURES'] ?? 0,
        (int) ($counts['added'] ?? 0),
        (int) ($counts['removed'] ?? 0),
        $note,
        $runId,
    ]);
    logline("=== run #$runId → $status  (titles " . (int) ($counts['titles'] ?? 0)
        . ', कॉल ' . ($GLOBALS['TMDB_CALLS'] ?? 0)
        . ', विफल ' . ($GLOBALS['TMDB_FAILURES'] ?? 0)
        . ', +' . (int) ($counts['added'] ?? 0)
        . ' -' . (int) ($counts['removed'] ?? 0) . ') ===');
}

/**
 * पुरानी अटकी दौड़ें साफ़ करना — अगर कोई run PHP timeout से मरी हो
 * तो वो हमेशा 'running' दिखती रहेगी और status.php झूठ बोलेगा।
 */
function run_reap_stale(PDO $pdo, int $olderThanMinutes = 90): void
{
    q($pdo, 'UPDATE sync_runs SET status = "failed", finished_at = NOW(),
                note = CONCAT(COALESCE(note,""), " [अपने-आप बंद — दौड़ अधूरी छूट गई थी]")
             WHERE status = "running" AND started_at < (NOW() - INTERVAL ? MINUTE)', [$olderThanMinutes]);
}

/**
 * सुरक्षा ब्रेक।
 * एक बुरा sync (API का बड़ा outage, region ब्लॉक, key बंद) पूरी history में
 * झूठे "हट गया" भर सकता है। यह जाँच उसे रोकती है।
 *
 * लौटाता है: null = ठीक है, आगे बढ़ो | string = वजह, रुक जाओ
 */
function safety_check(array $cfg, int $titlesOk, int $titlesLosing): ?string
{
    $minBatch = (int) ($cfg['safety']['min_batch'] ?? 20);
    $maxPct   = (float) ($cfg['safety']['max_removal_pct'] ?? 40);

    if ($titlesOk < $minBatch) {
        return null;                       // छोटी दौड़ पर जाँच का मतलब नहीं
    }
    $pct = ($titlesLosing / $titlesOk) * 100;
    if ($pct > $maxPct) {
        return sprintf(
            'सुरक्षा ब्रेक लगा: %d सफल titles में से %d से provider हट रहे थे (%.1f%%, सीमा %.1f%%). '
            . 'कोई बदलाव नहीं लिखा गया.',
            $titlesOk,
            $titlesLosing,
            $pct,
            $maxPct
        );
    }
    return null;
}

function alert(array $cfg, string $subject, string $body): void
{
    $to = trim((string) ($cfg['safety']['alert_email'] ?? ''));
    if ($to === '' || !function_exists('mail')) {
        return;
    }
    @mail(
        $to,
        '[OTT Guru] ' . $subject,
        $body,
        implode("\r\n", [
            'From: OTT Guru sync <no-reply@ottguru.in>',
            'Content-Type: text/plain; charset=utf-8',
        ])
    );
}

/** एक ही job की दो दौड़ें साथ न चलें (cron ओवरलैप से बचाव) */
function lock_acquire(string $job): mixed
{
    $f = sys_get_temp_dir() . '/ottguru-' . preg_replace('/[^a-z0-9_]/i', '', $job) . '.lock';
    $h = @fopen($f, 'c');
    if ($h === false) {
        return null;                       // लॉक न बन पाए तो रोकना नहीं है
    }
    if (!flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        return false;                      // पहले से चल रही है
    }
    return $h;
}

function lock_release(mixed $h): void
{
    if (is_resource($h)) {
        @flock($h, LOCK_UN);
        @fclose($h);
    }
}
