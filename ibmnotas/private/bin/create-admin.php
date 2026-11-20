<?php
declare(strict_types=1);

require __DIR__ . '/../app/database.php';
require __DIR__ . '/../app/auth.php';

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Uso: php bin/create-admin.php USUARIO 'Nombre' < archivo-privado-de-clave\n");
    exit(1);
}
$password = rtrim((string)fgets(STDIN), "\r\n");
if (strlen($password) < 8 || strlen($password) > 160 || $password === $argv[1]) {
    fwrite(STDERR, "Usa una clave distinta del usuario y de 8 a 160 bytes.\n");
    exit(1);
}
$db = connect(require __DIR__ . '/../config.php');
transaction($db, function () use ($db, $argv, $password) {
    if ($db->query("SELECT id FROM users WHERE role='admin' FOR UPDATE")->fetch()) {
        throw new RuntimeException('Ya existe un administrador.');
    }
    query($db, "INSERT INTO users (ci, name, role, password) VALUES (?, ?, 'admin', ?)",
        [$argv[1], $argv[2], accountPasswordHash($password)]);
});
echo "Administrador creado.\n";
