<?php
require_once __DIR__ . '/config.php';

// Hotmart envia POST com JSON
header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$data    = json_decode($payload, true);

// Verificar autenticidade (Hotmart envia o secret no header)
$hmSecret = $_SERVER['HTTP_X_HOTMART_HOTTOK'] ?? $_SERVER['HTTP_HOTTOK'] ?? '';
if (HOTMART_SECRET !== 'COLE_SEU_HOTMART_SECRET_AQUI' && $hmSecret !== HOTMART_SECRET) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Extrair dados do payload Hotmart
$event       = $data['event'] ?? $data['data']['purchase']['status'] ?? 'UNKNOWN';
$buyerEmail  = strtolower($data['data']['buyer']['email'] ?? $data['data']['subscription']['subscriber']['email'] ?? '');
$productId   = (string)($data['data']['product']['id'] ?? '');
$value       = (float)($data['data']['purchase']['price']['value'] ?? 0);

// Mapear produto → plano
$products = json_decode(file_get_contents(__DIR__ . '/products.json') ?? '{}', true) ?: [];
// Fallback por valor
if (empty($products)) {
    $planActivated = $value >= 150 ? 'elite' : 'soldado';
} else {
    $planActivated = $products[$productId] ?? ($value >= 150 ? 'elite' : 'soldado');
}

$db = getDB();

// Log da transação
$logStmt = $db->prepare("
    INSERT INTO hotmart_transactions (event, buyer_email, product_id, plan_activated, status, value, raw_payload, received_at)
    VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
");
$logStmt->execute([$event, $buyerEmail, $productId, $planActivated, $value, $payload]);
$txId = $db->lastInsertId();

// Buscar aluno pelo e-mail
$userStmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$userStmt->execute([$buyerEmail]);
$user = $userStmt->fetch();

$status = 'ok';

if (in_array($event, ['PURCHASE_COMPLETE', 'PURCHASE_APPROVED', 'PURCHASE_BILLET_PRINTED'])) {
    // COMPRA APROVADA — ativar plano
    if ($user) {
        $oldPlan = $user['plan'];
        $db->prepare("UPDATE users SET plan = ?, hotmart_id = ? WHERE id = ?")
           ->execute([$planActivated, $productId, $user['id']]);

        // Log de migração
        $db->prepare("INSERT INTO plan_migrations (user_id, from_plan, to_plan, reason, done_at) VALUES (?, ?, ?, 'Compra Hotmart', NOW())")
           ->execute([$user['id'], $oldPlan, $planActivated]);

        logActivity($user['id'], 'hotmart_purchase', "Plano ativado: $planActivated | Produto: $productId | Valor: R$$value");
    } else {
        // Aluno não cadastrado — criar conta automática
        $tempPass = bin2hex(random_bytes(8));
        $insertStmt = $db->prepare("INSERT INTO users (name, email, password, plan, hotmart_id) VALUES (?, ?, ?, ?, ?)");
        $insertStmt->execute([
            ucwords(explode('@', $buyerEmail)[0]),
            $buyerEmail,
            hashPassword($tempPass),
            $planActivated,
            $productId
        ]);
        $newUserId = $db->lastInsertId();
        logActivity($newUserId, 'hotmart_auto_register', "Plano: $planActivated");
        // TODO: enviar e-mail com senha temporária via seu serviço de e-mail
    }

} elseif (in_array($event, ['PURCHASE_REFUNDED', 'PURCHASE_CHARGEBACK', 'PURCHASE_CANCELED'])) {
    // REEMBOLSO — rebaixar para free
    if ($user) {
        $oldPlan = $user['plan'];
        $db->prepare("UPDATE users SET plan = 'free' WHERE id = ?")->execute([$user['id']]);
        $db->prepare("INSERT INTO plan_migrations (user_id, from_plan, to_plan, reason, done_at) VALUES (?, ?, 'free', ?, NOW())")
           ->execute([$user['id'], $oldPlan, "Reembolso/Cancelamento: $event"]);
        logActivity($user['id'], 'hotmart_refund', "Evento: $event");
    }
    $status = 'refunded';

} elseif ($event === 'PURCHASE_DELAYED') {
    $status = 'pending';
}

// Atualizar status no log
$db->prepare("UPDATE hotmart_transactions SET status = ? WHERE id = ?")->execute([$status, $txId]);

http_response_code(200);
echo json_encode(['received' => true, 'event' => $event, 'status' => $status]);
