<?php
declare(strict_types=1);

function connect(array $config): PDO
{
    $db = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec("SET time_zone = '+00:00', SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode,''), 'STRICT_ALL_TABLES')");
    $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    return $db;
}

function query(PDO $db, string $sql, array $params = []): PDOStatement
{
    $statement = $db->prepare($sql);
    foreach (array_values($params) as $i => $value) {
        $type = match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value), is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
        $statement->bindValue($i + 1, $value, $type);
    }
    $statement->execute();
    return $statement;
}

function transaction(PDO $db, callable $work): mixed
{
    $db->beginTransaction();
    try {
        $result = $work();
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function audit(PDO $db, int $actor, string $action, string $detail): void
{
    query($db, 'INSERT INTO audit (actor_id, action, detail) VALUES (?, ?, ?)', [$actor, $action, $detail]);
}

function tableMap(): array
{
    return require __DIR__ . '/tables.php';
}

function assertEmpty(PDO $db): void
{
    foreach (array_keys(tableMap()) as $table) {
        if ($db->query("SELECT 1 FROM `$table` LIMIT 1")->fetchColumn()) {
            throw new RuntimeException("La tabla $table del destino contiene datos.");
        }
    }
}

function databaseParams(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

function insertRow(PDO $db, string $table, array $columns, array $row): void
{
    if (array_keys($row) !== $columns) {
        throw new RuntimeException("Columnas inesperadas en $table.");
    }
    $names = implode('`,`', $columns);
    $placeholders = databaseParams(count($columns));
    query($db, "INSERT INTO `$table` (`$names`) VALUES ($placeholders)", array_values($row));
}
