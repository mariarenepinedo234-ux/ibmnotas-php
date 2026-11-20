<?php
declare(strict_types=1);

require __DIR__ . '/../app/database.php';
require __DIR__ . '/../app/academic.php';
require __DIR__ . '/../app/groups.php';
require __DIR__ . '/../app/catalog.php';

if (PHP_SAPI !== 'cli' || !in_array($argc, [2, 3], true) || ($argc === 3 && $argv[2] !== '--apply')) {
    fwrite(STDERR, "Uso: php bin/import-groups.php /ruta/privada/grupos.json [--apply]\n");
    exit(1);
}

try {
    $json = file_get_contents($argv[1], false, null, 0, 2097153);
    if ($json === false || strlen($json) > 2097152) {
        throw new DomainException('Usa un archivo JSON de hasta 2 MB.');
    }
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $admission = $data['admission_year'] ?? '';
    $year = $data['academic_year'] ?? '';
    $level = integer($data['level'] ?? null, 3);
    $shiftName = academicName($data['shift'] ?? '');
    $teacherName = academicName($data['teacher'] ?? '');
    $module = $data['module'] ?? [];
    $groups = $data['groups'] ?? [];
    if (!preg_match('/^20\d{2}$/D', $admission) || !preg_match('/^20\d{2}$/D', $year) || $year < $admission ||
        !is_array($groups) || !$groups || count($groups) > 100 || !is_array($module)) {
        throw new DomainException('Indica gestiones, módulo y entre 1 y 100 grupos.');
    }
    $module['name'] = academicName($module['name'] ?? '');
    foreach (['start_date', 'end_date'] as $key) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $module[$key] ?? '');
        if (!$date || $date->format('Y-m-d') !== $module[$key] || $date->format('Y') !== $year) {
            throw new DomainException('Las fechas deben pertenecer a la gestión cursada.');
        }
    }
    if ($module['start_date'] > $module['end_date']) {
        throw new DomainException('El rango de fechas es inválido.');
    }
    $validated = [];
    foreach ($groups as $group) {
        $career = careerKey(academicName($group['career'] ?? ''));
        $subject = academicName($group['subject'] ?? '');
        $code = empty($group['code']) ? null : strtoupper(trim($group['code']));
        $students = $group['students'] ?? [];
        if (!is_array($students) || !$students || count($students) > 200 ||
            ($code !== null && !preg_match('~^[A-Z0-9][A-Z0-9._/\-]{0,39}$~D', $code))) {
            throw new DomainException('Revisa el código de materia y la lista de 1 a 200 estudiantes.');
        }
        $names = [];
        foreach ($students as $student) {
            $name = academicName(is_string($student) ? $student : ($student['name'] ?? ''));
            $key = nameKey($name);
            if (isset($names[$key])) {
                throw new DomainException('Hay nombres repetidos en un grupo.');
            }
            $names[$key] = ['name' => $name, 'pending' => ($student['name_pending'] ?? false) === true];
        }
        $validated[] = compact('career', 'subject', 'code', 'names');
    }
    echo count($validated) . " grupos · ingreso $admission · gestión $year · año $level\n";
    if ($argc === 2) {
        exit;
    }
    $db = connect(require __DIR__ . '/../config.php');
    $admin = query($db, "SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$admin) {
        throw new DomainException('Crea primero el administrador.');
    }
    $counts = transaction($db, function () use ($db, $admin, $admission, $year, $level, $shiftName, $teacherName, $module, $validated) {
        $years = [];
        foreach (array_unique([$admission, $year]) as $label) {
            query($db, 'INSERT INTO years (name) VALUES (?) ON DUPLICATE KEY UPDATE name=name', [$label]);
            $years[$label] = query($db, 'SELECT id FROM years WHERE name=? FOR UPDATE', [$label])->fetchColumn();
        }
        $admissionId = $years[$admission];
        $yearId = $years[$year];
        $shifts = query($db, 'SELECT id,name FROM shifts')->fetchAll();
        $shifts = array_filter($shifts, static fn ($row) => nameKey($row['name']) === nameKey($shiftName));
        if (count($shifts) !== 1) {
            throw new DomainException('El turno debe coincidir con un registro.');
        }
        $shift = reset($shifts)['id'];
        $teachers = query($db, "SELECT id,name FROM users WHERE role='teacher'")->fetchAll();
        $tokens = explode(' ', teacherKey($teacherName));
        $matches = static fn ($row) => !array_diff($tokens, explode(' ', teacherKey($row['name'])));
        $directory = array_filter(query($db, 'SELECT * FROM teacher_directory ORDER BY id FOR UPDATE')->fetchAll(), $matches);
        $candidates = array_filter($teachers, $matches);
        if (count($directory) > 1 || (!$directory && count($candidates) !== 1)) {
            throw new DomainException('El docente debe coincidir con un único registro.');
        }
        if ($directory) {
            $entry = reset($directory);
            $teacher = $entry['user_id'];
            if (!$teacher) {
                $accounts = array_filter($teachers, static fn ($row) => teacherKey($row['name']) === teacherKey($entry['name']));
                if (count($accounts) > 1) {
                    throw new DomainException('Hay docentes homónimos.');
                }
                if (!$accounts) {
                    query($db, "INSERT INTO users (name,role) VALUES (?,'teacher')", [$entry['name']]);
                }
                $teacher = $accounts ? reset($accounts)['id'] : (int)$db->lastInsertId();
                query($db, 'UPDATE teacher_directory SET user_id=? WHERE id=?', [$teacher, $entry['id']]);
            }
        } else {
            $teacher = reset($candidates)['id'];
        }
        if (query($db, 'SELECT 1 FROM modules WHERE year_id=? AND admission_year_id=? LIMIT 1', [$yearId, $admissionId])->fetchColumn()) {
            throw new DomainException('El ingreso tiene calendario propio. Registra el grupo en su módulo desde el portal.');
        }
        $calendar = query($db, 'SELECT * FROM modules WHERE year_id=? AND admission_year_id IS NULL FOR UPDATE', [$yearId])->fetchAll();
        $same = array_filter($calendar, static fn ($row) => nameKey($row['name']) === nameKey($module['name']) || $row['start_date'] === $module['start_date']);
        if (count($same) > 1 || (!$same && count($calendar) >= 4)) {
            throw new DomainException('Revisa los módulos registrados para esta gestión.');
        }
        $moduleId = $same ? reset($same)['id'] : null;
        foreach ($calendar as $row) {
            if ($row['id'] !== $moduleId && $row['start_date'] <= $module['end_date'] && $row['end_date'] >= $module['start_date']) {
                throw new DomainException('Las fechas coinciden con otro módulo.');
            }
        }
        if ($moduleId) {
            query($db, 'UPDATE modules SET name=?,start_date=?,end_date=? WHERE id=?',
                [$module['name'], $module['start_date'], $module['end_date'], $moduleId]);
        } else {
            query($db, 'INSERT INTO modules (name,year_id,start_date,end_date) VALUES (?,?,?,?)',
                [$module['name'], $yearId, $module['start_date'], $module['end_date']]);
            $moduleId = (int)$db->lastInsertId();
        }
        $careerSql = careerName('c', '?');
        $careers = query($db, "SELECT c.id,c.name,$careerSql AS historical_name FROM careers c ORDER BY c.id FOR UPDATE", [$year])->fetchAll();
        $created = 0;
        foreach ($validated as $group) {
            $matches = array_filter($careers, static fn ($row) => in_array($group['career'], [careerKey($row['name']), careerKey($row['historical_name'])], true));
            if (count($matches) !== 1) {
                throw new DomainException('Una carrera debe coincidir con un único registro.');
            }
            $career = reset($matches)['id'];
            $subjects = query($db, 'SELECT id,name,code,active FROM subjects WHERE career_id=? AND level=? FOR UPDATE', [$career,$level])->fetchAll();
            $matches = array_filter($subjects, static fn ($row) => nameKey($row['name']) === nameKey($group['subject'])
                || ($group['code'] !== null && strtoupper($row['code'] ?? '') === $group['code']));
            if (count($matches) > 1 || ($matches && !reset($matches)['active'])) {
                throw new DomainException('La materia está inactiva o coincide con varios registros.');
            }
            if (!$matches) {
                query($db, 'INSERT INTO subjects (name,code,career_id,level) VALUES (?,?,?,?)', [$group['subject'],$group['code'],$career,$level]);
            }
            $subject = $matches ? reset($matches)['id'] : (int)$db->lastInsertId();
            $existing = query($db, 'SELECT s.*,u.name FROM students s JOIN users u ON u.id=s.user_id
                WHERE s.career_id=? AND s.admission_year_id=? ORDER BY s.user_id FOR UPDATE', [$career,$admissionId])->fetchAll();
            $ids = [];
            foreach ($group['names'] as $key => $entry) {
                $matches = array_filter($existing, static fn ($row) => nameKey($row['name']) === $key);
                $record = $matches ? reset($matches) : null;
                if (count($matches) > 1 || ($record && ($record['shift_id'] !== $shift || $record['level'] !== $level))) {
                    throw new DomainException('Hay homónimos o estudiantes con otro turno o año académico.');
                }
                if ($record) {
                    $ids[] = $record['user_id'];
                } else {
                    query($db, "INSERT INTO users (name,role) VALUES (?,'student')", [$entry['name']]);
                    $student = (int)$db->lastInsertId();
                    query($db, 'INSERT INTO students (user_id,career_id,shift_id,admission_year_id,level,name_pending) VALUES (?,?,?,?,?,?)',
                        [$student,$career,$shift,$admissionId,$level,$entry['pending']]);
                    $ids[] = $student;
                    $created++;
                }
            }
            saveGroup($db, $admin, ['student_ids'=>$ids, 'subject_id'=>$subject, 'module_id'=>$moduleId, 'shift_id'=>$shift,
                'teacher_id'=>$teacher, 'admission_year_id'=>$admissionId], true);
        }
        audit($db, $admin, 'Grupos históricos importados', "$admission → $year · $created estudiantes creados");
        return $created;
    });
    echo "$counts estudiantes nuevos con CI pendiente.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "La carga falló. " . ($error instanceof PDOException ? "Error de base de datos ({$error->getCode()})." : $error->getMessage()) . PHP_EOL);
    exit(1);
}
