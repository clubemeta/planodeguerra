<?php
// ══════════════════════════════════════════════════════════
// ai_proxy.php — Proxy seguro para API Anthropic
// public_html/plataforma/api/ai_proxy.php
// ══════════════════════════════════════════════════════════

require_once __DIR__ . '/config.php';
setHeaders();

// ── SUA CHAVE ANTHROPIC ───────────────────────────────────
// Obtenha em: https://console.anthropic.com/
define('ANTHROPIC_API_KEY', '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');
// ─────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método inválido.', 405);
}

if (ANTHROPIC_API_KEY === 'COLE_SUA_CHAVE_AQUI' || empty(ANTHROPIC_API_KEY)) {
    jsonError('Chave da API Anthropic não configurada. Edite ai_proxy.php e cole sua chave em ANTHROPIC_API_KEY.', 503);
}

// ── AUTENTICAÇÃO ──────────────────────────────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$user = null;
$isAdminBypass = false;

// Verificar AdminBypass (admin logado via JS sem token do banco)
if (strpos($authHeader, 'AdminBypass ') === 0) {
    $decoded = base64_decode(substr($authHeader, 12));
    $parts   = explode(':', $decoded, 2);
    if (count($parts) === 2) {
        $email = $parts[0];
        $pass  = $parts[1];
        // Verificar se é um dos emails admin configurados
        $adminEmails = [ADMIN_EMAIL, 'clubemetaoficial@gmail.com'];
        if (in_array($email, $adminEmails) && $pass === ADMIN_PASS) {
            $isAdminBypass = true;
            $user = ['id' => 0, 'role' => 'admin', 'plan' => 'elite', 'name' => 'Administrador'];
        }
    }
}

// Verificar token Bearer normal
if (!$user) {
    $token = null;
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
    }
    if ($token) {
        $user = verifyToken($token);
    }
}

// Ler payload
$body    = file_get_contents('php://input');
$payload = json_decode($body, true);

if (!$payload || !isset($payload['messages'])) {
    jsonError('Payload inválido — campo messages ausente.', 400);
}
// Garantir model e max_tokens padrão
if (!isset($payload['model'])) $payload['model'] = 'claude-haiku-4-5-20251001';
if (!isset($payload['max_tokens'])) $payload['max_tokens'] = 2000;

// Tipo de uso
$isRedacao  = !empty($payload['redacao_correction']);
$isChat     = !empty($payload['chat_aluno']);
$isGerador  = !empty($payload['gerador_questoes']) || (!$isRedacao && !$isChat);

// Limpar flags internas antes de enviar à Anthropic
unset($payload['redacao_correction'], $payload['chat_aluno'], $payload['gerador_questoes']);

// ── PERMISSÕES ────────────────────────────────────────────
if ($isGerador) {
    // Gerador: apenas admin
    if (!$user || ($user['role'] !== 'admin' && !$isAdminBypass)) {
        jsonError('Gerador de questões disponível apenas para administradores. Faça login com a conta admin no banco de dados.', 403);
    }
} elseif ($isRedacao) {
    // Redação: verificar redacao_enabled
    if (!$user && !$isAdminBypass) {
        jsonError('Faça login para usar a correção de redação.', 401);
    }
    if ($user && !$isAdminBypass) {
        try {
            $db = getDB();
            $row = $db->prepare("SELECT redacao_enabled FROM users WHERE id=?");
            $row->execute([$user['id']]);
            $r = $row->fetch();
            if (!$r || !$r['redacao_enabled']) {
                jsonError('Redação não ativada. Adquira o produto de correção de redação.', 403);
            }
        } catch(Exception $e) {}
    }
} elseif ($isChat) {
    // Chat/Suporte: disponível para TODOS (inclusive sem login)
    // É o suporte da plataforma - acesso livre
    // Sem restrição de plano ou autenticação
}

// ── CHAMAR API ANTHROPIC ──────────────────────────────────
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    jsonError('Erro ao conectar com Anthropic: ' . $curlError, 502);
}

if ($response === false || empty($response)) {
    jsonError('Resposta vazia da API Anthropic. Tente novamente.', 502);
}

// Log de uso
try {
    $db = getDB();
    $tipo = $isGerador ? 'gerador' : ($isRedacao ? 'redacao' : 'chat');
    $db->prepare("INSERT INTO activity_log (user_id, action, detail, ip, created_at) VALUES (?, 'ai_use', ?, ?, NOW())")
       ->execute([$user['id'] ?? 0, $tipo . ' · ' . ($payload['model'] ?? '?'), $_SERVER['REMOTE_ADDR'] ?? '']);
} catch (Exception $e) {}

http_response_code($httpCode);
header('Content-Type: application/json; charset=utf-8');
// Ensure valid JSON and transform Anthropic errors for JS
$decoded = json_decode($response, true);
if ($decoded === null) {
    jsonError('Resposta inválida da API. Tente novamente.', 502);
}
// Normalize error format
if (isset($decoded['error']) && is_array($decoded['error'])) {
    $decoded['error'] = $decoded['error']['message'] ?? json_encode($decoded['error']);
}
echo json_encode($decoded);