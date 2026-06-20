<?php
// auth.php — public_html/plataforma/api/auth.php
require_once __DIR__ . '/config.php';
setHeaders();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'ping':
        echo json_encode(['ok'=>true,'time'=>time(),'version'=>'2.0']);
        exit;

    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        $email = strtolower(trim($data['email'] ?? ''));
        $pass  = $data['password'] ?? '';
        if (!$email || !$pass) jsonError('E-mail e senha obrigatórios.');
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email=? AND blocked=0 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !verifyPassword($pass, $user['password'])) jsonError('E-mail ou senha incorretos.', 401);
        $token = generateToken();
        $db->prepare("INSERT INTO sessions (user_id,token,expires_at) VALUES (?,?,?)")
           ->execute([$user['id'], $token, date('Y-m-d H:i:s', strtotime('+30 days'))]);
        $db->prepare("UPDATE users SET last_seen=NOW() WHERE id=?")->execute([$user['id']]);
        logActivity($user['id'], 'login', $email);
        jsonResponse(['token'=>$token,'user'=>['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email'],'plan'=>$user['plan'],'role'=>$user['role']]]);
        break;

    case 'register':
        $data  = json_decode(file_get_contents('php://input'), true);
        $name  = trim($data['name']  ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $pass  = $data['password'] ?? '';
        $plan  = in_array($data['plan']??'', ['free','soldado','elite']) ? $data['plan'] : 'free';
        $role  = in_array($data['role']??'', ['student','admin','colaborador','suporte']) ? $data['role'] : 'student';
        if (!$name || !$email || !$pass) jsonError('Nome, e-mail e senha obrigatórios.');
        if (strlen($pass) < 6) jsonError('Senha mínima: 6 caracteres.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('E-mail inválido.');
        $db = getDB();
        if ($db->query("SELECT COUNT(*) FROM users WHERE email='".addslashes($email)."'")->fetchColumn() > 0)
            jsonError('Este e-mail já está cadastrado.', 409);
        $db->prepare("INSERT INTO users (name,email,password,plan,role,joined_at) VALUES (?,?,?,?,?,NOW())")
           ->execute([$name, $email, hashPassword($pass), $plan, $role]);
        $userId = $db->lastInsertId();
        $token  = generateToken();
        $db->prepare("INSERT INTO sessions (user_id,token,expires_at) VALUES (?,?,?)")
           ->execute([$userId, $token, date('Y-m-d H:i:s', strtotime('+30 days'))]);
        logActivity($userId, 'register', $email);
        // E-mail de boas-vindas
        $html = emailTemplate('Bem-vindo ao Plano de Guerra! ⚔️',
            "Olá, <strong>$name</strong>!<br><br>Sua conta foi criada. Plano: <strong>".strtoupper($plan)."</strong>.<br>Bons estudos para o GCM Fortaleza 2026!",
            'Acessar Plataforma →', SITE_URL.'/plataforma/');
        sendEmail($email, '⚔️ Bem-vindo ao Plano de Guerra!', $html, $name);
        jsonResponse(['token'=>$token,'user'=>['id'=>$userId,'name'=>$name,'email'=>$email,'plan'=>$plan,'role'=>$role]]);
        break;

    case 'logout':
        $token = getToken();
        if ($token) try { getDB()->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]); } catch(Exception $e){}
        jsonResponse(['ok'=>true]);
        break;

    case 'me':
        $user = verifyToken(getToken());
        if (!$user) jsonError('Sessão inválida.', 401);
        getDB()->prepare("UPDATE users SET last_seen=NOW() WHERE id=?")->execute([$user['id']]);
        jsonResponse(['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email'],'plan'=>$user['plan'],'role'=>$user['role']]);
        break;

    case 'forgot_password':
        $data  = json_decode(file_get_contents('php://input'), true);
        $email = strtolower(trim($data['email'] ?? ''));
        if (!$email) jsonError('E-mail obrigatório.');
        $db   = getDB();
        $stmt = $db->prepare("SELECT id,name FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $resetToken = 'reset_'.bin2hex(random_bytes(16));
            $db->prepare("INSERT INTO sessions (user_id,token,expires_at) VALUES (?,?,?)")
               ->execute([$user['id'], $resetToken, date('Y-m-d H:i:s', strtotime('+1 hour'))]);
            $resetUrl = SITE_URL.'/plataforma/?reset_token='.$resetToken;
            $html = emailTemplate('🔑 Redefinição de senha',
                "Olá, <strong>{$user['name']}</strong>!<br><br>Clique abaixo para redefinir sua senha. Link válido por 1 hora.",
                'Redefinir Minha Senha →', $resetUrl);
            sendEmail($email, '🔑 Redefinição de senha — Plano de Guerra', $html, $user['name']);
            jsonResponse(['ok'=>true,'name'=>$user['name'],'has_account'=>true]);
        } else {
            jsonResponse(['ok'=>true,'has_account'=>false]);
        }
        break;

    case 'reset_password':
        $data  = json_decode(file_get_contents('php://input'), true);
        $email = strtolower(trim($data['email'] ?? ''));
        $pass  = $data['password'] ?? '';
        $token = $data['token'] ?? '';
        if (!$pass || strlen($pass) < 6) jsonError('Senha mínima: 6 caracteres.');
        $db = getDB();
        // Buscar usuário pelo e-mail diretamente (fluxo simplificado sem token de link)
        $stmt = $db->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        // Ou pelo token de reset (vindo do link do e-mail)
        if (!$user && $token && strpos($token,'reset_')===0) {
            $stmt = $db->prepare("SELECT u.* FROM users u JOIN sessions s ON s.user_id=u.id WHERE s.token=? AND s.expires_at>NOW() LIMIT 1");
            $stmt->execute([$token]);
            $user = $stmt->fetch();
        }
        if (!$user) jsonError('Usuário não encontrado.', 404);
        $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([hashPassword($pass), $user['id']]);
        if ($token) $db->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);
        logActivity($user['id'], 'reset_password', $user['email']);
        jsonResponse(['ok'=>true,'name'=>$user['name']]);
        break;

    default:
        jsonError("Ação '$action' não encontrada.", 404);
}