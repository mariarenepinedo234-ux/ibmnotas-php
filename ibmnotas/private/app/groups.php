<?php
declare(strict_types=1);

function studentIds(mixed $selection): array
{
    if (!is_array($selection) || count($selection) > 1000) {
        throw new DomainException('Selecciona hasta 1000 estudiantes.');
    }
    $ids = array_unique(array_map('integer', $selection));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function saveGroup(PDO $db, int $actor, array $input, bool $inTransaction = false): int
{
    $students = studentIds($input['student_ids'] ?? []);
    if (!$students) {
        throw new DomainException('Selecciona estudiantes registrados.');
    }
    $subject = integer($input['subject_id'] ?? null);
    $moduleId = integer($input['module_id'] ?? null);
    $shift = integer($input['shift_id'] ?? null);
    $teacher = integer($input['teacher_id'] ?? null);
    $admission = integer($input['admission_year_id'] ?? null);
    $retakes = isset($input['retake']);
    $save = function () use ($db, $actor, $students, $subject, $moduleId, $shift, $teacher, $admission, $retakes) {
        $module = query($db, 'SELECT m.year_id,y.name AS academic_year FROM modules m JOIN years y ON y.id=m.year_id
            WHERE m.id=? FOR UPDATE', [$moduleId])->fetch();
        $year = query($db, 'SELECT name FROM years WHERE id=?', [$admission])->fetchColumn();
        if (!$module || !$year || $year > $module['academic_year'] ||
            !query($db, 'SELECT 1 FROM subjects WHERE id=? AND active=1', [$subject])->fetchColumn() ||
            !query($db, "SELECT 1 FROM users WHERE id=? AND role='teacher'", [$teacher])->fetchColumn()) {
            throw new DomainException('Revisa la materia, el módulo, el ingreso y el docente del grupo.');
        }
        $offering = query($db, 'SELECT id,teacher_id FROM offerings WHERE subject_id=? AND module_id=? AND shift_id=? FOR UPDATE',
            [$subject, $moduleId, $shift])->fetch();
        if ($offering && $offering['teacher_id'] !== $teacher) {
            throw new DomainException('La asignación pertenece a otro docente.');
        }
        if (!$offering) {
            query($db, 'INSERT INTO offerings (subject_id,module_id,shift_id,teacher_id) VALUES (?,?,?,?)', [$subject, $moduleId, $shift, $teacher]);
        }
        $id = $offering ? $offering['id'] : (int)$db->lastInsertId();
        $group = query($db, 'SELECT id FROM academic_groups WHERE offering_id=? FOR UPDATE', [$id])->fetchColumn();
        if (!$group) {
            $group = query($db, 'SELECT id FROM academic_groups WHERE subject_id=? AND year_id=? AND admission_year_id=?
                AND shift_id=? AND offering_id IS NULL FOR UPDATE', [$subject, $module['year_id'], $admission, $shift])->fetchColumn();
            if ($group) {
                query($db, 'UPDATE academic_groups SET offering_id=? WHERE id=?', [$id, $group]);
            } else {
                query($db, 'INSERT INTO academic_groups (subject_id,year_id,admission_year_id,shift_id,offering_id) VALUES (?,?,?,?,?)',
                    [$subject, $module['year_id'], $admission, $shift, $id]);
                $group = (int)$db->lastInsertId();
            }
        }
        foreach ($students as $student) {
            if (query($db, 'SELECT admission_year_id FROM students WHERE user_id=? FOR UPDATE', [$student])->fetchColumn() !== $admission) {
                throw new DomainException('Los estudiantes deben pertenecer al ingreso seleccionado.');
            }
            if (!query($db, 'SELECT 1 FROM enrollments WHERE offering_id=? AND student_id=?', [$id, $student])->fetchColumn()) {
                enroll($db, $actor, $id, $student, $retakes);
            }
        }
        query($db, 'INSERT INTO academic_group_students (group_id,student_id) SELECT ?,student_id FROM enrollments
            WHERE offering_id=? ON DUPLICATE KEY UPDATE group_id=academic_group_students.group_id', [$group, $id]);
        audit($db, $actor, 'Grupo académico guardado', "Grupo $group · asignación $id");
        return $id;
    };
    return $inTransaction ? $save() : transaction($db, $save);
}
