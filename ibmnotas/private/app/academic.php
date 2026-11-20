<?php
declare(strict_types=1);

function academicName(string $value): string
{
    $name = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    if ($name === '' || mb_strlen($name) > 160) {
        throw new DomainException('Escribe un nombre de hasta 160 caracteres.');
    }
    return $name;
}

function nameKey(string $name): string
{
    return strtr(mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? $name)), [
        'Á'=>'A', 'É'=>'E', 'Í'=>'I', 'Ó'=>'O', 'Ú'=>'U', 'Ü'=>'U', 'Ñ'=>'N',
    ]);
}

function integer(mixed $value, int $max = 2147483647): int
{
    $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
    if ($result === false) {
        throw new DomainException('Selecciona un valor válido.');
    }
    return $result;
}

function careerName(string $career, string $year): string
{
    return "COALESCE((SELECT h.name FROM career_names_by_year h WHERE h.career_id = $career.id
        AND h.effective_year <= $year ORDER BY h.effective_year DESC LIMIT 1), $career.name)";
}

function offeringsSql(): string
{
    $career = careerName('c', 'y.name');
    return "SELECT o.*, s.name AS subject, s.code, s.level, s.career_id, $career AS career,
        m.name AS module, m.start_date, m.end_date, m.admission_year_id, y.name AS academic_year,
        sh.name AS shift, u.name AS teacher FROM offerings o JOIN subjects s ON s.id = o.subject_id
        JOIN careers c ON c.id = s.career_id JOIN modules m ON m.id = o.module_id JOIN years y ON y.id = m.year_id
        JOIN shifts sh ON sh.id = o.shift_id JOIN users u ON u.id = o.teacher_id";
}

function gradebook(PDO $db, array $user, int $offeringId): array
{
    if (!in_array($user['role'], ['teacher', 'admin'], true)) {
        throw new DomainException('Acceso restringido.');
    }
    $offering = query($db, offeringsSql() . ' WHERE o.id = ?', [$offeringId])->fetch();
    if (!$offering || ($user['role'] === 'teacher' && $offering['teacher_id'] !== $user['id'])) {
        throw new DomainException('Asignación no disponible.');
    }
    $rows = query($db, 'SELECT e.*, u.name, u.ci FROM enrollments e
        JOIN users u ON u.id = e.student_id WHERE e.offering_id = ? ORDER BY u.name, u.id', [$offeringId])->fetchAll();
    return [$offering, $rows];
}

function canGrade(array $offering): bool
{
    $deadline = new DateTimeImmutable($offering['end_date'] . ' 23:59:59', new DateTimeZone('America/La_Paz'));
    $hours = 72;
    while ($hours > 0) {
        $deadline = $deadline->modify('+1 hour');
        if ((int)$deadline->format('N') < 6) {
            $hours--;
        }
    }
    $unlock = $offering['unlocked_until'];
    return time() <= $deadline->getTimestamp() || ($unlock !== null && time() <= strtotime($unlock . ' UTC'));
}

function gradeValues(array $input): array
{
    $values = [];
    foreach (['saber' => 40, 'hacer' => 40, 'ser' => 20] as $key => $max) {
        $value = $input[$key] ?? '';
        if ($value === '') {
            $values[] = null;
            continue;
        }
        if (!is_string($value) || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $value) || (float)$value > $max) {
            throw new DomainException(ucfirst($key) . " admite de 0 a $max con dos decimales.");
        }
        $values[] = (float)$value;
    }
    $total = in_array(null, $values, true) ? null : round(array_sum($values), 2);
    if ($total !== null && $total < 1) {
        throw new DomainException('La nota final debe estar entre 1 y 100.');
    }
    return [...$values, $total];
}

