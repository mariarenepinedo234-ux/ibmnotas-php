<?php
declare(strict_types=1);

require __DIR__ . '/../app/database.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if ($argc !== 2) {
    fwrite(STDERR, "Uso: php bin/migrate-postgres.php /ruta/privada/postgres.php\n");
    exit(1);
}

try {
    $config = require $argv[1];
    $source = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $target = connect(require __DIR__ . '/../config.php');
    $source->beginTransaction();
    $source->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY; SET LOCAL TIME ZONE 'UTC'");
    if ((int)$source->query('SELECT count(*) FROM public.schema_migrations')->fetchColumn() !== 12) {
        throw new RuntimeException('Se requieren las migraciones 001 a 012 del origen.');
    }
    $counts = transaction($target, function () use ($source, $target) {
        assertEmpty($target);
        $counts = [];
        foreach (tableMap() as $table => $columns) {
            $names = implode(',', $columns);
            $projection = $table === 'study_plans' ? str_replace('content,', "encode(content,'base64') AS content,", $names) : $names;
            foreach (['created_at', 'updated_at', 'unlocked_until'] as $date) {
                $projection = preg_replace('/\b' . $date . '\b/', "to_char($date, 'YYYY-MM-DD HH24:MI:SS.US') AS $date", $projection);
            }
            $source->exec("DECLARE ibm_migration_rows NO SCROLL CURSOR FOR SELECT $projection FROM public.$table");
            $batchSize = $table === 'study_plans' ? 1 : 500;
            $count = 0;
            do {
                $rows = $source->query("FETCH FORWARD $batchSize FROM ibm_migration_rows")->fetchAll();
                foreach ($rows as $row) {
                    if ($table === 'study_plans') {
                        $row['content'] = base64_decode($row['content'], true);
                        if ($row['content'] === false) {
                            throw new RuntimeException('PDF de origen ilegible.');
                        }
                    }
                    insertRow($target, $table, $columns, $row);
                    $count++;
                }
            } while (count($rows) === $batchSize);
            $source->exec('CLOSE ibm_migration_rows');
            if ((int)$target->query("SELECT count(*) FROM `$table`")->fetchColumn() !== $count) {
                throw new RuntimeException("Conteo inconsistente en $table.");
            }
            $counts[$table] = $count;
        }
        return $counts;
    });
    $source->rollBack();
    echo json_encode($counts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "La migración falló. " . ($error instanceof PDOException ? "Error de base de datos ({$error->getCode()})." : $error->getMessage()) . PHP_EOL);
    exit(1);
}
