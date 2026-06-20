<?php
// report.php — Endpoint público para reports de alunos
// public_html/plataforma/api/report.php
require_once __DIR__ . '/config.php';
setHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método inválido.', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) jsonError('Payload inválido.', 400);

// Tentar identificar o usuário pelo token (opcional)
$user = verifyToken(getToken());

$db = getDB();

// Criar tabela se não existir
$db->exec("CREATE TABLE IF NOT EXISTS gcm_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(120),
    user_email VARCHAR(180),
    plan VARCHAR(20),
    question_id VARCHAR(20),
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved TINYINT(1) DEFAULT 0,
    ip VARCHAR(60),
    INDEX idx_resolved (resolved),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->prepare("INSERT INTO gcm_reports (user_id,user_name,user_email,plan,question_id,description,ip) VALUES (?,?,?,?,?,?,?)")
   ->execute([
       $user['id'] ?? null,
       $user['name'] ?? ($data['user'] ?? 'Anônimo'),
       $user['email'] ?? ($data['email'] ?? ''),
       $user['plan'] ?? ($data['plan'] ?? 'free'),
       $data['qid'] ?? $data['question_id'] ?? '',
       $data['desc'] ?? $data['description'] ?? '',
       $_SERVER['REMOTE_ADDR'] ?? '',
   ]);

logActivity($user['id'] ?? 0, 'report', $data['qid'] ?? '');
jsonResponse(['ok' => true, 'id' => $db->lastInsertId()]);