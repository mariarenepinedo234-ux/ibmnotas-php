<?php
declare(strict_types=1);

function academicHistory(PDO $db, int $student): array
{
    return transaction($db, function () use ($db, $student) {
        $profile = query($db, 'SELECT s.*,u.blocked FROM students s JOIN users u ON u.id=s.user_id WHERE s.user_id=?', [$student])->fetch();
        if (!$profile || $profile['blocked']) {
            throw new DomainException('Expediente no disponible.');
        }
        $attempts = query($db, 'SELECT e.id,e.grade,o.subject_id,o.series_id,o.part_number,ps.part_count,ps.completed,
            m.end_date,s.name,s.code,s.level FROM enrollments e JOIN offerings o ON o.id=e.offering_id
            JOIN subjects s ON s.id=o.subject_id JOIN modules m ON m.id=o.module_id
            LEFT JOIN subject_series ps ON ps.id=o.series_id WHERE e.student_id=? ORDER BY m.end_date DESC,e.id DESC',
            [$student])->fetchAll();
        $subjects = query($db, 'SELECT id,name,code,level,catalog_order FROM subjects WHERE career_id=? AND active=1
            ORDER BY level,catalog_order IS NULL,catalog_order,name', [$profile['career_id']])->fetchAll();
        $official = (bool)array_filter($subjects, static fn ($row) => $row['catalog_order'] !== null);
        if ($official) {
            $subjects = array_filter($subjects, static fn ($row) => $row['catalog_order'] !== null);
        }
        $bySubject = [];
        foreach ($attempts as $attempt) {
            $bySubject[$attempt['subject_id']][] = $attempt;
        }
        $plan = array_column($subjects, null, 'id');
        foreach ($bySubject as $subjectId => $records) {
            $plan[$subjectId] ??= ['id' => $subjectId, ...$records[0], 'outside' => true];
        }
        $today = (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
        $summary = [];
        foreach ($plan as $subjectId => $subject) {
            $evaluations = $series = [];
            foreach ($bySubject[$subjectId] ?? [] as $record) {
                if ($record['series_id']) {
                    $series[$record['series_id']][] = $record;
                    continue;
                }
                $grade = $record['grade'];
                $state = $grade !== null ? ($grade >= 61 ? 'Aprobada' : 'Reprobada') :
                    ($record['end_date'] >= $today ? 'Inscrita / en curso' : 'Nota pendiente');
                $evaluations[] = ['state' => $state, ...$record];
            }
            foreach ($series as $attempts) {
                $evaluations[] = summarizeParts($attempts, $today);
            }
            usort($evaluations, static fn ($left, $right) =>
                [$right['end_date'], $right['id']] <=> [$left['end_date'], $left['id']]);
            $passed = array_filter($evaluations, static fn ($row) => $row['state'] === 'Aprobada');
            $result = $passed ? reset($passed) : ($evaluations[0] ?? []);
            $summary[] = [$subject['level'] . 'º', $subject['code'], $subject['name'], $result['state'] ?? 'Sin inscripción',
                $result['grade'] ?? '', isset($subject['outside']) ? 'Fuera del plan' : 'Plan vigente'];
        }
        return $summary;
    });
}

function summarizeParts(array $rows, string $today): array
{
    $latest = $rows[0];
    $parts = [];
    foreach ($rows as $row) {
        $part = $row['part_number'];
        if (!isset($parts[$part]) || (($parts[$part]['grade'] === null || $parts[$part]['grade'] < 61) && $row['grade'] >= 61)) {
            $parts[$part] = $row;
        }
    }
    $grades = array_filter(array_column($parts, 'grade'), static fn ($grade) => $grade !== null);
    $allGraded = count($grades) === $latest['part_count'];
    $failed = (bool)array_filter($grades, static fn ($grade) => $grade < 61);
    $inProgress = !$latest['completed'] || $latest['end_date'] >= $today;
    $grade = $latest['completed'] && $allGraded ? round(array_sum($grades) / count($grades), 2) : null;
    $state = match (true) {
        $failed => 'Reprobada',
        $grade !== null => 'Aprobada',
        $inProgress => 'Inscrita / en curso',
        default => 'Nota pendiente',
    };
    return ['grade' => $grade, 'state' => $state, 'id' => $latest['id'], 'end_date' => $latest['end_date']];
}