function saveGrades(PDO $db, array $user, int $offeringId, array $items): void
{
    transaction($db, function () use ($db, $user, $offeringId, $items) {
        [, $rows] = gradebook($db, $user, $offeringId);
        $current = query($db, 'SELECT o.*, m.end_date FROM offerings o JOIN modules m ON m.id=o.module_id
            WHERE o.id=? FOR UPDATE', [$offeringId])->fetch();
        if (!$current || ($user['role'] === 'teacher' && ($current['teacher_id'] !== $user['id'] || !canGrade($current)))) {
            throw new DomainException('Asignación no disponible para editar.');
        }
        $allowed = array_column($rows, null, 'id');
        ksort($items, SORT_NUMERIC);
        foreach ($items as $id => $input) {
            if (!isset($allowed[$id]) || !is_array($input)) {
                throw new DomainException('Inscripción inválida.');
            }
            $previous = query($db, 'SELECT saber, hacer, ser, grade, updated_at FROM enrollments WHERE id=? AND offering_id=? FOR UPDATE',
                [$id, $offeringId])->fetch();
            if (!$previous) {
                throw new DomainException('La inscripción cambió. Actualiza la página.');
            }
            $submitted = [$input['saber'] ?? null, $input['hacer'] ?? null, $input['ser'] ?? null];
            $saved = array_map(static fn ($value) => $value === null ? '' : (string)$value,
                [$previous['saber'], $previous['hacer'], $previous['ser']]);
            if ($submitted === $saved) {
                continue;
            }
            if (($input['version'] ?? '') !== (string)$previous['updated_at']) {
                throw new DomainException('Las notas cambiaron. Actualiza la página antes de guardar.');
            }
            $values = gradeValues($input);
            query($db, 'UPDATE enrollments SET saber=?, hacer=?, ser=?, grade=?, updated_at=CURRENT_TIMESTAMP(6) WHERE id=?', [...$values, $id]);
            audit($db, $user['id'], 'Notas actualizadas', "Inscripción $id: " . ($previous['grade'] ?? 'pendiente') . ' → ' . ($values[3] ?? 'pendiente'));
        }
    });
}

function enroll(PDO $db, int $actor, int $offeringId, int $studentId, bool $allowRetake): void
{
    $offering = query($db, 'SELECT o.*, s.career_id, s.level, m.year_id, m.admission_year_id
        FROM offerings o JOIN subjects s ON s.id=o.subject_id JOIN modules m ON m.id=o.module_id
        WHERE o.id=? FOR UPDATE', [$offeringId])->fetch();
    $student = query($db, 'SELECT * FROM students WHERE user_id=? FOR UPDATE', [$studentId])->fetch();
    if (!$offering || !$student || $student['career_id'] !== $offering['career_id'] ||
        $student['shift_id'] !== $offering['shift_id'] || $student['level'] < $offering['level']) {
        throw new DomainException('La carrera, el turno y el año deben ser compatibles.');
    }
    $retake = $student['level'] > $offering['level'];
    $group = query($db, 'SELECT * FROM academic_groups WHERE offering_id=?', [$offeringId])->fetch();
    if (($retake && !$allowRetake) || (!$retake && $group && $group['admission_year_id'] !== $student['admission_year_id'])) {
        throw new DomainException('El ingreso no coincide o falta autorizar la materia pendiente.');
    }
    if (!$retake) {
        $admission = $student['admission_year_id'];
        $scope = $offering['admission_year_id'];
        if (($scope !== null && $scope !== $admission) || ($scope === null && query($db,
            'SELECT 1 FROM modules WHERE year_id=? AND admission_year_id=? LIMIT 1', [$offering['year_id'], $admission])->fetchColumn())) {
            throw new DomainException('Selecciona el calendario del ingreso del estudiante.');
        }
    }
    query($db, 'INSERT INTO enrollments (student_id, offering_id, is_retake) VALUES (?, ?, ?)', [$studentId, $offeringId, $retake]);
    if ($group) {
        query($db, 'INSERT INTO academic_group_students (group_id, student_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE group_id=group_id', [$group['id'], $studentId]);
    }
    audit($db, $actor, $retake ? 'Materia pendiente inscrita' : 'Inscripción realizada', "Estudiante $studentId · asignación $offeringId");
}

function renameCareer(PDO $db, int $actor, int $id, string $year, string $name): void
{
    if (!preg_match('/^20[0-9]{2}$/D', $year)) {
        throw new DomainException('La gestión debe estar entre 2000 y 2099.');
    }
    transaction($db, function () use ($db, $actor, $id, $year, $name) {
        if (!query($db, 'SELECT 1 FROM years WHERE name=?', [$year])->fetchColumn()) {
            throw new DomainException('Registra la gestión de vigencia.');
        }
        $careers = $db->query('SELECT id, name FROM careers ORDER BY id FOR UPDATE')->fetchAll();
        if (!in_array($id, array_column($careers, 'id'), true)) {
            throw new DomainException('Carrera no encontrada.');
        }
        $names = query($db, 'SELECT name FROM careers WHERE id<>? UNION ALL
            SELECT name FROM career_names_by_year WHERE career_id<>?', [$id, $id])->fetchAll(PDO::FETCH_COLUMN);
        if (in_array(nameKey($name), array_map('nameKey', $names), true)) {
            throw new DomainException('Otra carrera ya utiliza ese nombre.');
        }
        query($db, 'INSERT INTO career_names_by_year (career_id, effective_year, name) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE name=?', [$id, $year, $name, $name]);
        audit($db, $actor, 'Nombre de carrera actualizado', "Carrera $id · desde $year · $name");
    });
}
