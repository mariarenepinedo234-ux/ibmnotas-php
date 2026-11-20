<?php
declare(strict_types=1);

function careerKey(string $name): string
{
    $key = nameKey($name);
    return $key === 'COMERCIO INTERNACIONAL' ? 'COMERCIO INTERNACIONAL Y ADMINISTRACION ADUANERA' : $key;
}

function teacherKey(string $name): string
{
    return preg_replace('/^(LIC|ING|DR|DRA|MSC|TEC)\.?\s+/', '', nameKey($name));
}

function catalogPreview(): array
{
    $file = $_FILES['catalog'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $file['size'] > 2097152 || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('Adjunta un Excel .xlsx de hasta 2 MB.');
    }
    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name'], ZipArchive::RDONLY) !== true) {
        throw new DomainException('El archivo .xlsx no es válido.');
    }
    try {
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $total += $zip->statIndex($index)['size'];
            if ($total > 41943040 || $zip->numFiles > 250) {
                throw new DomainException('El Excel supera el tamaño permitido al descomprimir.');
            }
        }
    } finally {
        $zip->close();
    }
    require_once __DIR__ . '/../vendor/autoload.php';
    $reader = new PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $sheetName = null;
    foreach ($reader->listWorksheetNames($file['tmp_name']) as $candidate) {
        if (nameKey($candidate) === 'BASE DE DATOS') {
            $sheetName = $candidate;
            break;
        }
    }
    if ($sheetName === null) {
        throw new DomainException('Falta la hoja BASE DE DATOS.');
    }
    foreach ($reader->listWorksheetInfo($file['tmp_name']) as $info) {
        if ($info['worksheetName'] === $sheetName && ($info['totalRows'] > 5000 || $info['totalColumns'] > 60)) {
            throw new DomainException('La hoja supera el límite de filas o columnas.');
        }
    }
    $workbook = $reader->setLoadSheetsOnly($sheetName)->setReadDataOnly(true)->load($file['tmp_name']);
    $sheet = $workbook->getSheet(0);
    $lastRow = $sheet->getHighestDataRow();
    $lastColumn = PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    if ($lastRow > 5000 || $lastColumn > 60) {
        throw new DomainException('La hoja supera el límite de filas o columnas.');
    }
    $read = static function (int $row, int $column) use ($sheet): string {
        if (!$sheet->cellExists([$column, $row])) {
            return '';
        }
        $cell = $sheet->getCell([$column, $row]);
        if ($cell->isFormula()) {
            return '';
        }
        $value = $cell->getValue();
        if ($value instanceof PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }
        return is_string($value) || is_numeric($value) ? trim(preg_replace('/\s+/u', ' ', (string)$value)) : '';
    };
    $careers = $teachers = $subjects = $heads = $warnings = [];
    $section = '';
    for ($row = 1; $row <= $lastRow; $row++) {
        $value = $read($row, 2);
        $key = nameKey($value);
        if (in_array($key, ['CARRERAS','DOCENTE','DOCENTES'], true)) {
            $section = $key;
        } elseif (in_array($key, ['TURNO','CURSO','TRIMESTRE'], true)) {
            $section = '';
        } elseif ($value !== '' && $section === 'CARRERAS') {
            $careers[careerKey($value)] ??= academicName($value);
        } elseif ($value !== '' && $section !== '') {
            $teachers[teacherKey($value)] ??= academicName($value);
        }
    }
    for ($row = 1; $row <= $lastRow; $row++) {
        for ($column = 4; $column <= $lastColumn; $column++) {
            $value = $read($row, $column);
            if ($value === '') {
                continue;
            }
            $code = nameKey($read($row, $column + 1));
            if ($code === 'CODIGO') {
                if (!preg_match('/^(.*?) (1ER|2DO|3ER|PRIMER|PRIMERO|SEGUNDO|TERCER|TERCERO) ANO$/D', nameKey($value), $matches) ||
                    !isset($careers[careerKey($matches[1])])) {
                    throw new DomainException("Encabezado de carrera y año inválido en fila $row.");
                }
                $level = preg_match('/^(1|PRIM)/', $matches[2]) ? 1 : (preg_match('/^(2|SEG)/', $matches[2]) ? 2 : 3);
                $heads[$column] = [careerKey($matches[1]), $level];
                $column++;
                continue;
            }
            if (!isset($heads[$column])) {
                continue;
            }
            if ($code === '') {
                $warnings[] = "Se omitió una materia sin código en fila $row.";
                continue;
            }
            if (!preg_match('~^[A-Z0-9][A-Z0-9._/\-]{0,39}$~D', $code)) {
                throw new DomainException("Código inválido en fila $row.");
            }
            [$career, $level] = $heads[$column];
            $id = "$career|$level|$code";
            if (isset($subjects[$id]) && nameKey($subjects[$id]['name']) !== nameKey($value)) {
                throw new DomainException("Código repetido con distinto nombre en fila $row.");
            }
            $subjects[$id] ??= ['career' => $career, 'level' => $level, 'code' => $code, 'name' => academicName($value)];
            $column++;
        }
    }
    if (!$careers || !$subjects) {
        throw new DomainException('El Excel debe contener carreras y materias con códigos.');
    }
    return ['careers' => $careers, 'subjects' => $subjects, 'teachers' => $teachers, 'warnings' => $warnings];
}

