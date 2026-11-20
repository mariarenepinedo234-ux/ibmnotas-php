<?php
declare(strict_types=1);

$root = __DIR__ . '/private';
require $root . '/app/database.php';
require $root . '/app/auth.php';
require $root . '/app/academic.php';
require $root . '/app/admin.php';
require $root . '/app/backup.php';
require $root . '/app/plans.php';
require $root . '/app/groups.php';
require $root . '/app/catalog.php';
require $root . '/app/parts.php';
require $root . '/app/history.php';
require $root . '/app/management.php';
require $root . '/app/exports.php';
require $root . '/app/views.php';

function redirect(string $location = './'): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
date_default_timezone_set('UTC');

try {
    $config = require $root . '/config.php';
    $db = connect($config);
} catch (Throwable $error) {
    error_log('IBM Notas: configuración de base de datos no disponible.');
    http_response_code(503);
    exit('El servicio no está disponible. Consulta con administración.');
}

session_name('ibmnotas');
session_start([
    'use_strict_mode' => true, 'cookie_secure' => $config['secure_cookies'],
    'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'gc_maxlifetime' => 28800,
]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$user = null;
if (isset($_SESSION['user_id'], $_SESSION['stamp']) && $_SESSION['expires'] > time()) {
    $user = query($db, 'SELECT id,ci,name,role,password,must_change,blocked FROM users WHERE id=?', [$_SESSION['user_id']])->fetch();
    if (!$user || !hash_equals($_SESSION['stamp'], sessionStamp($user['ci'] ?? '', $user['password'] ?? ''))) {
        $user = null;
    }
}

$errorMessage = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            throw new DomainException('El formulario venció. Recarga la página.');
        }
        $action = $_POST['action'] ?? '';
        if (!$user) {
            $password = $_POST['password'] ?? '';
            $ci = trim($_POST['ci'] ?? '');
            if ($action !== 'login' || $ci === '' || strlen($ci) > 255 || strlen($password) > 160 ||
                !signIn($db, $ci, $password, $_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
                throw new DomainException('Credenciales incorrectas o demasiados intentos.');
            }
            redirect();
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirect();
        }
        if ($action === 'password') {
            $old = $_POST['current'] ?? '';
            $new = $_POST['password'] ?? '';
            if (strlen($old) > 160 || strlen($new) < 8 || strlen($new) > 160 || $old === $new ||
                $new === $user['ci'] || !validPassword($old, $user['password'])) {
                throw new DomainException('La clave actual debe ser correcta y la nueva debe tener de 8 a 160 bytes y ser distinta del usuario y de la anterior.');
            }
            $newHash = accountPasswordHash($new);
            transaction($db, function () use ($db, $newHash, $user) {
                $result = query($db, 'UPDATE users SET password=?,must_change=0 WHERE id=? AND password=?',
                    [$newHash, $user['id'], $user['password']]);
                if ($result->rowCount() !== 1) {
                    throw new DomainException('La contraseña cambió. Inicia sesión de nuevo.');
                }
                audit($db, $user['id'], 'Contraseña actualizada', $user['name']);
            });
            session_regenerate_id(true);
            $_SESSION['stamp'] = sessionStamp($user['ci'], $newHash);
            redirect();
        }
        if ($user['must_change']) {
            throw new DomainException('Cambia tu contraseña inicial.');
        }
        if ($action === 'grades') {
            $id = integer($_POST['offering_id']);
            if (($_POST['complete'] ?? '') !== '1') {
                throw new DomainException('El formulario llegó incompleto. Aumenta max_input_vars o reduce el tamaño del grupo.');
            }
            saveGrades($db, $user, $id, $_POST['grades'] ?? []);
            redirect('?offering=' . $id);
        }
        if ($user['role'] !== 'admin') {
            throw new DomainException('Acceso restringido.');
        }
        redirect(adminAction($db, $user, $action, $_POST, $config));
    }
    if ($user && !$user['must_change']) {
        if (isset($_GET['plan'])) {
            if (!in_array($user['role'], ['admin', 'teacher'], true)) {
                throw new DomainException('Acceso restringido.');
            }
            downloadPlan($db, integer($_GET['plan']));
            exit;
        }
        if (isset($_GET['export'])) {
            $format = $_GET['export'];
            if (!in_array($format, ['pdf', 'xlsx'], true)) {
                throw new DomainException('Formato inválido.');
            }
            [$offering, $rows] = transaction($db, static fn () => gradebook($db, $user, integer($_GET['offering'] ?? null)));
            $draft = isset($_GET['draft']);
            foreach ($rows as $row) {
                if ((!$draft && $row['grade'] === null) || $row['saber'] > 40 || $row['hacer'] > 40 || $row['ser'] > 20) {
                    throw new DomainException('Completa las notas y revisa los puntajes de escalas anteriores. Para notas aún pendientes puedes descargar un borrador.');
                }
            }
            if (!$rows) {
                throw new DomainException('La planilla está vacía.');
            }
            audit($db, $user['id'], $draft ? 'Borrador exportado' : 'Planilla exportada', $offering['subject'] . ' · ' . $format);
            session_write_close();
            exportReport($offering, $rows, $format, $draft);
            exit;
        }
        if (isset($_GET['backup'])) {
            $filename = $_GET['backup'];
            $path = $config['backup_directory'] . '/' . $filename;
            if ($user['role'] !== 'admin' || !preg_match('/^ibm-notas-\d{8}-\d{6}-[a-f0-9]{16}\.jsonl$/D', $filename) || !is_file($path)) {
                throw new DomainException('Respaldo no disponible.');
            }
            audit($db, $user['id'], 'Copia de seguridad descargada', $filename);
            session_write_close();
            header('Content-Type: application/octet-stream');
            header("Content-Disposition: attachment; filename=\"$filename\"");
            readfile($path);
            exit;
        }
    }
} catch (DomainException $error) {
    $errorMessage = $error->getMessage();
    http_response_code(400);
} catch (PDOException $error) {
    $errorMessage = $error->getCode() === '23000' ? 'La operación entra en conflicto con un registro existente o una relación académica.'
        : 'No se pudo guardar la operación. Vuelve a intentarlo.';
    error_log('IBM Notas: error SQL ' . $error->getCode());
    http_response_code($error->getCode() === '23000' ? 409 : 500);
} catch (Throwable $error) {
    $errorMessage = 'No se pudo completar la operación.';
    error_log('IBM Notas: ' . get_class($error) . ' en ' . basename($error->getFile()) . ':' . $error->getLine());
    http_response_code(500);
}
render($db, $config, $user, $errorMessage);
