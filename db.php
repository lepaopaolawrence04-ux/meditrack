<?php
declare(strict_types=1);

// Sessions keep the signed-in user between page requests.
if (!is_dir(__DIR__ . '/storage/sessions')) mkdir(__DIR__ . '/storage/sessions', 0700, true);
session_save_path(__DIR__ . '/storage/sessions');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
date_default_timezone_set('Asia/Singapore');
if (!is_dir(__DIR__ . '/storage')) mkdir(__DIR__ . '/storage', 0700, true);
// MySQL connection settings are stored in storage/database-config.php.
$config = require __DIR__ . '/storage/database-config.php';
$db = new PDO(
 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
 $config['username'], $config['password'],
 [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$db->exec("SET time_zone = '+00:00'");

function query(string $sql, array $params = []): PDOStatement {
 global $db;
 $stmt = $db->prepare($sql); $stmt->execute($params); return $stmt;
}
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function logActivity(string $action): void { query('INSERT INTO logs(user_id,action) VALUES (?,?)', [$_SESSION['user_id'] ?? null, $action]); }
// Each transaction commits the business change and audit log together, or rolls both back.
function transaction(callable $work): void {
 global $db;
 $db->beginTransaction();
 try { $work(); $db->commit(); } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function redirect(string $page): void { header('Location: index.php?page=' . urlencode($page)); exit; }
function input(string $key, int $max = 1000): string {
 $value = trim((string)($_POST[$key] ?? ''));
 if ($value === '' || strlen($value) > $max) throw new RuntimeException('Please fill all required fields within the allowed length.');
 return $value;
}
function validDate(string $value): bool {
 $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
 return $date && $date->format('Y-m-d') === $value;
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function csrf(): void { echo '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }
$user = isset($_SESSION['user_id']) ? query('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']])->fetch(PDO::FETCH_ASSOC) : false;
function allowed(string $area): bool {
 global $user;
 $permissions = [
 'patients' => ['administrator','receptionist','nurse','doctor'],
 'appointments' => ['administrator','receptionist','nurse','doctor'],
 'records' => ['administrator','nurse','doctor'],
 'prescribe' => ['administrator','doctor'],
 'delete' => ['administrator'], 'reports' => ['administrator','doctor'], 'logs' => ['administrator']];
 return $user && in_array($user['role'], $permissions[$area] ?? [], true);
}


