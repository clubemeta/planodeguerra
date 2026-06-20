<?php
// webhook.php — Hotmart Automação Completa
// public_html/plataforma/api/webhook.php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$data    = json_decode($payload, true);

// ── Verificar autenticidade ──────────────────────────
$hmSecret = $_SERVER['HTTP_X_HOTMART_HOTTOK'] ?? $_SERVER['HTTP_HOTTOK'] ?? '';
if ($hmSecret !== HOTMART_SECRET) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$event      = $data['event'] ?? 'UNKNOWN';
$buyerEmail = strtolower($data['data']['buyer']['email'] ?? '');
$buyerName  = $data['data']['buyer']['name'] ?? ucwords(explode('@', $buyerEmail)[0]);
$offerCode  = $data['data']['purchase']['offer']['code'] ?? '';
$productId  = (string)($data['data']['product']['id'] ?? '');
$value      = (float)($data['data']['purchase']['price']['value'] ?? 0);

// ── Mapear produto → tipo ────────────────────────────
// products.json mapeia offer codes e product IDs para planos
$products = [];
if (file_exists(__DIR__ . '/products.json')) {
    $products = json_decode(file_get_contents(__DIR__ . '/products.json'), true) ?: [];
}

// Verificar se é produto de REDAÇÃO (offer code ou product ID específico)
$isRedacaoProduct = false;
$redacaoOffers = $products['redacao_offers'] ?? ['redacao_offer_code']; // codes do produto redação
if (in_array($offerCode, $redacaoOffers) || ($products[$offerCode] ?? '') === 'redacao') {
    $isRedacaoProduct = true;
}

// Determinar plano (para produtos não-redação)
$planActivated = match(true) {
    isset($products[$offerCode]) && $products[$offerCode] !== 'redacao' => $products[$offerCode],
    isset($products[$productId]) => $products[$productId],
    $value >= 150 => 'elite',
    default       => 'soldado',
};

$db = getDB();

// Log
$db->prepare("INSERT INTO hotmart_transactions (event,buyer_email,product_id,plan_activated,status,value,raw_payload,received_at) VALUES (?,?,?,?,'pending',?,?,NOW())")
   ->execute([$event, $buyerEmail, $productId, $isRedacaoProduct ? 'redacao' : $planActivated, $value, $payload]);
$txId = $db->lastInsertId();

// Buscar aluno
$userStmt = $db->prepare("SELECT * FROM users WHERE email=?");
$userStmt->execute([$buyerEmail]);
$user = $userStmt->fetch();
$status = 'ok';
$userName = $user['name'] ?? $buyerName;
$planLabel = strtoupper($isRedacaoProduct ? 'Redação' : $planActivated);

