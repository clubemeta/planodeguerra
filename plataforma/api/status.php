<?php
// status.php — Estado da plataforma (manutenção etc.)
// public_html/plataforma/api/status.php
require_once __DIR__ . '/config.php';
setHeaders();

$method = $_SERVER['REQUEST_METHOD'];

// Arquivo de flag de manutenção
$flagFile = __DIR__ . '/maintenance.flag';

// ── LEITURA (GET) ─────────────────────────────────
if ($method === 'GET') {
    $maintenance = file_exists($flagFile);
    jsonResponse([
        'maintenance' => $maintenance,
        'version'     => '1.0',
        'status'      => $maintenance ? 'maintenance' : 'online',
    ]);
}

// ── ESCRITA (POST) — apenas admin ─────────────────
if ($method === 'POST') {
    // Verificar AdminBypass ou token de admin
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $isAdmin = false;

    if (strpos($authHeader, 'AdminBypass ') === 0) {
        $decoded = base64_decode(substr($authHeader, 12));
        $parts   = explode(':', $decoded, 2);
        $adminEmails = [ADMIN_EMAIL, 'clubemetaoficial@gmail.com'];
        if (count($parts) === 2 && in_array(trim($parts[0]), $adminEmails) && trim($parts[1]) === ADMIN_PASS) {
            $isAdmin = true;
        }
    } elseif (strpos($authHeader, 'Bearer ') === 0) {
        $user = verifyToken(substr($authHeader, 7));
        if ($user && $user['role'] === 'admin') $isAdmin = true;
    }

    if (!$isAdmin) jsonError('Acesso negado.', 403);

    $body = json_decode(file_get_contents('php://input'), true);
    $maintenance = !empty($body['maintenance']);

    if ($maintenance) {
        file_put_contents($flagFile, date('Y-m-d H:i:s'));
    } else {
        if (file_exists($flagFile)) unlink($flagFile);
    }

    jsonResponse(['ok' => true, 'maintenance' => $maintenance]);
}

jsonError('Método inválido.', 405);