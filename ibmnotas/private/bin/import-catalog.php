<?php
declare(strict_types=1);

require __DIR__ . '/../app/database.php';
require __DIR__ . '/../app/academic.php';
require __DIR__ . '/../app/catalog.php';

if (PHP_SAPI !== 'cli' || !in_array($argc, [2, 3], true) || ($argc === 3 && $argv[2] !== '--apply')) {
    fwrite(STDERR, "Uso: php bin/import-catalog.php catálogo-revisado.json [--apply]\n");
    exit(1);
}
try {
    $file = file_get_contents($argv[1], false, null, 0, 2097153);
    if ($file === false || strlen($file) > 2097152) {
        throw new DomainException('Usa un catálogo JSON de hasta 2 MB.');
    }
    $source = json_decode($file, true, flags: JSON_THROW_ON_ERROR);
    if (empty($source['careers']) || empty($source['subjects'])) {
        throw new DomainException('El catálogo requiere carreras y materias.');
    }
    $catalog = ['careers' => [], 'subjects' => [], 'teachers' => []];
    foreach ($source['careers'] as $career) {
        $catalog['careers'][careerKey($career)] = academicName($career);
    }
    foreach ($source['subjects'] as $row) {
        $career = careerKey($row['career']);
        $level = integer($row['level'], 3);
        $code = strtoupper(trim($row['code']));
        $name = academicName($row['name']);
        $key = "$career|$level|$code";
        if (!isset($catalog['careers'][$career]) || isset($catalog['subjects'][$key]) ||
            !preg_match('~^[A-Z0-9][A-Z0-9._/\-]{0,39}$~D', $code)) {
            throw new DomainException("Materia inválida o repetida: $code.");
        }
        $catalog['subjects'][$key] = compact('career', 'level', 'code', 'name') + ['previous_names' => $row['previous_names'] ?? []];
    }
    if ($argc === 3) {
        $db = connect(require __DIR__ . '/../config.php');
        $admin = $db->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
        if (!$admin) {
            throw new DomainException('Crea primero el administrador.');
        }
        applyCatalog($db, $admin, $catalog);
    }
    echo count($catalog['careers']) . ' carreras · ' . count($catalog['subjects']) . ' materias · '
        . ($argc === 3 ? 'catálogo aplicado' : 'revisión') . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Importación fallida. " . ($error instanceof PDOException ? "Error de base de datos ({$error->getCode()})." : $error->getMessage()) . PHP_EOL);
    exit(1);
}
