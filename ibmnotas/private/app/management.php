<?php
declare(strict_types=1);

function managementState(PDO $db, string $type, int $id, bool $lock = false): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $sql = match ($type) {
        'offering' => 'SELECT * FROM offerings WHERE id=?',
        'module' => 'SELECT * FROM modules WHERE id=?',
        'student' => "SELECT s.*,u.name,u.ci FROM students s JOIN users u ON u.id=s.user_id WHERE u.role='student' AND s.user_id=?",
        'career' => 'SELECT * FROM careers WHERE id=?',
        default => throw new DomainException('Tipo inválido.'),
    };
    $record = query($db, $sql . $suffix, [$id])->fetch();
    if (!$record) {
        throw new DomainException('Registro no disponible.');
    }
    [$selector, $membership] = match ($type) {
        'offering' => ['o.id=?', 'g.offering_id=?'],
        'module' => ['o.module_id=?', 'g.offering_id IN (SELECT id FROM offerings WHERE module_id=?)'],
        'student' => ['o.id IN (SELECT offering_id FROM enrollments WHERE student_id=?)', 'gs.student_id=?'],
        'career' => ['o.subject_id IN (SELECT id FROM subjects WHERE career_id=?)', 'g.subject_id IN (SELECT id FROM subjects WHERE career_id=?)'],
    };
    $offerings = query($db, "SELECT o.* FROM offerings o WHERE $selector ORDER BY o.id" . $suffix, [$id])->fetchAll();
    $enrollmentSelector = $type === 'student' ? 'e.student_id=?' : $selector;
    $enrollments = query($db, "SELECT e.* FROM enrollments e JOIN offerings o ON o.id=e.offering_id
        WHERE $enrollmentSelector ORDER BY e.id" . $suffix, [$id])->fetchAll();
    $join = $type === 'student' ? ' JOIN academic_group_students gs ON gs.group_id=g.id' : '';
    $groups = query($db, "SELECT g.* FROM academic_groups g$join WHERE $membership ORDER BY g.id" . $suffix, [$id])->fetchAll();
    $related = [];
    if ($type === 'career') {
        foreach (['students', 'subjects', 'study_plans'] as $table) {
            $related[$table] = query($db, "SELECT COUNT(*) FROM $table WHERE career_id=?", [$id])->fetchColumn();
        }
    }
    $state = compact('record', 'offerings', 'enrollments', 'groups', 'related');
    $state['revision'] = hash('sha256', serialize($state));
    return $state;
}

function hasNotes(array $row): bool
{
    return $row['grade'] !== null || $row['saber'] !== null || $row['hacer'] !== null || $row['ser'] !== null;
}

function removeRecord(PDO $db, int $actor, string $type, int $id, array $input): string
{
    return transaction($db, function () use ($db, $actor, $type, $id, $input) {
        $state = managementState($db, $type, $id, true);
        $confirmation = removalPhrase($type);
        if (($input['revision'] ?? '') !== $state['revision'] || ($input['confirm'] ?? '') !== $confirmation) {
            throw new DomainException('Los datos cambiaron o la confirmación no coincide. Revisa nuevamente el impacto.');
        }
        $page = './';
        if ($type === 'student') {
            query($db, 'DELETE FROM academic_group_students WHERE student_id=?', [$id]);
            query($db, 'DELETE FROM enrollments WHERE student_id=?', [$id]);
            query($db, 'UPDATE audit SET actor_id=NULL WHERE actor_id=?', [$id]);
            query($db, 'DELETE FROM students WHERE user_id=?', [$id]);
            query($db, 'DELETE FROM users WHERE id=?', [$id]);
            $page = '?page=people';
        } else {
            if ($type === 'career' && ($state['related']['students'] || $state['enrollments'] ||
                query($db, 'SELECT 1 FROM academic_group_students gs JOIN academic_groups g ON g.id=gs.group_id
                    JOIN subjects s ON s.id=g.subject_id WHERE s.career_id=? LIMIT 1 FOR UPDATE', [$id])->fetchColumn())) {
                throw new DomainException('La carrera tiene estudiantes registrados o inscritos.');
            }
            if ($type !== 'career' && array_filter($state['offerings'], static fn ($offering) => $offering['series_id'] !== null)) {
                throw new DomainException('La asignación pertenece a una materia por partes.');
            }
            foreach ($state['groups'] as $group) {
                query($db, 'DELETE FROM academic_group_students WHERE group_id=?', [$group['id']]);
                query($db, 'DELETE FROM academic_groups WHERE id=?', [$group['id']]);
            }
            foreach ($state['offerings'] as $offering) {
                query($db, 'DELETE FROM enrollments WHERE offering_id=?', [$offering['id']]);
                query($db, 'DELETE FROM offerings WHERE id=?', [$offering['id']]);
            }
            if ($type === 'module') {
                query($db, 'DELETE FROM modules WHERE id=?', [$id]);
                $page = '?page=catalog';
            } elseif ($type === 'career') {
                query($db, 'DELETE ps FROM subject_series ps JOIN subjects s ON s.id=ps.subject_id WHERE s.career_id=?', [$id]);
                query($db, 'DELETE FROM subjects WHERE career_id=?', [$id]);
                query($db, 'DELETE FROM study_plans WHERE career_id=?', [$id]);
                query($db, 'DELETE FROM careers WHERE id=?', [$id]);
                $page = '?page=catalog';
            }
        }
        audit($db, $actor, $confirmation, "Registro $id · " . count($state['enrollments']) . ' inscripciones · '
            . count(array_filter($state['enrollments'], 'hasNotes')) . ' con notas.');
        return $page;
    });
}

function removalPhrase(string $type): string
{
    return match ($type) {
        'offering' => 'ELIMINAR ASIGNACION',
        'module' => 'ELIMINAR TRIMESTRE',
        'student' => 'ELIMINAR ESTUDIANTE',
        'career' => 'ELIMINAR CARRERA',
        default => throw new DomainException('Tipo inválido.'),
    };
}

function removeEnrollment(PDO $db, int $actor, int $offering, int $id, array $input): void
{
    transaction($db, function () use ($db, $actor, $offering, $id, $input) {
        query($db, 'SELECT id FROM offerings WHERE id=? FOR UPDATE', [$offering])->fetchColumn();
        $row = query($db, 'SELECT * FROM enrollments WHERE id=? AND offering_id=? FOR UPDATE', [$id, $offering])->fetch();
        if (!$row || ($input['revision'] ?? '') !== hash('sha256', serialize($row))) {
            throw new DomainException('La inscripción o sus notas cambiaron. Revisa nuevamente.');
        }
        $phrase = hasNotes($row) ? 'RETIRAR CON NOTAS' : 'RETIRAR';
        if (($input['confirm'] ?? '') !== $phrase) {
            throw new DomainException("Escribe $phrase para confirmar.");
        }
        query($db, 'DELETE gs FROM academic_group_students gs JOIN academic_groups g ON g.id=gs.group_id
            WHERE g.offering_id=? AND gs.student_id=?', [$offering, $row['student_id']]);
        query($db, 'DELETE FROM enrollments WHERE id=?', [$id]);
        audit($db, $actor, 'Inscripción retirada', "Asignación $offering · inscripción $id · $phrase");
    });
}
