<?php
declare(strict_types=1);

function writeBackup(PDO $db, string $directory): string
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear el directorio privado.');
    }
    $filename = 'ibm-notas-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.jsonl';
    $path = $directory . '/' . $filename;
    $file = fopen($path . '.partial', 'xb');
    if (!$file) {
        throw new RuntimeException('No se pudo abrir el respaldo para escritura.');
    }
    chmod($path . '.partial', 0600);
    $checksum = hash_init('sha256');
    $write = static function (array $record) use ($file, $checksum): void {
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        if (!isset($record['counts'])) {
            hash_update($checksum, $line);
        }
        if (fwrite($file, $line) !== strlen($line)) {
            throw new RuntimeException('Escritura incompleta del respaldo.');
        }
    };
    $buffered = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    try {
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        transaction($db, function () use ($db, $write, $checksum) {
            $write(['format' => 'ibmnotas-mysql-v1']);
            $counts = [];
            foreach (tableMap() as $table => $columns) {
                $names = implode('`,`', $columns);
                $counts[$table] = 0;
                $rows = $db->query("SELECT `$names` FROM `$table`");
                try {
                    while ($row = $rows->fetch()) {
                        if ($table === 'study_plans') {
                            $row['content'] = base64_encode($row['content']);
                        }
                        $write(['table' => $table, 'row' => $row]);
                        $counts[$table]++;
                    }
                } finally {
                    $rows->closeCursor();
                }
            }
            $write(['counts' => $counts, 'sha256' => hash_final($checksum)]);
        });
        if (!fflush($file)) {
            throw new RuntimeException('No se pudo finalizar el respaldo.');
        }
        fclose($file);
        if (!rename($path . '.partial', $path)) {
            throw new RuntimeException('No se pudo finalizar el respaldo.');
        }
        return $filename;
    } finally {
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        if (is_resource($file)) {
            fclose($file);
        }
        if (is_file($path . '.partial')) {
            unlink($path . '.partial');
        }
    }
}

function restoreBackup(PDO $db, string $path): array
{
    $file = fopen($path, 'rb');
    if (!$file) {
        throw new RuntimeException('No se pudo abrir el respaldo.');
    }
    try {
        return transaction($db, function () use ($db, $file) {
            assertEmpty($db);
            $header = fgets($file);
            if ($header === false || json_decode($header, true, flags: JSON_THROW_ON_ERROR) !== ['format' => 'ibmnotas-mysql-v1']) {
                throw new RuntimeException('Formato de respaldo inválido.');
            }
            $checksum = hash_init('sha256');
            hash_update($checksum, $header);
            $tables = tableMap();
            $counts = array_fill_keys(array_keys($tables), 0);
            while (($line = fgets($file)) !== false) {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (isset($record['counts'])) {
                    if ($record['counts'] !== $counts || !hash_equals(hash_final($checksum), (string)($record['sha256'] ?? '')) ||
                        fgets($file) !== false) {
                        throw new RuntimeException('El respaldo tiene conteos o suma de integridad inconsistentes.');
                    }
                    return $counts;
                }
                hash_update($checksum, $line);
                $table = $record['table'] ?? '';
                $row = $record['row'] ?? null;
                if (!isset($tables[$table]) || !is_array($row)) {
                    throw new RuntimeException('Registro de respaldo inválido.');
                }
                if ($table === 'study_plans') {
                    $row['content'] = base64_decode($row['content'] ?? '', true);
                    if ($row['content'] === false) {
                        throw new RuntimeException('Archivo de plan de estudio inválido.');
                    }
                }
                insertRow($db, $table, $tables[$table], $row);
                $counts[$table]++;
            }
            throw new RuntimeException('El respaldo está incompleto.');
        });
    } finally {
        fclose($file);
    }
}
