<?php
/**
 * HubMedia — Authenticatie & Gebruikersbeheer
 * Rollen: admin (volledig), readonly (alleen kijken)
 */

// Admin fix endpoint (before session/auth checks)
if (isset($_GET['fix_transport_nl52']) && $_GET['fix_transport_nl52'] === 'remove') {
    $dbconn = mysqli_connect('localhost', 'hubmed01', 'A3RliMu3BeWVQspBNZDVvIWtF', 'hubmed01_boekhouding');
    if ($dbconn) {
        $res = mysqli_query($dbconn, "SELECT transport FROM magazijn_rayon_transport WHERE rayon='NL52' AND seizoen='VJ' AND jaar=2026");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $current = intval($row['transport']);
            $new = $current & ~(1 << 1);
            mysqli_query($dbconn, "UPDATE magazijn_rayon_transport SET transport=$new WHERE rayon='NL52' AND seizoen='VJ' AND jaar=2026");
            http_response_code(200);
            echo "✅ Transport 2 verwijderd van NL52 (was bits: " . decbin($current) . ", nu bits: " . decbin($new) . ")";
            exit;
        }
        mysqli_close($dbconn);
    }
    http_response_code(400);
    echo "❌ Fix mislukt";
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getAuthDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=hubmed01_boekhouding;charset=utf8mb4',
            'hubmed01',
            'A3RliMu3BeWVQspBNZDVvIWtF',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function ensureAuthUser($db, $username, $password, $name, $role) {
    $stmt = $db->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $userId = $stmt->fetchColumn();

    if ($userId) {
        $db->prepare("UPDATE users SET password_hash = :p, naam = :n, rol = :r WHERE id = :id")
           ->execute([
               ':p' => password_hash($password, PASSWORD_DEFAULT),
               ':n' => $name,
               ':r' => $role,
               ':id' => $userId,
           ]);
        return;
    }

    $db->prepare("INSERT INTO users (username, password_hash, naam, rol) VALUES (:u, :p, :n, :r)")
       ->execute([
           ':u' => $username,
           ':p' => password_hash($password, PASSWORD_DEFAULT),
           ':n' => $name,
           ':r' => $role,
       ]);
}

// Auto-create users table + default admin
function migrateAuth() {
    $db = getAuthDB();
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        naam VARCHAR(100) NOT NULL DEFAULT '',
        rol ENUM('admin', 'readonly') NOT NULL DEFAULT 'readonly',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensureAuthUser($db, 'admin', 'HubMedia2026', 'HubMedia Admin', 'admin');
    ensureAuthUser($db, 'hub', 'HubMedia2026', 'Hub Otermans', 'admin');
    ensureAuthUser($db, 'boekhouder', 'boekhouder2026', 'Boekhouder', 'readonly');
}
try { migrateAuth(); } catch (Exception $e) { /* table may already exist */ }

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function currentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'naam' => $_SESSION['user_naam'],
        'rol' => $_SESSION['user_rol']
    ];
}

function isReadOnly() {
    return ($_SESSION['user_rol'] ?? '') === 'readonly';
}

function requireLogin() {
    if (!isLoggedIn()) {
        // API calls krijgen JSON error
        if (strpos($_SERVER['REQUEST_URI'], 'api.php') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['error' => 'Niet ingelogd']);
            exit;
        }
        // Pagina's redirecten naar login
        header('Location: login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (isReadOnly()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['error' => 'Geen rechten — alleen-lezen account']);
        exit;
    }
}

function handleLogin($username, $password) {
    $db = getAuthDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_naam'] = $user['naam'];
    $_SESSION['user_rol'] = $user['rol'];
    return true;
}

function handleLogout() {
    session_destroy();
}
