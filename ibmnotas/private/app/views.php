<?php
declare(strict_types=1);

require_once __DIR__ . '/portal-layout.php';

function e(mixed $text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function form(string $action, bool $upload = false): void
{
    echo '<form method="post"' . ($upload ? ' enctype="multipart/form-data"' : '') . '><input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf'] ?? '') . '">';
}

function field(string $name, string $label, string $type = 'text', bool $required = true): void
{
    echo '<label class="form-label d-block">' . e($label) . '<input class="form-control" name="' . e($name)
        . '" type="' . e($type) . '"' . ($required ? ' required' : '') . '></label>';
}

function selectField(string $name, string $label, array $options, string $empty = '', mixed $selected = null): void
{
    echo '<label class="form-label d-block">' . e($label) . '<select class="form-select" name="' . e($name) . '">';
    if ($empty !== '') {
        echo '<option value="">' . e($empty) . '</option>';
    }
    $selected = is_scalar($selected) ? (string)$selected : '';
    foreach ($options as $id => $value) {
        echo '<option value="' . e($id) . '"' . ($selected === (string)$id ? ' selected' : '') . '>' . e($value) . '</option>';
    }
    echo '</select></label>';
}

function button(string $text = 'Guardar'): void
{
    echo '<button class="btn btn-ibm" type="submit">' . e($text) . '</button>';
}

function panel(string $title, callable $body): void
{
    echo '<section class="card shadow-sm p-3 p-md-4 mb-4"><h2 class="h5 mb-3">' . e($title) . '</h2>';
    $body();
    echo '</section>';
}

