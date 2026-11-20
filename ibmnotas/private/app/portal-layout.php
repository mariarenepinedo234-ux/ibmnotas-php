<?php
declare(strict_types=1);

function portalIcon(string $name): string
{
    $icons = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'students' => '<path d="m2 8 10-5 10 5-10 5zM6 10v7q6 5 12 0v-7M22 8v9"/>',
        'people' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a6 6 0 0 1 3 5v2"/>',
        'book' => '<path d="M12 5v16M3 3q5-1 9 2 4-3 9-2v16q-5-1-9 2-4-3-9-2z"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v5m10-5v5M3 11h18m-14 4h2m6 0h2m-10 3h2"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'down' => '<path d="m7 10 5 5 5-5"/>',
        'settings' => '<path d="m10 3-1 3-3 1-3 3 2 2-1 4 3 2 2-1 3 4 3-2 3-1v-4l3-2-2-3-3-1-1-3z"/><circle cx="12" cy="12" r="3"/>',
        'logout' => '<path d="M9 4H4v16h5m5-4 4-4-4-4m-6 4h13"/>',
        'shield' => '<path d="m12 3 8 3v6q0 6-8 10-8-4-8-10V6z"/><path d="m8 12 3 3 5-6"/>',
        'file' => '<path d="M14 3H5v18h14V8zM14 3v5h5M8 12h8m-8 4h6"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1 1m12 12 1 1M5 19l1-1M18 6l1-1"/>',
    ];
    return '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($icons[$name] ?? $icons['file']) . '</svg>';
}

function portalBrand(): string
{
    return '<span class="ibm-brand"><img src="assets/logo_ibm.png" alt="">'
        . '<span class="ibm-brand-name"><strong>IBM</strong><span>Instituto Técnico<br>Británico Mercantil</span></span></span>';
}

function portalThemeButton(): string
{
    return '<button type="button" class="theme-toggle" data-theme-toggle aria-label="Cambiar tema" aria-pressed="false">'
        . portalIcon('sun') . '<span>Cambiar tema</span></button>';
}

function portalAuth(?array $user, string $error): void
{
    $changing = $user !== null;
    ?>
<main class="auth-page">
  <div class="auth-theme"><?= portalThemeButton() ?></div>
  <div class="auth-content">
    <section class="auth-card" aria-labelledby="login-title">
      <div class="auth-brand"><?= portalBrand() ?></div>
      <div class="auth-heading">
        <span class="eyebrow">PORTAL ACADÉMICO</span>
        <h1 id="login-title"><?= $changing ? 'Cambiar contraseña' : 'Bienvenido a IBM' ?></h1>
        <p><?= $changing ? 'Actualiza tu contraseña para proteger tu cuenta.' : 'Sistema de registro y seguimiento de notas' ?></p>
      </div>
      <?php form($changing ? 'password' : 'login'); ?>
        <label for="login-user"><?= $changing ? 'Contraseña actual' : 'Usuario o carnet de identidad' ?></label>
        <div class="auth-input">
          <?= portalIcon($changing ? 'lock' : 'people') ?>
          <input id="login-user" name="<?= $changing ? 'current' : 'ci' ?>" type="<?= $changing ? 'password' : 'text' ?>"
            autocomplete="<?= $changing ? 'current-password' : 'username' ?>"
            placeholder="<?= $changing ? 'Ingresa tu contraseña actual' : 'Ingresa tu usuario o C.I.' ?>"
            required maxlength="160" autocapitalize="none" spellcheck="false">
        </div>
        <label for="login-password"><?= $changing ? 'Contraseña nueva' : 'Contraseña' ?></label>
        <div class="auth-input">
          <?= portalIcon('lock') ?>
          <input id="login-password" name="password" type="password" autocomplete="<?= $changing ? 'new-password' : 'current-password' ?>"
            placeholder="<?= $changing ? 'Ingresa tu contraseña nueva' : 'Ingresa tu contraseña' ?>" required maxlength="160">
          <button type="button" id="toggle-password" aria-label="Mostrar contraseña" aria-pressed="false">Ver</button>
        </div>
        <p class="form-error" role="alert" aria-live="polite"><?= e($error) ?></p>
        <button type="submit" class="btn primary wide"><?= $changing ? 'Cambiar contraseña' : 'Iniciar sesión' ?> <?= portalIcon('arrow') ?></button>
      </form>
      <?php if ($changing): ?>
        <?php form('logout'); ?><button type="submit" class="auth-help">Cerrar sesión</button></form>
      <?php else: ?>
        <button type="button" class="auth-help" id="login-help">¿Olvidaste tu contraseña?</button>
      <?php endif; ?>
      <div class="auth-security"><?= portalIcon('shield') ?> Tu información académica está protegida.</div>
    </section>
    <footer class="auth-footer">© <?= gmdate('Y') ?> Instituto Técnico Británico Mercantil</footer>
  </div>
</main>
<dialog id="access-help"><div class="modal-header"><h2>Recuperar el acceso</h2></div><div class="modal-body">
  <p>Comunícate con secretaría para solicitar el restablecimiento de tu contraseña.</p>
  <form method="dialog" class="form-actions"><button class="btn primary">Cerrar</button></form>
</div></dialog>
    <?php
}