function applyCatalog(PDO $db, int $actor, array $catalog): void
{
    transaction($db, function () use ($db, $actor, $catalog) {
        $careers = $db->query('SELECT id,name FROM careers ORDER BY id FOR UPDATE')->fetchAll();
        $histories = $db->query('SELECT career_id AS id,name FROM career_names_by_year')->fetchAll();
        $matches = [];
        foreach ([...$careers, ...$histories] as $row) {
            $matches[careerKey($row['name'])][$row['id']] = $row['id'];
        }
        $ids = $positions = [];
        foreach ($catalog['careers'] as $key => $name) {
            $alternatives = $matches[$key] ?? [];
            if (count($alternatives) > 1) {
                throw new DomainException('Hay más de una carrera con un nombre equivalente.');
            }
            if (!$alternatives) {
                query($db, 'INSERT INTO careers (name) VALUES (?)', [$name]);
            }
            $ids[$key] = $alternatives ? reset($alternatives) : (int)$db->lastInsertId();
        }
        foreach ($catalog['subjects'] as $row) {
            $careerId = $ids[$row['career']];
            $level = $row['level'];
            $code = $row['code'];
            $key = "$careerId:$level";
            $position = $positions[$key] = ($positions[$key] ?? 0) + 1;
            $subjectId = query($db, 'SELECT id FROM subjects WHERE career_id=? AND level=? AND code_key=? FOR UPDATE',
                [$careerId, $level, $code])->fetchColumn();
            if (!$subjectId) {
                $uncoded = query($db, 'SELECT id,name FROM subjects WHERE career_id=? AND level=? AND code IS NULL FOR UPDATE',
                    [$careerId, $level])->fetchAll();
                $aliases = array_map('nameKey', [$row['name'], ...($row['previous_names'] ?? [])]);
                $compatible = array_filter($uncoded, static fn ($old) => in_array(nameKey($old['name']), $aliases, true));
                if (count($compatible) > 1) {
                    throw new DomainException('Hay varias materias sin código con el mismo nombre.');
                }
                $subjectId = $compatible ? reset($compatible)['id'] : null;
            }
            if ($subjectId) {
                query($db, 'UPDATE subjects SET name=?,code=?,catalog_order=? WHERE id=?', [$row['name'], $code, $position, $subjectId]);
            } else {
                query($db, 'INSERT INTO subjects (name,career_id,level,code,catalog_order) VALUES (?,?,?,?,?)',
                    [$row['name'], $careerId, $level, $code, $position]);
            }
        }
        $teachers = $db->query("SELECT id,name FROM users WHERE role='teacher'")->fetchAll();
        foreach ($catalog['teachers'] as $key => $name) {
            if (query($db, 'SELECT 1 FROM teacher_directory WHERE name_key=?', [$key])->fetchColumn()) {
                continue;
            }
            $accounts = array_filter($teachers, static fn ($teacher) => teacherKey($teacher['name']) === $key);
            $userId = count($accounts) === 1 ? reset($accounts)['id'] : null;
            query($db, 'INSERT INTO teacher_directory (name,name_key,user_id) VALUES (?,?,?)', [$name, $key, $userId]);
        }
        audit($db, $actor, 'Catálogo Excel importado', count($catalog['subjects']) . ' materias revisadas.');
    });
}
