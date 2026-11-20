<?php
declare(strict_types=1);

function subjectPartAction(PDO $db, int $actor, string $action, array $input): int
{
    $sourceId = integer($input['offering_id'] ?? null);
    return transaction($db, function () use ($db, $actor, $action, $input, $sourceId) {
        $targetId = $action === 'part-link' ? integer($input['target_offering_id'] ?? null) : 0;
        $offerings = query($db, 'SELECT o.*,m.start_date,m.end_date FROM offerings o JOIN modules m ON m.id=o.module_id
            WHERE o.id IN (?,?) ORDER BY o.id FOR UPDATE', [$sourceId, $targetId])->fetchAll();
        $offerings = array_column($offerings, null, 'id');
        $source = $offerings[$sourceId] ?? null;
        if (!$source || $sourceId === $targetId) {
            throw new DomainException('Selecciona una asignación válida.');
        }
        $series = $source['series_id'] ? query($db, 'SELECT * FROM subject_series WHERE id=? FOR UPDATE', [$source['series_id']])->fetch() : null;
        $count = $series['part_count'] ?? 1;
        if (integer($input['expected_parts'] ?? null) !== $count) {
            throw new DomainException('Las partes cambiaron. Actualiza la página.');
        }
        if ($action === 'part-status') {
            if (!$series) {
                throw new DomainException('La asignación no pertenece a una serie.');
            }
            $completed = isset($input['completed']);
            $parts = query($db, 'SELECT part_number FROM offerings WHERE series_id=? AND part_recovery=0 ORDER BY part_number',
                [$series['id']])->fetchAll(PDO::FETCH_COLUMN);
            if ($completed && $parts !== range(1, $count)) {
                throw new DomainException('Faltan partes originales.');
            }
            query($db, 'UPDATE subject_series SET completed=? WHERE id=?', [$completed, $series['id']]);
            audit($db, $actor, $completed ? 'Materia por partes concluida' : 'Materia por partes reabierta', "Serie {$series['id']}");
            return $sourceId;
        }
        $recovery = $action === 'part-recover';
        if (($recovery && !$series) || (!$recovery && $series &&
            ($series['completed'] || $source['part_recovery'] || $source['part_number'] !== $count))) {
            throw new DomainException('Continúa desde la última parte de una materia abierta.');
        }
        $roster = query($db, 'SELECT e.student_id,e.grade,e.is_retake,s.admission_year_id FROM enrollments e
            JOIN students s ON s.user_id=e.student_id WHERE e.offering_id=? ORDER BY e.student_id FOR UPDATE',
            [$sourceId])->fetchAll();
        $roster = array_column($roster, null, 'student_id');
        if ($targetId) {
            $target = $offerings[$targetId] ?? null;
            if (!$target || $target['series_id'] || $target['subject_id'] !== $source['subject_id'] ||
                $target['shift_id'] !== $source['shift_id'] || $target['start_date'] <= $source['end_date']) {
                throw new DomainException('La asignación debe ser posterior y de la misma materia y turno.');
            }
            $targetStudents = query($db, 'SELECT student_id FROM enrollments WHERE offering_id=? ORDER BY student_id FOR UPDATE',
                [$targetId])->fetchAll(PDO::FETCH_COLUMN);
            if ($roster && $targetStudents && !array_intersect(array_keys($roster), $targetStudents)) {
                throw new DomainException('Los grupos no comparten estudiantes.');
            }
        } else {
            $module = integer($input['module_id'] ?? null);
            $teacher = integer($input['teacher_id'] ?? null);
            $calendar = query($db, 'SELECT start_date,year_id FROM modules WHERE id=? FOR UPDATE', [$module])->fetch();
            if (!$calendar || $calendar['start_date'] <= $source['end_date'] || !query($db, "SELECT 1 FROM users WHERE id=? AND role='teacher'", [$teacher])->fetchColumn()) {
                throw new DomainException('Selecciona un módulo posterior y un docente registrado.');
            }
            $selected = studentIds($input['student_ids'] ?? []);
            foreach ($selected as $student) {
                if (!isset($roster[$student])) {
                    throw new DomainException('Selecciona estudiantes de la parte de origen.');
                }
            }
        }
        $part = $recovery ? $source['part_number'] : $count + 1;
        if (!$series) {
            query($db, 'INSERT INTO subject_series (subject_id,shift_id,part_count) VALUES (?,?,?)', [$source['subject_id'], $source['shift_id'], $part]);
            $seriesId = (int)$db->lastInsertId();
            query($db, 'UPDATE offerings SET series_id=?,part_number=1 WHERE id=?', [$seriesId, $sourceId]);
        } else {
            $seriesId = $series['id'];
            if (!$recovery) {
                query($db, 'UPDATE subject_series SET part_count=? WHERE id=?', [$part, $seriesId]);
            }
        }
        if ($targetId) {
            query($db, 'UPDATE offerings SET series_id=?,part_number=? WHERE id=?', [$seriesId, $part, $targetId]);
        } else {
            query($db, 'INSERT INTO offerings (subject_id,module_id,shift_id,teacher_id,series_id,part_number,part_recovery)
                VALUES (?,?,?,?,?,?,?)', [$source['subject_id'], $module, $source['shift_id'], $teacher, $seriesId, $part, $recovery]);
            $targetId = (int)$db->lastInsertId();
            $admission = query($db, 'SELECT admission_year_id FROM academic_groups WHERE offering_id=?', [$sourceId])->fetchColumn()
                ?: ($selected ? $roster[$selected[0]]['admission_year_id'] : null);
            foreach ($selected as $student) {
                enroll($db, $actor, $targetId, $student, true);
                if ($roster[$student]['is_retake']) {
                    query($db, 'UPDATE enrollments SET is_retake=1 WHERE offering_id=? AND student_id=?', [$targetId, $student]);
                }
            }
            if ($admission) {
                query($db, 'INSERT INTO academic_groups (subject_id,year_id,admission_year_id,shift_id,offering_id)
                    VALUES (?,?,?,?,?)', [$source['subject_id'], $calendar['year_id'], $admission, $source['shift_id'], $targetId]);
                $group = (int)$db->lastInsertId();
                query($db, 'INSERT INTO academic_group_students (group_id,student_id)
                    SELECT ?,student_id FROM enrollments WHERE offering_id=?', [$group, $targetId]);
            }
        }
        audit($db, $actor, $recovery ? 'Recuperación de parte' : 'Continuación de materia', "Serie $seriesId · parte $part · asignaciones $sourceId → $targetId");
        return $targetId;
    });
}

function partLabel(array $offering): string
{
    return $offering['part_number'] ? ' · Parte ' . $offering['part_number'] . ($offering['part_recovery'] ? ' · Recuperación' : '') : '';
}