function portalNav(array $user): array
{
    return match ($user['role']) {
        'admin' => [
            '' => ['grid', 'Resumen general'], 'students' => ['students', 'Estudiantes'],
            'teachers' => ['people', 'Docentes'], 'assignments' => ['book', 'Materias y asignaciones'],
            'plans' => ['file', 'Planes de estudio'], 'catalog' => ['calendar', 'Gestiones y módulos'],
            'groups' => ['students', 'Grupos académicos'],
        ],
        'teacher' => [
            '' => ['grid', 'Mi resumen'], 'assignments' => ['book', 'Mis materias y notas'],
            'plans' => ['file', 'Planes de estudio'], 'calendar' => ['calendar', 'Calendario académico'],
        ],
        default => [
            '' => ['grid', 'Mi resumen'], 'results' => ['book', 'Mis calificaciones'],
            'history' => ['file', 'Mi historial académico'], 'calendar' => ['calendar', 'Calendario académico'],
        ],
    };
}

function portalInitials(string $name): string
{
    $words = array_slice(preg_split('/\s+/u', trim($name)) ?: [], 0, 2);
    return implode('', array_map(static fn ($word) => mb_substr($word, 0, 1), $words));
}

function portalLink(string $page, string $icon, string $label, bool $active = false): string
{
    return '<a href="' . ($page === '' ? './' : '?page=' . e($page)) . '" class="nav-link' . ($active ? ' active" aria-current="page' : '')
        . '">' . portalIcon($icon) . '<span>' . e($label) . '</span>' . ($active ? '<span class="active-dot"></span>' : '') . '</a>';
}

function portalShell(array $user, string $page): void
{
    $nav = portalNav($user);
    $roles = ['admin' => 'Administración', 'teacher' => 'Docente', 'student' => 'Estudiante'];
    $title = $nav[$page][1] ?? ($page === 'backups' ? 'Copias de seguridad' : $nav[''][1]);
    ?>
<div class="app-layout">
  <aside class="sidebar" id="portal-menu">
    <a class="brand" href="./" aria-label="Inicio IBM"><?= portalBrand() ?></a>
    <div class="workspace-label">ESPACIO <?= $user['role'] === 'admin' ? 'ADMINISTRATIVO' : 'ACADÉMICO' ?></div>
    <nav aria-label="Navegación principal">
      <?php foreach ($nav as $key => [$icon, $label]): ?><?= portalLink($key, $icon, $label, $page === $key) ?><?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
      <?php if ($user['role'] === 'admin'): ?><?= portalLink('backups', 'download', 'Copias de seguridad', $page === 'backups') ?><?php endif; ?>
      <?= portalLink('password', 'shield', 'Seguridad de mi cuenta') ?>
      <?php form('logout'); ?><button class="nav-link logout" type="submit"><?= portalIcon('logout') ?><span>Cerrar sesión</span></button></form>
    </div>
  </aside>
  <div class="main-column">
    <header class="topbar">
      <div class="breadcrumb">
        <button type="button" class="icon-button mobile-menu" aria-label="Abrir menú" aria-expanded="false" aria-controls="portal-menu"><?= portalIcon('menu') ?></button>
        <span>Portal académico</span><span class="slash">/</span><strong><?= e($title) ?></strong>
      </div>
      <div class="topbar-right">
        <?= portalThemeButton() ?><span class="demo-indicator">Portal institucional</span><div class="topbar-divider"></div>
        <a class="profile" href="?page=password"><span class="avatar"><?= e(portalInitials($user['name'])) ?></span>
          <span><strong><?= e($user['name']) ?></strong><small><?= e($roles[$user['role']]) ?></small></span><?= portalIcon('down') ?>
        </a>
      </div>
    </header>
    <main id="content" class="content">
    <?php
}