// ════════════════════════════════════════════════════
// COMPRA APROVADA
// ════════════════════════════════════════════════════
if (in_array($event, ['PURCHASE_COMPLETE', 'PURCHASE_APPROVED'])) {

    if ($isRedacaoProduct) {
        // ── PRODUTO REDAÇÃO ──────────────────────────
        if ($user) {
            // Ativar redação para usuário existente
            $db->prepare("UPDATE users SET redacao_enabled=1 WHERE id=?")->execute([$user['id']]);
            logActivity($user['id'], 'redacao_purchase', "R\$$value");

            // Email: Acesso redação liberado
            $html = emailTemplate(
                '✍️ Correção de Redação liberada!',
                "Olá, <strong>$userName</strong>!<br><br>
                Seu acesso à <strong>Correção de Redação</strong> foi ativado!<br><br>
                ✅ Envie sua redação manuscrita (foto ou PDF)<br>
                ✅ Nossa equipe avalia nos 5 critérios da banca<br>
                ✅ Receba nota de 0 a 100 com feedback detalhado<br><br>
                Acesse agora → menu <strong>✍️ Redação</strong> na plataforma.",
                '✍️ Enviar Minha Redação →',
                SITE_URL . '/plataforma/'
            );
            sendEmail($buyerEmail, '✍️ Correção de Redação liberada — Plano de Guerra!', $html, $userName);

        } else {
            // Comprador de redação sem conta — criar conta e liberar redação
            $tempPass = bin2hex(random_bytes(6));
            $db->prepare("INSERT INTO users (name,email,password,plan,redacao_enabled,hotmart_id,joined_at) VALUES (?,?,?,'free',1,?,NOW())")
               ->execute([$buyerName, $buyerEmail, hashPassword($tempPass), $productId]);
            $newId = $db->lastInsertId();

            // Token de primeiro acesso (7 dias)
            $resetToken = 'reset_' . bin2hex(random_bytes(16));
            $db->prepare("INSERT INTO sessions (user_id,token,expires_at) VALUES (?,?,?)")
               ->execute([$newId, $resetToken, date('Y-m-d H:i:s', strtotime('+7 days'))]);
            $resetUrl = SITE_URL . '/plataforma/?reset_token=' . $resetToken;

            $html = emailTemplate(
                '✍️ Seu acesso à Correção de Redação está pronto!',
                "Olá, <strong>$buyerName</strong>!<br><br>
                Criamos sua conta e seu acesso à <strong>Correção de Redação</strong> já está ativo!<br><br>
                📧 Login: <strong>$buyerEmail</strong><br><br>
                Clique abaixo para criar sua senha e acessar:",
                '🔑 Criar Senha e Acessar',
                $resetUrl
            );
            sendEmail($buyerEmail, '✍️ Acesso Correção de Redação — Plano de Guerra!', $html, $buyerName);
        }

    } else {
        // ── PRODUTO PLANO (soldado/elite) ────────────
        if ($user) {
            $oldPlan = $user['plan'];
            $db->prepare("UPDATE users SET plan=?,hotmart_id=? WHERE id=?")
               ->execute([$planActivated, $productId, $user['id']]);
            $db->prepare("INSERT INTO plan_migrations (user_id,from_plan,to_plan,reason,done_at) VALUES (?,?,?,'Hotmart',NOW())")
               ->execute([$user['id'], $oldPlan, $planActivated]);
            logActivity($user['id'], 'hotmart_purchase', "Plano: $planActivated | R\$$value");

            $feats = $planActivated === 'elite'
                ? '✅ 1.500 questões comentadas<br>✅ Simulados ilimitados<br>✅ Vade Mecum completo<br>✅ Chat IA ilimitado'
                : '✅ 1.000 questões comentadas<br>✅ Simulados cronometrados<br>✅ Chat IA';

            $html = emailTemplate(
                "🎖️ Plano $planLabel ativado!",
                "Olá, <strong>$userName</strong>!<br><br>
                Seu <strong>Plano $planLabel</strong> está ativo!<br><br>$feats<br><br>
                Faça login com seu e-mail e senha normalmente.",
                '⚔️ Acessar Plataforma →',
                SITE_URL . '/plataforma/'
            );
            sendEmail($buyerEmail, "🎖️ Plano $planLabel ativado — Plano de Guerra!", $html, $userName);

        } else {
            // Criar conta nova com link de primeiro acesso
            $tempPass = bin2hex(random_bytes(6));
            $db->prepare("INSERT INTO users (name,email,password,plan,hotmart_id,joined_at) VALUES (?,?,?,?,?,NOW())")
               ->execute([$buyerName, $buyerEmail, hashPassword($tempPass), $planActivated, $productId]);
            $newId = $db->lastInsertId();

            $resetToken = 'reset_' . bin2hex(random_bytes(16));
            $db->prepare("INSERT INTO sessions (user_id,token,expires_at) VALUES (?,?,?)")
               ->execute([$newId, $resetToken, date('Y-m-d H:i:s', strtotime('+7 days'))]);
            $resetUrl = SITE_URL . '/plataforma/?reset_token=' . $resetToken;

            $html = emailTemplate(
                "⚔️ Seu acesso ao Plano $planLabel está pronto!",
                "Olá, <strong>$buyerName</strong>!<br><br>
                Sua conta foi criada com <strong>Plano $planLabel</strong> ativo!<br><br>
                📧 Login: <strong>$buyerEmail</strong><br><br>
                Clique abaixo para criar sua senha:",
                '🔑 Criar Minha Senha →',
                $resetUrl
            );
            sendEmail($buyerEmail, "⚔️ Acesso ao Plano $planLabel — Plano de Guerra!", $html, $buyerName);
        }
    }

// ════════════════════════════════════════════════════
// REEMBOLSO
// ════════════════════════════════════════════════════
} elseif (in_array($event, ['PURCHASE_REFUNDED', 'PURCHASE_CHARGEBACK', 'PURCHASE_CANCELED'])) {
    if ($user) {
        if ($isRedacaoProduct) {
            $db->prepare("UPDATE users SET redacao_enabled=0 WHERE id=?")->execute([$user['id']]);
            logActivity($user['id'], 'redacao_refund', $event);
        } else {
            $old = $user['plan'];
            $db->prepare("UPDATE users SET plan='free' WHERE id=?")->execute([$user['id']]);
            $db->prepare("INSERT INTO plan_migrations (user_id,from_plan,to_plan,reason,done_at) VALUES (?,?,'free','Reembolso',NOW())")
               ->execute([$user['id'], $old]);
        }
        $html = emailTemplate(
            '↩️ Reembolso processado',
            "Olá, <strong>$userName</strong>!<br><br>
            Seu reembolso foi processado. Se teve algum problema, entre em contato:<br>
            📧 contato@uselegora.com.br",
            '↩️ Falar com Suporte',
            'mailto:contato@uselegora.com.br'
        );
        sendEmail($buyerEmail, '↩️ Reembolso — Plano de Guerra', $html, $userName);
    }
    $status = 'refunded';

// ════════════════════════════════════════════════════
// BOLETO EMITIDO
// ════════════════════════════════════════════════════
} elseif ($event === 'PURCHASE_BILLET_PRINTED') {
    $html = emailTemplate(
        '📄 Boleto emitido!',
        "Olá, <strong>$buyerName</strong>!<br><br>
        Seu boleto foi gerado. Vence em <strong>3 dias úteis</strong>.<br>
        Após o pagamento confirmado, seu acesso é liberado automaticamente!<br><br>
        💡 Prefere acesso imediato? Pague com cartão.",
        '💳 Pagar com Cartão →',
        $isRedacaoProduct ? '#' : ($planActivated === 'elite' ? HOTMART_ELITE : HOTMART_SOLDADO)
    );
    sendEmail($buyerEmail, '📄 Boleto emitido — Plano de Guerra', $html, $buyerName);
    $status = 'billet';
}

$db->prepare("UPDATE hotmart_transactions SET status=? WHERE id=?")->execute([$status, $txId]);
echo json_encode(['received' => true, 'event' => $event, 'status' => $status]);