function displayTable(array $headings, array $rows): void
{
    if (!$rows) {
        echo '<p class="text-body-secondary">Sin registros.</p>';
        return;
    }
    echo '<div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th scope="col">'
        . implode('</th><th scope="col">', array_map('e', $headings)) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . implode('</td><td>', array_map('e', $row)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function options(PDO $db, string $sql): array
{
    return $db->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
}

function render(PDO $db, array $config, ?array $user, string $error): void
{
    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IBM · Gestión académica</title>
<link rel="icon" href="assets/logo_ibm.png">
<link rel="stylesheet" href="assets/bootstrap.min.css">
<script src="assets/portal/theme-init.js"></script>
<link rel="stylesheet" href="assets/portal/style.css">
<link rel="stylesheet" href="assets/portal/interface.css">
<link rel="stylesheet" href="assets/portal/theme.css">
<link rel="stylesheet" href="assets/ibm.css">
<script type="module" src="assets/portal/portal.js"></script>
</head>
<body>
<?php
    if (!$user || $user['must_change'] || $page === 'password') {
        portalAuth($user, $error);
        echo '</body></html>';
        return;
    }
    portalShell($user, isset($_GET['offering']) ? 'assignments' : $page);
    if ($error !== '') {
        echo '<div class="alert alert-danger" role="alert">' . e($error) . '</div>';
    }
    try {
        if ($page === 'calendar' && $user['role'] !== 'admin') {
            portalCalendar($db, $user);
        } elseif ($user['role'] === 'student') {
            studentView($db, $user, $page);
        } elseif (isset($_GET['offering'])) {
            offeringView($db, $user, integer($_GET['offering']));
        } elseif ($user['role'] === 'admin' && $page === 'review') {
            removalView($db, $_GET['type'] ?? '', integer($_GET['id'] ?? null));
        } elseif ($user['role'] === 'admin' && $page === 'groups') {
            groupsView($db);
        } elseif ($page === 'plans') {
            plansView($db, $user['role'] === 'admin');
        } elseif ($user['role'] === 'admin' && $page === 'import') {
            importView();
        } elseif ($user['role'] === 'admin' && $page === 'catalog') {
            catalogView($db);
        } elseif ($user['role'] === 'admin' && in_array($page, ['people', 'students', 'teachers'], true)) {
            peopleView($db, $page);
        } elseif ($user['role'] === 'admin' && $page === 'backups') {
            backupView($db, $config);
        } elseif ($page === '') {
            portalDashboard($db, $user);
        } else {
            assignmentsView($db, $user);
        }
    } catch (DomainException $failure) {
        echo '<div class="alert alert-danger">' . e($failure->getMessage()) . '</div>';
    }
    echo '</main><footer class="app-footer"><span>Instituto Técnico Británico Mercantil</span></footer></div></div></body></html>';
}

function studentView(PDO $db, array $user, string $page): void
{
    if ($user['blocked']) {
        echo '<div class="alert alert-warning">La consulta de notas está suspendida. Contacta con administración.</div>';
        return;
    }
    if ($page !== 'results') {
        panel('Avance del plan de estudios', function () use ($db, $user) {
            displayTable(['Año','Código','Materia','Estado','Nota','Plan'], academicHistory($db, $user['id']));
        });
        if ($page === 'history') {
            return;
        }
    }
    $sql = 'SELECT ' . careerName('c', 'y.name') . ' AS career, s.name AS subject, y.name AS academic_year,
        m.name AS module, o.part_number, o.part_recovery, e.is_retake, e.saber, e.hacer, e.ser, e.grade
        FROM enrollments e JOIN offerings o ON o.id=e.offering_id JOIN subjects s ON s.id=o.subject_id
        JOIN careers c ON c.id=s.career_id JOIN modules m ON m.id=o.module_id JOIN years y ON y.id=m.year_id
        WHERE e.student_id=?';
    $params = [$user['id']];
    $years = options($db, 'SELECT name,name FROM years ORDER BY name DESC');
    $year = $_GET['year'] ?? '';
    if (is_string($year) && isset($years[$year])) {
        $sql .= ' AND y.name=?';
        $params[] = $year;
    }
    $module = $_GET['module'] ?? '';
    $modules = options($db, "SELECT m.id,CONCAT(y.name,' · ',m.name) FROM modules m JOIN years y ON y.id=m.year_id
        ORDER BY y.name DESC,m.start_date");
    if (is_string($module) && isset($modules[$module])) {
        $sql .= ' AND m.id=?';
        $params[] = (int)$module;
    }
    $rows = query($db, $sql . ' ORDER BY y.name DESC,m.start_date,s.name', $params)->fetchAll();
    echo '<form method="get" class="ibm-filter"><input type="hidden" name="page" value="results">';
    selectField('year', 'Gestión', $years, 'Todas', $year);
    selectField('module', 'Módulo', $modules, 'Todos', $module);
    button('Filtrar');
    echo '</form><h2 class="h5 mt-4">Historial de notas</h2>';
    displayTable(['Carrera', 'Materia', 'Gestión', 'Módulo', 'Parte', 'Pendiente', 'Saber', 'Hacer', 'Ser', 'Final', 'Resultado'],
        array_map(static fn ($row) => [$row['career'], $row['subject'], $row['academic_year'], $row['module'],
            $row['part_number'] ? $row['part_number'] . ($row['part_recovery'] ? ' · recuperación' : '') : '',
            $row['is_retake'] ? 'Sí' : '', $row['saber'], $row['hacer'], $row['ser'], $row['grade'],
            observation($row['grade'])], $rows));
}

function assignmentsView(PDO $db, array $user): void
{
    $sql = offeringsSql();
    $rows = query($db, $sql . ($user['role'] === 'teacher' ? ' WHERE o.teacher_id = ?' : '')
        . ' ORDER BY y.name DESC, m.start_date DESC,s.name', $user['role'] === 'teacher' ? [$user['id']] : [])->fetchAll();
    $filters = [
        'year' => ['Gestión', 'academic_year', 'academic_year'],
        'module' => ['Módulo', 'module_id', 'module'],
        'career' => ['Carrera', 'career_id', 'career'],
    ];
    echo '<h2 class="h5">Asignaciones</h2><form method="get" class="row g-2 align-items-end mb-3"><input type="hidden" name="page" value="assignments">';
    foreach ($filters as $name => [$label, $key, $text]) {
        $choices = array_column($rows, $text, $key);
        $selection = $_GET[$name] ?? '';
        echo '<div class="col-sm">';
        selectField($name, $label, $choices, 'Todas las opciones', $selection);
        echo '</div>';
    }
    echo '<div class="col-sm-auto mb-2">';
    button('Filtrar');
    echo '</div></form>';
    foreach ($filters as $name => [, $key]) {
        $selection = $_GET[$name] ?? '';
        if (is_string($selection) && $selection !== '') {
            $rows = array_values(array_filter($rows, static fn ($row) => (string)$row[$key] === $selection));
        }
    }
    if (!$rows) {
        echo '<p class="text-body-secondary">Sin asignaciones para este período.</p>';
    } else {
        foreach ($rows as &$row) {
            $row['can_grade'] = canGrade($row);
        }
        unset($row);
        if ($user['role'] === 'teacher') {
            usort($rows, static fn ($a, $b) => [$b['can_grade'], $b['end_date'], $b['start_date']] <=>
                [$a['can_grade'], $a['end_date'], $a['start_date']]);
        }
        $groups = [];
        foreach ($rows as $row) {
            $groups[nameKey($row['subject']) . ':' . $row['level']][] = $row;
        }
        $assignments = array_column($rows, 'id');
        $marks = databaseParams(count($assignments));
        $stats = query($db, "SELECT COUNT(DISTINCT student_id) AS students, COUNT(grade) AS registered
            FROM enrollments WHERE offering_id IN ($marks)", $assignments)->fetch();
        echo '<p class="text-body-secondary">' . count($groups) . ' materias · ' . count($rows) . ' grupos · '
            . e($stats['students']) . ' estudiantes · ' . e($stats['registered']) . ' notas registradas</p>';
        foreach ($groups as $group) {
            $first = $group[0];
            echo '<details class="card p-3 mb-2"><summary><strong>' . e($first['subject'] . ' · ' . $first['level'] . 'º año')
                . '</strong> <span class="text-body-secondary">(' . count($group) . ' grupos)</span></summary><div class="list-group list-group-flush mt-2">';
            foreach ($group as $row) {
                echo '<a class="list-group-item list-group-item-action" href="?offering=' . e($row['id']) . '">'
                    . e(implode(' · ', [$row['career'], $row['academic_year'], $row['module'], $row['shift'], $row['teacher']]) . partLabel($row))
                    . ($user['role'] === 'teacher' && !$row['can_grade'] ? ' · Plazo cerrado' : '') . '</a>';
            }
            echo '</div></details>';
        }
        echo '<div class="mb-4"></div>';
    }
    if ($user['role'] === 'admin') {
        panel('Nueva asignación docente', function () use ($db) {
            form('offering');
            selectField('subject_id', 'Materia', options($db, "SELECT s.id,CONCAT(c.name,' · ',s.level,'º · ',s.name)
                FROM subjects s JOIN careers c ON c.id=s.career_id WHERE s.active=1 ORDER BY c.name,s.level,s.name"));
            selectField('module_id', 'Módulo', options($db, "SELECT m.id,CONCAT(y.name,' · ',m.name,' · ',COALESCE(a.name,'compartido'))
                FROM modules m JOIN years y ON y.id=m.year_id LEFT JOIN years a ON a.id=m.admission_year_id ORDER BY y.name DESC,m.start_date"));
            selectField('teacher_id', 'Docente', options($db, "SELECT id,name FROM users WHERE role='teacher' ORDER BY name"));
            selectField('shift_id', 'Turno', options($db, 'SELECT id,name FROM shifts ORDER BY name'));
            button();
            echo '</form>';
        });
    }
}

function offeringView(PDO $db, array $user, int $id): void
{
    [$offering, $rows] = gradebook($db, $user, $id);
    echo '<h2>' . e($offering['subject'] . partLabel($offering)) . '</h2><p>' . e(implode(' · ', [$offering['career'], $offering['academic_year'],
        $offering['module'], $offering['shift'], $offering['teacher']])) . '</p><div class="ibm-actions mb-3">';
    foreach (['pdf' => 'PDF', 'xlsx' => 'Excel'] as $format => $label) {
        foreach ([false, true] as $draft) {
            echo '<a class="btn btn-outline-secondary" href="?offering=' . $id . '&amp;export=' . $format . ($draft ? '&amp;draft=1' : '')
                . '">' . ($draft ? 'Borrador ' : 'Planilla ') . $label . '</a>';
        }
    }
    echo '</div>';
    $editable = $user['role'] === 'admin' || canGrade($offering);
    if (!$editable) {
        echo '<div class="alert alert-warning">El plazo de registro está cerrado.</div>';
    }
    if ($rows) {
        if ($editable) {
            form('grades');
            echo '<input type="hidden" name="offering_id" value="' . $id . '">';
        }
        echo '<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Estudiante</th><th>CI</th>'
            . '<th>Saber /40</th><th>Hacer /40</th><th>Ser /20</th><th>Final</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . e($row['name']) . '</th><td>' . e($row['ci']) . '</td>';
            foreach (['saber'=>40,'hacer'=>40,'ser'=>20] as $field => $max) {
                $value = $row[$field];
                echo '<td>';
                if ($editable) {
                    echo '<input class="form-control ibm-score" type="number" step="0.01" min="0" max="' . $max
                        . '" aria-label="' . e($field . ' · ' . $row['name']) . '" name="grades[' . e($row['id']) . '][' . $field
                        . ']" value="' . e($value) . '">';
                } else {
                    echo e($value);
                }
                echo '</td>';
            }
            $class = $row['grade'] !== null && $row['grade'] < 61 ? 'text-danger' : '';
            echo '<td class="' . $class . '">' . e($row['grade'] ?? 'Pendiente');
            if ($editable) {
                echo '<input type="hidden" name="grades[' . e($row['id']) . '][version]" value="' . e($row['updated_at']) . '">';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($editable) {
            echo '<input type="hidden" name="complete" value="1">';
            button('Guardar notas');
            echo '</form>';
        }
    } else {
        echo '<p>Sin estudiantes inscritos.</p>';
    }
    if ($user['role'] === 'admin') {
        echo '<div class="row mt-4"><div class="col-md-6">';
        panel('Inscribir estudiante', function () use ($db, $id, $offering) {
            form('enroll');
            echo '<input type="hidden" name="offering_id" value="' . $id . '">';
            $students = query($db, "SELECT u.id,CONCAT(u.name,' · ',COALESCE(u.ci,'CI pendiente'),' · ',s.level,'º')
                FROM students s JOIN users u ON u.id=s.user_id
                WHERE s.career_id=? AND s.shift_id=? AND s.level>=?
                AND NOT EXISTS (SELECT 1 FROM enrollments e WHERE e.student_id=u.id AND e.offering_id=?) ORDER BY u.name",
                [$offering['career_id'], $offering['shift_id'], $offering['level'], $id])->fetchAll(PDO::FETCH_KEY_PAIR);
            echo '<fieldset class="mb-3"><legend class="h6">Estudiantes disponibles</legend>';
            foreach ($students as $studentId => $student) {
                echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="student_ids[]" value="'
                    . e($studentId) . '"> ' . e($student) . '</label>';
            }
            echo '</fieldset>';
            echo '<label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="retake"> Autorizar materia pendiente de un año anterior</label>';
            button('Inscribir');
            echo '</form>';
        });
        echo '</div><div class="col-md-6">';
        panel('Reabrir plazo docente', function () use ($id) {
            form('unlock');
            echo '<input type="hidden" name="offering_id" value="' . $id . '">';
            field('hours', 'Horas (1 a 168)', 'number');
            field('name', 'Motivo');
            button('Reabrir');
            echo '</form>';
        });
        echo '</div></div>';
        partsView($db, $offering, $rows);
        rosterRemovalView($db, $id);
        echo removalLink('offering', $id, 'Revisar eliminación de asignación');
    }
}

function catalogView(PDO $db): void
{
    $careers = options($db, 'SELECT c.id,' . careerName('c', "'" . (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('Y') . "'") . ' FROM careers c ORDER BY c.name');
    $years = options($db, 'SELECT id,name FROM years ORDER BY name DESC');
    echo '<h2 class="h4 mb-3">Catálogo académico</h2><a class="btn btn-outline-secondary mb-3" href="?page=import">Importar catálogo Excel</a><div class="row">';
    foreach (['career' => 'Carrera', 'shift' => 'Turno', 'year' => 'Gestión'] as $key => $label) {
        echo '<div class="col-md-4">';
        panel('Registrar ' . mb_strtolower($label), function () use ($key, $label) {
            form($key);
            field('name', $label);
            button();
            echo '</form>';
        });
        echo '</div>';
    }
    echo '<div class="col-md-6">';
    panel('Nombre de carrera por gestión', function () use ($careers, $years, $db) {
        form('rename');
        selectField('career_id', 'Carrera', $careers);
        selectField('year', 'Vigente desde la gestión', array_combine($years, $years));
        field('name', 'Nuevo nombre');
        button();
        echo '</form><div class="mt-3">';
        displayTable(['Nombre original', 'Vigente desde', 'Nombre histórico'],
            $db->query('SELECT c.name,h.effective_year,h.name FROM careers c JOIN career_names_by_year h ON h.career_id=c.id
                ORDER BY c.name,h.effective_year DESC')->fetchAll(PDO::FETCH_NUM));
        echo '</div><ul>';
        foreach ($careers as $careerId => $career) {
            echo '<li>' . removalLink('career', $careerId, 'Revisar baja de ' . $career) . '</li>';
        }
        echo '</ul>';
    });
    panel('Registrar materia', function () use ($careers) {
        form('subject');
        selectField('career_id', 'Carrera', $careers);
        selectField('level', 'Año académico', [1=>'1º',2=>'2º',3=>'3º']);
        field('name', 'Materia');
        field('code', 'Código', required: false);
        button();
        echo '</form>';
    });
    echo '</div><div class="col-md-6">';
    panel('Registrar módulo', function () use ($years) {
        form('module');
        selectField('year_id', 'Gestión cursada', $years);
        selectField('admission_year_id', 'Gestión de ingreso', $years, 'Calendario compartido');
        field('name', 'Módulo');
        field('start_date', 'Fecha de inicio', 'date');
        field('end_date', 'Fecha final', 'date');
        button();
        echo '</form>';
    });
    panel('Calendarios registrados', function () use ($db, $years) {
        $modules = $db->query('SELECT * FROM modules ORDER BY year_id DESC,start_date')->fetchAll();
        foreach ($modules as $module) {
            echo '<details class="border-bottom pb-2 mb-2"><summary>'
                . e($years[$module['year_id']] . ' · ' . $module['name'] . ' · ingreso ' . ($years[$module['admission_year_id']] ?? 'compartido'))
                . '</summary><div class="mt-2">';
            form('module');
            echo '<input type="hidden" name="module_id" value="' . e($module['id']) . '">';
            selectField('year_id', 'Gestión cursada', $years, selected: $module['year_id']);
            selectField('admission_year_id', 'Gestión de ingreso', $years, 'Calendario compartido', $module['admission_year_id']);
            foreach (['name'=>'Nombre','start_date'=>'Fecha inicial','end_date'=>'Fecha final'] as $key => $label) {
                echo '<label class="form-label d-block">' . e($label) . '<input class="form-control" required type="'
                    . ($key === 'name' ? 'text' : 'date') . '" name="' . $key . '" value="' . e($module[$key]) . '"></label>';
            }
            button('Guardar módulo');
            echo '</form>' . removalLink('module', $module['id'], 'Revisar eliminación de módulo') . '</div></details>';
        }
    });
    echo '</div></div>';
    panel('Materias registradas', function () use ($db, $careers) {
        $rows = $db->query('SELECT id,career_id,level,code,name,active FROM subjects
            ORDER BY career_id,level,catalog_order IS NULL,catalog_order,name')->fetchAll();
        foreach ($rows as $row) {
            echo '<details class="border-bottom pb-2 mb-2"><summary>' .
                e(implode(' · ', [$careers[$row['career_id']], $row['level'] . 'º', $row['code'], $row['name'], $row['active'] ? 'Activa' : 'Inactiva'])) .
                '</summary><div class="mt-2">';
            form('subject');
            echo '<input type="hidden" name="subject_id" value="' . e($row['id']) . '">';
            selectField('career_id', 'Carrera', $careers, selected: $row['career_id']);
            selectField('level', 'Año académico', [1=>'1º',2=>'2º',3=>'3º'], selected: $row['level']);
            echo '<label class="form-label d-block">Materia<input class="form-control" required name="name" value="' . e($row['name']) . '"></label>'
                . '<label class="form-label d-block">Código<input class="form-control" name="code" value="' . e($row['code']) . '"></label>'
                . '<label class="form-check mb-2"><input type="checkbox" name="active" class="form-check-input"'
                . ($row['active'] ? ' checked' : '') . '> Activa</label>';
            button('Guardar materia');
            echo '</form></div></details>';
        }
    });
}

function peopleView(PDO $db, string $page): void
{
    $careers = options($db, 'SELECT id,name FROM careers ORDER BY name');
    $shifts = options($db, 'SELECT id,name FROM shifts ORDER BY name');
    $years = options($db, 'SELECT id,name FROM years ORDER BY name DESC');
    if ($page !== 'students') {
        panel('Registrar docente', function () {
            form('teacher');
            field('name', 'Nombre completo');
            field('ci', 'CI');
            button();
            echo '</form>';
        });
    }
    if ($page !== 'teachers') {
        panel('Registrar estudiante', function () use ($careers, $shifts, $years) {
            form('student');
            field('name', 'Nombre completo');
            field('ci', 'CI (opcional si está pendiente)', required: false);
            echo '<label class="form-check mb-2"><input type="checkbox" name="name_pending" class="form-check-input"> Nombre pendiente</label>';
            selectField('career_id', 'Carrera', $careers);
            selectField('shift_id', 'Turno', $shifts);
            selectField('year_id', 'Gestión de ingreso', $years);
            selectField('level', 'Año académico', [1=>'1º',2=>'2º',3=>'3º']);
            button();
            echo '</form>';
        });
        panel('Estudiantes', function () use ($db, $shifts, $careers, $years) {
            $rows = $db->query('SELECT s.*,u.name,u.ci,u.blocked,y.name AS admission,' . careerName('c', 'y.name') . ' AS career
                FROM students s JOIN users u ON u.id=s.user_id JOIN years y ON y.id=s.admission_year_id
                JOIN careers c ON c.id=s.career_id ORDER BY u.name')->fetchAll();
            foreach ($rows as $row) {
                $id = (int)$row['user_id'];
                echo '<details class="border-bottom mb-2 pb-2"><summary>' . e($row['name'] . ' · ' . ($row['ci'] ?? 'CI pendiente')
                    . ' · ' . $row['career'] . ' · ingreso ' . $row['admission']) . '</summary><div class="row g-3 mt-1">';
                echo '<div class="col-lg-4">';
                form('transfer');
                echo '<input type="hidden" name="student_id" value="' . $id . '">';
                selectField('shift_id', 'Turno', $shifts, selected: $row['shift_id']);
                selectField('level', 'Año académico', [1=>'1º',2=>'2º',3=>'3º'], selected: $row['level']);
                field('name', 'Motivo');
                button('Registrar traslado');
                echo '</form></div><div class="col-lg-4">';
                form('student-status');
                echo '<input type="hidden" name="student_id" value="' . $id . '">';
                echo '<label class="d-block mb-3"><input type="checkbox" name="blocked"' . ($row['blocked'] ? ' checked' : '') . '> Suspender consulta</label>';
                button();
                echo '</form></div>';
                if ($row['ci']) {
                    echo '<div class="col-lg-4">';
                    resetPasswordForm($id);
                    echo '</div>';
                }
                echo '<div class="col-lg-4">';
                form('student-edit');
                echo '<input type="hidden" name="student_id" value="' . $id . '">';
                echo '<label class="form-label d-block">Nombre<input class="form-control" name="name" required value="' . e($row['name']) . '"></label>'
                    . '<label class="form-label d-block">CI<input class="form-control" name="ci" value="' . e($row['ci']) . '"></label>'
                    . '<label class="form-check mb-2"><input type="checkbox" name="name_pending" class="form-check-input"'
                    . ($row['name_pending'] ? ' checked' : '') . '> Nombre pendiente</label>';
                selectField('career_id', 'Carrera', $careers, selected: $row['career_id']);
                selectField('shift_id', 'Turno', $shifts, selected: $row['shift_id']);
                selectField('year_id', 'Ingreso', $years, selected: $row['admission_year_id']);
                selectField('level', 'Año académico', [1=>'1º',2=>'2º',3=>'3º'], selected: $row['level']);
                button('Guardar ficha');
                echo '</form>' . removalLink('student', $id, 'Revisar eliminación de estudiante') . '</div>';
                echo '</div></details>';
            }
        });
    }
    if ($page === 'students') {
        return;
    }
    panel('Docentes', function () use ($db) {
        $teachers = $db->query("SELECT id,name,ci FROM users WHERE role='teacher' ORDER BY name")->fetchAll();
        foreach ($teachers as $teacher) {
            echo '<details class="border-bottom mb-2 pb-2"><summary>' . e($teacher['name'] . ' · ' . ($teacher['ci'] ?? 'CI pendiente')) . '</summary>';
            if ($teacher['ci']) {
                resetPasswordForm($teacher['id']);
            }
            echo '</details>';
        }
    });
    panel('Directorio docente pendiente', function () use ($db) {
        $rows = $db->query('SELECT d.id,d.name FROM teacher_directory d LEFT JOIN users u ON u.id=d.user_id
            WHERE u.ci IS NULL ORDER BY d.name')->fetchAll();
        foreach ($rows as $row) {
            echo '<details class="border-bottom mb-2 pb-2"><summary>' . e($row['name']) . '</summary>';
            form('activate-teacher');
            echo '<input type="hidden" name="directory_id" value="' . e($row['id']) . '">';
            field('ci', 'CI confirmado');
            button('Habilitar docente');
            echo '</form></details>';
        }
        if (!$rows) {
            echo '<p>Sin registros pendientes.</p>';
        }
    });
}

function backupView(PDO $db, array $config): void
{
    panel('Respaldos de la base', function () use ($config) {
        form('backup');
        button('Crear respaldo');
        echo '</form><ul class="mt-3 mb-0">';
        $files = glob($config['backup_directory'] . '/ibm-notas-*.jsonl') ?: [];
        rsort($files);
        foreach ($files as $path) {
            $name = basename($path);
            echo '<li><a href="?backup=' . rawurlencode($name) . '">' . e($name) . '</a></li>';
        }
        echo '</ul>';
    });
    panel('Actividad reciente', function () use ($db) {
        displayTable(['Fecha UTC', 'Acción', 'Detalle'],
            $db->query('SELECT created_at,action,detail FROM audit ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_NUM));
    });
}

function plansView(PDO $db, bool $admin): void
{
    if ($admin) {
        panel('Publicar plan de estudios', function () use ($db) {
            form('plan-upload', true);
            field('name', 'Título');
            selectField('career_id', 'Carrera', options($db, 'SELECT id,name FROM careers ORDER BY name'));
            selectField('level', 'Año académico', [1=>'1º',2=>'2º',3=>'3º']);
            echo '<label class="form-label d-block">Archivo PDF<input type="file" name="plan" accept="application/pdf" class="form-control" required></label>';
            button('Publicar');
            echo '</form>';
        });
    }
    panel('Planes disponibles', function () use ($db, $admin) {
        $rows = $db->query('SELECT p.id,p.title,p.level,c.name FROM study_plans p JOIN careers c ON c.id=p.career_id
            ORDER BY c.name,p.level,p.created_at DESC')->fetchAll();
        foreach ($rows as $plan) {
            echo '<div class="d-flex align-items-center gap-3 border-bottom pb-2 mb-2"><a href="?plan=' . e($plan['id']) . '">'
                . e($plan['name'] . ' · ' . $plan['level'] . 'º · ' . $plan['title']) . '</a>';
            if ($admin) {
                form('plan-delete');
                echo '<input type="hidden" name="plan_id" value="' . e($plan['id']) . '">'
                    . '<button class="btn btn-sm btn-outline-danger">Eliminar</button></form>';
            }
            echo '</div>';
        }
        if (!$rows) {
            echo '<p>Sin planes publicados.</p>';
        }
    });
}

function groupsView(PDO $db): void
{
    panel('Registrar grupo con estudiantes existentes', function () use ($db) {
        form('group');
        selectField('subject_id', 'Materia', options($db, "SELECT s.id,CONCAT(c.name,' · ',s.level,'º · ',s.name)
            FROM subjects s JOIN careers c ON c.id=s.career_id WHERE s.active=1 ORDER BY c.name,s.level,s.name"));
        selectField('admission_year_id', 'Gestión de ingreso', options($db, 'SELECT id,name FROM years ORDER BY name DESC'));
        selectField('module_id', 'Módulo', options($db, "SELECT m.id,CONCAT(y.name,' · ',m.name,' · ',COALESCE(a.name,'compartido'))
            FROM modules m JOIN years y ON y.id=m.year_id LEFT JOIN years a ON a.id=m.admission_year_id ORDER BY y.name DESC,m.start_date"));
        selectField('shift_id', 'Turno', options($db, 'SELECT id,name FROM shifts ORDER BY name'));
        selectField('teacher_id', 'Docente', options($db, "SELECT id,name FROM users WHERE role='teacher' ORDER BY name"));
        $students = options($db, "SELECT s.user_id,CONCAT(u.name,' · ',c.name,' · ',sh.name,' · ',s.level,'º · ',y.name)
            FROM students s JOIN users u ON u.id=s.user_id JOIN years y ON y.id=s.admission_year_id
            JOIN careers c ON c.id=s.career_id JOIN shifts sh ON sh.id=s.shift_id ORDER BY u.name");
        studentCheckList($students);
        echo '<label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="retake"> Autorizar materias pendientes</label>';
        button('Guardar grupo');
        echo '</form>';
    });
    panel('Grupos registrados', function () use ($db) {
        $career = careerName('c', 'y.name');
        $groups = $db->query("SELECT g.offering_id, s.name AS subject, $career AS career, y.name AS academic_year, a.name AS admission,
            sh.name AS shift, (SELECT COUNT(*) FROM academic_group_students gs WHERE gs.group_id=g.id) AS students
            FROM academic_groups g JOIN subjects s ON s.id=g.subject_id JOIN careers c ON c.id=s.career_id
            JOIN years y ON y.id=g.year_id JOIN years a ON a.id=g.admission_year_id JOIN shifts sh ON sh.id=g.shift_id
            ORDER BY y.name DESC,s.name")->fetchAll();
        foreach ($groups as $group) {
            $label = implode(' · ', [$group['subject'], $group['career'], $group['academic_year'], 'Ingreso ' . $group['admission'],
                $group['shift'], $group['students'] . ' estudiantes']);
            echo '<p>' . ($group['offering_id'] ? '<a href="?offering=' . e($group['offering_id']) . '">' . e($label) . '</a>' : e($label)) . '</p>';
        }
        if (!$groups) {
            echo '<p>Sin grupos registrados.</p>';
        }
    });
}

function studentCheckList(array $students): void
{
    echo '<fieldset class="mb-3"><legend class="h6">Estudiantes</legend><div class="ibm-student-list">';
    foreach ($students as $id => $name) {
        echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="student_ids[]" value="'
            . e($id) . '"> ' . e($name) . '</label>';
    }
    echo '</div></fieldset>';
}

function partsView(PDO $db, array $offering, array $rows): void
{
    $series = $offering['series_id'] ? query($db, 'SELECT part_count,completed FROM subject_series WHERE id=?', [$offering['series_id']])->fetch() : null;
    $count = $series['part_count'] ?? 1;
    $id = $offering['id'];
    $context = '<input type="hidden" name="offering_id" value="' . e($id) . '">'
        . '<input type="hidden" name="expected_parts" value="' . e($count) . '">';
    panel('Materia por partes', function () use ($db, $offering, $series, $rows, $context) {
        if ($series) {
            form('part-status');
            echo $context;
            if (!$series['completed']) {
                echo '<input type="hidden" name="completed" value="1">';
            }
            button($series['completed'] ? 'Reabrir serie' : 'Concluir serie');
            echo '</form>';
        }
        $modules = query($db, "SELECT m.id,CONCAT(y.name,' · ',m.name) FROM modules m JOIN years y ON y.id=m.year_id
            WHERE m.start_date>? ORDER BY m.start_date", [$offering['end_date']])->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!$modules) {
            return;
        }
        $modes = $series ? ['part-continue' => 'Crear continuación', 'part-recover' => 'Crear recuperación'] :
            ['part-continue' => 'Crear continuación'];
        if ($series && ($series['completed'] || $offering['part_recovery'] || $offering['part_number'] !== $series['part_count'])) {
            unset($modes['part-continue']);
        }
        foreach ($modes as $action => $label) {
            echo '<details class="mt-3"><summary>' . e($label) . '</summary>';
            form($action);
            echo $context;
            selectField('module_id', 'Módulo posterior', $modules);
            selectField('teacher_id', 'Docente', options($db, "SELECT id,name FROM users WHERE role='teacher' ORDER BY name"),
                selected: $offering['teacher_id']);
            studentCheckList(array_column($rows, 'name', 'student_id'));
            button($label);
            echo '</form></details>';
        }
        if (isset($modes['part-continue'])) {
            $targets = query($db, "SELECT o.id,CONCAT(y.name,' · ',m.name) FROM offerings o JOIN modules m ON m.id=o.module_id
                JOIN years y ON y.id=m.year_id WHERE o.subject_id=? AND o.shift_id=? AND o.series_id IS NULL AND m.start_date>?
                ORDER BY m.start_date", [$offering['subject_id'], $offering['shift_id'], $offering['end_date']])->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($targets) {
                echo '<details class="mt-3"><summary>Vincular asignación como continuación</summary>';
                form('part-link');
                echo $context;
                selectField('target_offering_id', 'Asignación posterior', $targets);
                button('Vincular');
                echo '</form></details>';
            }
        }
    });
}

function resetPasswordForm(int $id): void
{
    form('reset-password');
    echo '<input type="hidden" name="user_id" value="' . e($id) . '">';
    field('password', 'Contraseña temporal', 'password');
    button('Restablecer contraseña');
    echo '</form>';
}

function importView(): void
{
    panel('Importar catálogo institucional', function () {
        echo '<p>Selecciona el archivo .xlsx con la hoja BASE DE DATOS, hasta 2 MB.</p>';
        form('catalog-preview', true);
        echo '<label class="form-label d-block">Planilla Excel<input type="file" name="catalog" accept=".xlsx" class="form-control" required></label>';
        button('Revisar archivo');
        echo '</form>';
    });
    $preview = $_SESSION['catalog_preview'] ?? null;
    if (!$preview || $preview['until'] < time()) {
        return;
    }
    panel('Vista previa', function () use ($preview) {
        $catalog = $preview['catalog'];
        $rows = array_map(static fn ($row) => [$catalog['careers'][$row['career']], $row['level'], $row['code'], $row['name']], $catalog['subjects']);
        displayTable(['Carrera', 'Año', 'Código', 'Materia'], $rows);
        if ($catalog['teachers']) {
            echo '<p>Docentes en el archivo: ' . e(implode(' · ', $catalog['teachers'])) . '</p>';
        }
        foreach (array_unique($catalog['warnings']) as $warning) {
            echo '<p>' . e($warning) . '</p>';
        }
        form('catalog-apply');
        button('Aplicar catálogo');
        echo '</form>';
    });
}

function removalLink(string $type, int $id, string $label): string
{
    return '<a class="d-inline-block mt-2 text-danger" href="?page=review&amp;type=' . e($type) . '&amp;id=' . $id . '">' . e($label) . '</a>';
}

function removalView(PDO $db, string $type, int $id): void
{
    $state = transaction($db, static fn () => managementState($db, $type, $id));
    panel('Revisión antes de eliminar', function () use ($type, $id, $state) {
        $label = $state['record']['name'] ?? ('Registro ' . $id);
        echo '<p><strong>' . e($label) . '</strong></p><ul><li>Asignaciones vinculadas: ' . count($state['offerings'])
            . '</li><li>Inscripciones: ' . count($state['enrollments']) . '</li><li>Registros con notas: '
            . count(array_filter($state['enrollments'], 'hasNotes')) . '</li><li>Grupos vinculados: ' . count($state['groups']) . '</li>';
        foreach ($state['related'] as $table => $count) {
            echo '<li>' . e(['students'=>'Estudiantes','subjects'=>'Materias','study_plans'=>'Planes de estudio'][$table]) . ': ' . e($count) . '</li>';
        }
        echo '</ul>';
        form('delete');
        echo '<input type="hidden" name="type" value="' . e($type) . '"><input type="hidden" name="id" value="' . $id
            . '"><input type="hidden" name="revision" value="' . e($state['revision']) . '">';
        field('confirm', 'Escribe ' . removalPhrase($type));
        echo '<button class="btn btn-danger">Confirmar eliminación</button></form>';
    });
}

function rosterRemovalView(PDO $db, int $offering): void
{
    $rows = query($db, 'SELECT e.*,u.name FROM enrollments e JOIN users u ON u.id=e.student_id
        WHERE e.offering_id=? ORDER BY u.name', [$offering])->fetchAll();
    if (!$rows) {
        return;
    }
    panel('Retirar inscripciones', function () use ($rows, $offering) {
        foreach ($rows as $row) {
            $name = $row['name'];
            unset($row['name']);
            echo '<details class="border-bottom pb-2 mb-2"><summary>' . e($name) . '</summary>';
            form('remove-enrollment');
            echo '<input type="hidden" name="offering_id" value="' . $offering . '"><input type="hidden" name="enrollment_id" value="' . e($row['id'])
                . '"><input type="hidden" name="revision" value="' . e(hash('sha256', serialize($row))) . '">';
            field('confirm', 'Escribe ' . (hasNotes($row) ? 'RETIRAR CON NOTAS' : 'RETIRAR'));
            echo '<button class="btn btn-outline-danger">Retirar</button></form></details>';
        }
    });
}
