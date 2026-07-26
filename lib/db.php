<?php
declare(strict_types=1);

function db_connect(array $c): PDO
{
    if (!empty($c['socket'])) {
        $dsn = 'mysql:unix_socket=' . $c['socket'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    } else {
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int) ($c['port'] ?? 3306)
             . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    }
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        fail('DB से नहीं जुड़ सका — config.php में db सेटिंग जाँचिए। (' . $e->getMessage() . ')');
    }
    $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

function q(PDO $pdo, string $sql, array $args = []): PDOStatement
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st;
}

function one(PDO $pdo, string $sql, array $args = [])
{
    $r = q($pdo, $sql, $args)->fetch();
    return $r === false ? null : $r;
}

function scalar(PDO $pdo, string $sql, array $args = [])
{
    $r = q($pdo, $sql, $args)->fetchColumn();
    return $r === false ? null : $r;
}

function all(PDO $pdo, string $sql, array $args = []): array
{
    return q($pdo, $sql, $args)->fetchAll();
}

/** sync_state में कर्सर पढ़ना/लिखना — इससे लंबा काम टुकड़ों में होता है */
function state_get(PDO $pdo, string $key, $default = null)
{
    $v = scalar($pdo, 'SELECT v FROM sync_state WHERE k = ?', [$key]);
    if ($v === null) {
        return $default;
    }
    $d = json_decode((string) $v, true);
    return json_last_error() === JSON_ERROR_NONE ? $d : $v;
}

function state_set(PDO $pdo, string $key, $val): void
{
    q(
        $pdo,
        'INSERT INTO sync_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        [$key, is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE)]
    );
}
