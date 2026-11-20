<?php
declare(strict_types=1);

require __DIR__ . '/../app/database.php';
require __DIR__ . '/../app/backup.php';

if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "Uso: php bin/restore.php /ruta/al/respaldo.jsonl\n");
    exit(1);
}
try {
    $db = connect(require __DIR__ . '/../config.php');
    echo json_encode(restoreBackup($db, $argv[1]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Restauración fallida. " . ($error instanceof PDOException ? "Error de base de datos ({$error->getCode()})." : $error->getMessage()) . PHP_EOL);
    exit(1);
}
