<?php
declare(strict_types=1);

function changePlan(PDO $db, int $actor, string $action, array $input): void
{
    transaction($db, function () use ($db, $actor, $action, $input) {
        if ($action === 'plan-delete') {
            $id = integer($input['plan_id'] ?? null);
            if (query($db, 'DELETE FROM study_plans WHERE id=?', [$id])->rowCount() !== 1) {
                throw new DomainException('Plan no encontrado.');
            }
            audit($db, $actor, 'Plan de estudios eliminado', "Plan $id");
            return;
        }
        $file = $_FILES['plan'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $file['size'] < 16 || $file['size'] > 20971520 ||
            !is_uploaded_file($file['tmp_name'])) {
            throw new DomainException('Selecciona un PDF de hasta 20 MB.');
        }
        $content = file_get_contents($file['tmp_name']);
        if ($content === false || !str_starts_with($content, '%PDF-') || !str_contains(substr($content, -1024), '%%EOF')) {
            throw new DomainException('El archivo debe ser un PDF válido.');
        }
        $title = academicName($input['name'] ?? '');
        query($db, 'INSERT INTO study_plans (title,career_id,level,original_name,content,size_bytes,uploaded_by)
            VALUES (?,?,?,?,?,?,?)', [$title, integer($input['career_id']), integer($input['level'], 3),
            mb_substr(basename($file['name']), 0, 200), $content, strlen($content), $actor]);
        audit($db, $actor, 'Plan de estudios publicado', $title);
    });
}

function downloadPlan(PDO $db, int $id): void
{
    $plan = query($db, 'SELECT content,size_bytes FROM study_plans WHERE id=?', [$id])->fetch();
    if (!$plan) {
        throw new DomainException('Plan no encontrado.');
    }
    header('Content-Type: application/pdf');
    header("Content-Disposition: attachment; filename=\"plan-$id.pdf\"");
    header('Content-Length: ' . $plan['size_bytes']);
    echo $plan['content'];
}