function portalDashboard(PDO $db, array $user): void
{
    $isAdmin = $user['role'] === 'admin';
    $where = $isAdmin ? '' : ' WHERE o.teacher_id=?';
    $params = $isAdmin ? [] : [$user['id']];
    $groups = query($db, 'SELECT o.id,s.name,s.level,s.career_id FROM offerings o JOIN subjects s ON s.id=o.subject_id' . $where, $params)->fetchAll();
    $subjectKeys = [];
    foreach ($groups as $group) {
        $subjectKeys[nameKey($group['name']) . ':' . $group['level']] = true;
    }
    $grades = query($db, 'SELECT COUNT(DISTINCT e.student_id) AS students, COUNT(*)-COUNT(e.grade) AS pending
        FROM enrollments e JOIN offerings o ON o.id=e.offering_id' . $where, $params)->fetch();
    $students = $isAdmin ? $db->query('SELECT count(*) FROM students')->fetchColumn() : $grades['students'];
    $teachers = $isAdmin ? $db->query("SELECT count(*) FROM users WHERE role='teacher'")->fetchColumn() : count($groups);
    $stats = [
        [$isAdmin ? 'Estudiantes registrados' : 'Estudiantes asignados', $students, 'students', 'wine'],
        [$isAdmin ? 'Docentes registrados' : 'Grupos asignados', $teachers, 'people', 'sand'],
        ['Materias diferentes', count($subjectKeys), 'book', 'gray'],
        ['Notas pendientes', $grades['pending'], 'calendar', 'rose'],
    ];
    ?>
<div class="page-heading"><div><div class="eyebrow">TU INSTITUTO, EN UN SOLO LUGAR</div>
<h1>Hola, <?= e($user['name']) ?> <span class="wave">✦</span></h1>
<p><?= $isAdmin ? 'Esto es lo que está pasando en tu comunidad académica.' : 'Estas son tus materias y registros pendientes.' ?></p></div></div>
<section class="welcome-banner">
  <div class="banner-copy"><h2>Una mejor gestión.<br><em>Más oportunidades para aprender.</em></h2>
  <p><?= $isAdmin ? 'Organiza, acompaña y conecta cada etapa de la vida académica.' : 'Registra los logros de tus estudiantes y acompaña su avance.' ?></p>
  <a class="banner-link" href="?page=<?= $isAdmin ? 'catalog' : 'assignments' ?>"><?= $isAdmin ? 'Ver calendario académico' : 'Registrar calificaciones' ?> <?= portalIcon('arrow') ?></a></div>
  <div class="banner-art" aria-hidden="true"><div class="art-circle c1"></div><div class="art-circle c2"></div>
    <div class="art-dot d1"></div><div class="art-dot d2"></div><div class="art-book"><?= portalIcon('book') ?></div>
    <span class="art-spark">✦</span><div class="art-caption">CRECER · APRENDER · TRANSFORMAR</div></div>
</section>
<section class="stats-grid">
  <?php foreach ($stats as [$label, $value, $icon, $color]): ?>
  <article class="stat-card"><div class="stat-top"><span><?= e($label) ?></span>
    <span class="stat-icon <?= e($color) ?>"><?= portalIcon($icon) ?></span></div><strong class="stat-value"><?= e($value) ?></strong></article>
  <?php endforeach; ?>
</section>
    <?php
}

function portalCalendar(PDO $db, array $user): void
{
    $sql = 'SELECT m.*, y.name AS academic_year, a.name AS admission_year FROM modules m JOIN years y ON y.id=m.year_id
        LEFT JOIN years a ON a.id=m.admission_year_id';
    $params = [];
    if ($user['role'] === 'student') {
        $profile = query($db, 'SELECT admission_year_id FROM students WHERE user_id=?', [$user['id']])->fetchColumn();
        $sql .= ' WHERE m.admission_year_id=? OR (m.admission_year_id IS NULL AND NOT EXISTS
            (SELECT 1 FROM modules p WHERE p.year_id=m.year_id AND p.admission_year_id=?)) OR EXISTS
            (SELECT 1 FROM offerings o JOIN enrollments e ON e.offering_id=o.id WHERE o.module_id=m.id AND e.student_id=?)';
        $params = [$profile, $profile, $user['id']];
    }
    $modules = query($db, $sql . ' ORDER BY y.name DESC,m.start_date', $params)->fetchAll();
    $today = (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
    echo '<div class="page-heading"><div><h1>Calendario académico</h1></div></div><div class="module-grid">';
    foreach ($modules as $module) {
        $status = $today < $module['start_date'] ? 'Próximamente' : ($today > $module['end_date'] ? 'Finalizado' : 'En curso');
        ?>
<article class="panel module-card">
  <div class="module-card-top"><span class="module-number"><?= portalIcon('calendar') ?></span><span class="badge neutral"><?= e($status) ?></span></div>
  <span class="eyebrow">GESTIÓN <?= e($module['academic_year']) ?></span>
  <h2><?= e($module['name']) ?></h2><p class="module-calendar-label"><?= e($module['admission_year'] ? 'Ingreso ' . $module['admission_year'] : 'Calendario compartido') ?></p>
  <div class="module-dates"><div><span>INICIO</span><strong><?= e($module['start_date']) ?></strong></div><?= portalIcon('arrow') ?>
    <div><span>FINALIZACIÓN</span><strong><?= e($module['end_date']) ?></strong></div></div>
</article>
        <?php
    }
    if (!$modules) {
        echo '<p>Sin módulos registrados.</p>';
    }
    echo '</div>';
}
