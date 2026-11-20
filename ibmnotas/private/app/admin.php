<?php
declare(strict_types=1);

function adminAction(PDO $db, array $user, string $action, array $input, array $config): string
{
    $actor = $user['id'];
    if ($action === 'catalog-preview') {
        $_SESSION['catalog_preview'] = null;
        $_SESSION['catalog_preview'] = ['until' => time() + 900, 'catalog' => catalogPreview()];
        return '?page=import';
    }
    if ($action === 'catalog-apply') {
        $preview = $_SESSION['catalog_preview'] ?? null;
        if (!$preview || $preview['until'] < time()) {
            throw new DomainException('La vista previa venció. Selecciona el archivo de nuevo.');
        }
        applyCatalog($db, $actor, $preview['catalog']);
        unset($_SESSION['catalog_preview']);
        return '?page=catalog';
    }
    if ($action === 'delete') {
        return removeRecord($db, $actor, $input['type'] ?? '', integer($input['id'] ?? null), $input);
    }
    if ($action === 'remove-enrollment') {
        $offering = integer($input['offering_id'] ?? null);
        removeEnrollment($db, $actor, $offering, integer($input['enrollment_id'] ?? null), $input);
        return '?offering=' . $offering;
    }
    if ($action === 'group') {
        return '?offering=' . saveGroup($db, $actor, $input);
    }
    if (in_array($action, ['part-link', 'part-continue', 'part-recover', 'part-status'], true)) {
        return '?offering=' . subjectPartAction($db, $actor, $action, $input);
    }
    if ($action === 'backup') {
        $filename = writeBackup($db, $config['backup_directory']);
        audit($db, $actor, 'Copia de seguridad creada', $filename);
        return '?page=backups';
    }
    if ($action === 'plan-upload' || $action === 'plan-delete') {
        changePlan($db, $actor, $action, $input);
        return '?page=plans';
    }
    if ($action === 'rename') {
        renameCareer($db, $actor, integer($input['career_id']), $input['year'], academicName($input['name']));
        return '?page=catalog';
    }
    if ($action === 'enroll') {
        $offering = integer($input['offering_id']);
        $students = studentIds($input['student_ids'] ?? [$input['student_id'] ?? null]);
        if (!$students) {
            throw new DomainException('Selecciona al menos un estudiante.');
        }
        transaction($db, function () use ($db, $actor, $offering, $students, $input) {
            foreach ($students as $student) {
                enroll($db, $actor, $offering, $student, isset($input['retake']));
            }
        });
        return '?offering=' . $offering;
    }
    return transaction($db, function () use ($db, $actor, $action, $input) {
        $id = static fn ($key, $max = 2147483647) => integer($input[$key] ?? null, $max);
        $name = static fn () => academicName($input['name'] ?? '');
        $page = '?page=catalog';
        switch ($action) {
            case 'career':
            case 'shift':
            case 'year':
                $table = ['career'=>'careers', 'shift'=>'shifts', 'year'=>'years'][$action];
                $value = $name();
                if ($action === 'year' && !preg_match('/^20\d{2}$/D', $value)) {
                    throw new DomainException('La gestión debe estar entre 2000 y 2099.');
                }
                if ($action === 'career') {
                    $db->query('SELECT id FROM careers ORDER BY id FOR UPDATE')->fetchAll();
                    $names = $db->query('SELECT name FROM careers UNION ALL SELECT name FROM career_names_by_year')->fetchAll(PDO::FETCH_COLUMN);
                    if (in_array(nameKey($value), array_map('nameKey', $names), true)) {
                        throw new DomainException('Otra carrera ya utiliza ese nombre.');
                    }
                }
                query($db, "INSERT INTO $table (name) VALUES (?)", [$value]);
                break;
            case 'subject':
                $subjectId = empty($input['subject_id']) ? null : $id('subject_id');
                $code = strtoupper(trim($input['code'] ?? ''));
                if ($code !== '' && !preg_match('~^[A-Z0-9][A-Z0-9._/\-]{0,39}$~D', $code)) {
                    throw new DomainException('Código de materia inválido.');
                }
                $values = [$name(), $code === '' ? null : $code, $id('career_id'), $id('level', 3)];
                if ($subjectId) {
                    $old = query($db, 'SELECT career_id,level FROM subjects WHERE id=? FOR UPDATE', [$subjectId])->fetch();
                    if (!$old || (($old['career_id'] !== $values[2] || $old['level'] !== $values[3]) &&
                        query($db, 'SELECT 1 FROM offerings WHERE subject_id=? LIMIT 1', [$subjectId])->fetchColumn())) {
                        throw new DomainException('La asignación existente exige conservar la carrera y el año.');
                    }
                    query($db, 'UPDATE subjects SET name=?,code=?,career_id=?,level=?,active=? WHERE id=?',
                        [...$values, isset($input['active']), $subjectId]);
                } else {
                    query($db, 'INSERT INTO subjects (name, code, career_id, level) VALUES (?, ?, ?, ?)', $values);
                }
                break;
            case 'module':
                $yearId = $id('year_id');
                $year = query($db, 'SELECT name FROM years WHERE id=? FOR UPDATE', [$yearId])->fetchColumn();
                $scope = empty($input['admission_year_id']) ? null : $id('admission_year_id');
                $moduleId = empty($input['module_id']) ? null : $id('module_id');
                if ($moduleId) {
                    $previous = query($db, 'SELECT year_id,admission_year_id FROM modules WHERE id=? FOR UPDATE', [$moduleId])->fetch();
                    if (!$previous || (($previous['year_id'] !== $yearId || $previous['admission_year_id'] !== $scope) &&
                        query($db, 'SELECT 1 FROM offerings WHERE module_id=? LIMIT 1', [$moduleId])->fetchColumn())) {
                        throw new DomainException('El módulo tiene asignaciones o ya no existe. Conserva su gestión y calendario.');
                    }
                }
                $admission = $scope === null ? null : query($db, 'SELECT name FROM years WHERE id=?', [$scope])->fetchColumn();
                $start = $input['start_date'];
                $end = $input['end_date'];
                foreach ([$start, $end] as $date) {
                    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                    if (!$parsed || $parsed->format('Y-m-d') !== $date || substr($date, 0, 4) !== $year) {
                        throw new DomainException('Las fechas deben pertenecer a la gestión cursada.');
                    }
                }
                if ($start > $end || ($scope !== null && (!$admission || $admission > $year))) {
                    throw new DomainException('Calendario inválido.');
                }
                $peers = query($db, 'SELECT start_date,end_date FROM modules WHERE year_id=? AND admission_year_id <=> ? AND id<>? FOR UPDATE',
                    [$yearId, $scope, $moduleId ?? 0])->fetchAll();
                if (count($peers) >= 4) {
                    throw new DomainException('El calendario ya tiene cuatro módulos.');
                }
                foreach ($peers as $peer) {
                    if ($peer['start_date'] <= $end && $peer['end_date'] >= $start) {
                        throw new DomainException('Las fechas coinciden con otro módulo del mismo calendario.');
                    }
                }
                $values = [$name(), $yearId, $scope, $start, $end];
                if ($moduleId) {
                    query($db, 'UPDATE modules SET name=?,year_id=?,admission_year_id=?,start_date=?,end_date=? WHERE id=?',
                        [...$values, $moduleId]);
                } else {
                    query($db, 'INSERT INTO modules (name,year_id,admission_year_id,start_date,end_date) VALUES (?,?,?,?,?)', $values);
                }
                break;
            case 'student':
            case 'teacher':
                $ci = trim($input['ci'] ?? '');
                $namePending = $action === 'student' && isset($input['name_pending']);
                if (($ci !== '' && (strlen($ci) < 5 || $namePending)) || ($ci === '' && $action === 'teacher') || strlen($ci) > 40) {
                    throw new DomainException('Escribe un CI de 5 a 40 bytes. Confirma el nombre antes de habilitar el acceso.');
                }
                query($db, 'INSERT INTO users (ci,name,role,password) VALUES (?,?,?,?)',
                    [$ci ?: null, $name(), $action, $ci === '' ? null : accountPasswordHash($ci)]);
                if ($action === 'student') {
                    query($db, 'INSERT INTO students (user_id,career_id,shift_id,admission_year_id,level,name_pending) VALUES (?,?,?,?,?,?)',
                        [(int)$db->lastInsertId(), $id('career_id'), $id('shift_id'), $id('year_id'), $id('level', 3), $namePending]);
                }
                $page = '?page=people';
                break;
            case 'offering':
                $teacher = $id('teacher_id');
                $subject = $id('subject_id');
                if (!query($db, "SELECT id FROM users WHERE id=? AND role='teacher'", [$teacher])->fetchColumn() ||
                    !query($db, 'SELECT id FROM subjects WHERE id=? AND active=1', [$subject])->fetchColumn()) {
                    throw new DomainException('Selecciona una materia activa y un docente.');
                }
                query($db, 'INSERT INTO offerings (subject_id,module_id,shift_id,teacher_id) VALUES (?,?,?,?)',
                    [$subject, $id('module_id'), $id('shift_id'), $teacher]);
                $page = '?offering=' . $db->lastInsertId();
                break;
            case 'unlock':
                $offering = $id('offering_id');
                $hours = $id('hours', 168);
                $reason = $name();
                query($db, 'UPDATE offerings SET unlocked_until=? WHERE id=?', [gmdate('Y-m-d H:i:s', time() + $hours * 3600), $offering]);
                $page = '?offering=' . $offering;
                break;
            case 'activate-teacher':
                $entry = query($db, 'SELECT * FROM teacher_directory WHERE id=? FOR UPDATE', [$id('directory_id')])->fetch();
                $ci = trim($input['ci'] ?? '');
                if (!$entry || strlen($ci) < 5 || strlen($ci) > 40) {
                    throw new DomainException('Confirma un CI de 5 a 40 bytes.');
                }
                $password = accountPasswordHash($ci);
                if ($entry['user_id']) {
                    if (query($db, 'UPDATE users SET ci=?,password=?,must_change=1 WHERE id=? AND ci IS NULL',
                        [$ci, $password, $entry['user_id']])->rowCount() !== 1) {
                        throw new DomainException('El docente ya tiene acceso.');
                    }
                } else {
                    query($db, "INSERT INTO users (ci,name,role,password) VALUES (?,?,'teacher',?)", [$ci, $entry['name'], $password]);
                    query($db, 'UPDATE teacher_directory SET user_id=? WHERE id=?', [(int)$db->lastInsertId(), $entry['id']]);
                }
                audit($db, $actor, 'Cuenta docente habilitada', "Directorio {$entry['id']}");
                return '?page=people';
            case 'reset-password':
                $target = $id('user_id');
                $account = query($db, "SELECT id,ci FROM users WHERE id=? AND role IN ('student','teacher') FOR UPDATE", [$target])->fetch();
                $password = $input['password'] ?? '';
                if (!$account || !$account['ci'] || !is_string($password) || strlen($password) < 8 ||
                    strlen($password) > 160 || $password === $account['ci']) {
                    throw new DomainException('Indica una contraseña temporal de 8 a 160 bytes, distinta del CI.');
                }
                query($db, 'UPDATE users SET password=?,must_change=1 WHERE id=?', [accountPasswordHash($password), $target]);
                audit($db, $actor, 'Restablecimiento de contraseña', "Usuario $target");
                return '?page=people';
            case 'student-edit':
            case 'student-status':
            case 'transfer':
                $student = $id('student_id');
                if (!query($db, 'SELECT user_id FROM students WHERE user_id=? FOR UPDATE', [$student])->fetchColumn()) {
                    throw new DomainException('Estudiante no encontrado.');
                }
                if ($action === 'student-edit') {
                    $account = query($db, 'SELECT ci,password FROM users WHERE id=? FOR UPDATE', [$student])->fetch();
                    $ci = trim($input['ci'] ?? '');
                    $pending = isset($input['name_pending']);
                    if (($account['ci'] && $ci === '') || ($ci !== '' && ($pending || strlen($ci) < 5 || strlen($ci) > 40))) {
                        throw new DomainException('Confirma el nombre y un CI entre 5 y 40 bytes. Conserva un CI ya registrado.');
                    }
                    if (!$account['ci'] && $ci !== '') {
                        query($db, 'UPDATE users SET name=?,ci=?,password=?,must_change=1 WHERE id=?',
                            [$name(), $ci, accountPasswordHash($ci), $student]);
                    } else {
                        query($db, 'UPDATE users SET name=?,ci=? WHERE id=?', [$name(), $ci === '' ? null : $ci, $student]);
                    }
                    query($db, 'UPDATE students SET career_id=?,shift_id=?,admission_year_id=?,level=?,name_pending=? WHERE user_id=?',
                        [$id('career_id'), $id('shift_id'), $id('year_id'), $id('level', 3), $pending, $student]);
                } elseif ($action === 'student-status') {
                    query($db, 'UPDATE users SET blocked=? WHERE id=?', [isset($input['blocked']), $student]);
                } elseif ($action === 'transfer') {
                    $reason = $name();
                    query($db, 'UPDATE students SET shift_id=?,level=? WHERE user_id=?', [$id('shift_id'), $id('level', 3), $student]);
                }
                $page = '?page=people';
                break;
            default:
                throw new DomainException('Acción desconocida.');
        }
        audit($db, $actor, 'Administración académica', $action . ' · ' . ($input['name'] ?? '') . ' · ' . ($student ?? $offering ?? ''));
        return $page;
    });
}
