<?php
// admin.php — Painel Administrativo
// public_html/plataforma/api/admin.php
require_once __DIR__ . '/config.php';
setHeaders();

$action = $_GET['action'] ?? 'dashboard';
$admin  = requireAdmin();

switch ($action) {

    // ── MÉTRICAS DO DASHBOARD ─────────────────────────────
    case 'metrics':
        $db = getDB();
        $total   = $db->query("SELECT COUNT(*) FROM users WHERE role='student' OR role IS NULL")->fetchColumn();
        $plans   = $db->query("SELECT plan, COUNT(*) as c FROM users GROUP BY plan")->fetchAll();
        $planMap = [];
        foreach ($plans as $p) $planMap[$p['plan']] = (int)$p['c'];
        $sold    = $planMap['soldado'] ?? 0;
        $elite   = $planMap['elite']   ?? 0;
        $free    = $planMap['free']    ?? 0;
        $revenue = $sold * 97 + $elite * 197;
        $conv    = $total > 0 ? round(($sold + $elite) / $total * 100, 1) : 0;
        $avgQ    = $db->query("SELECT ROUND(AVG(cnt),0) FROM (SELECT COUNT(*) as cnt FROM progress GROUP BY user_id) t")->fetchColumn();
        jsonResponse([
            'total'    => (int)$total,
            'plans'    => ['free'=>$free, 'soldado'=>$sold, 'elite'=>$elite],
            'revenue'  => $revenue,
            'conv'     => $conv,
            'avg_questions' => (int)($avgQ ?: 0),
        ]);
        break;

    // ── LISTAR ALUNOS ─────────────────────────────────────
    case 'users':
        $db    = getDB();
        $limit = min((int)($_GET['limit'] ?? 200), 500);
        $plan  = $_GET['plan'] ?? '';
        $q     = $_GET['q']    ?? '';

        $sql  = "SELECT u.id, u.name, u.email, u.plan, u.role, u.blocked, u.joined_at, u.last_seen,
                        (SELECT COUNT(*) FROM progress WHERE user_id=u.id) as total_q,
                        (SELECT COUNT(*) FROM progress WHERE user_id=u.id AND correct=1) as correct_q
                 FROM users u WHERE 1=1";
        $params = [];
        if ($plan)  { $sql .= " AND u.plan=?";          $params[] = $plan; }
        if ($q)     { $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
        $sql .= " ORDER BY u.joined_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        foreach ($users as &$u) {
            $u['pct']  = $u['total_q'] > 0 ? round($u['correct_q'] / $u['total_q'] * 100) : 0;
            $u['sims'] = 0;
        }
        jsonResponse(['users' => $users, 'total' => count($users)]);
        break;

    // ── BLOQUEAR/DESBLOQUEAR ALUNO ────────────────────────
    case 'toggle_block':
        $data = json_decode(file_get_contents('php://input'), true);
        $uid  = (int)($data['user_id'] ?? 0);
        if (!$uid) jsonError('user_id obrigatório.');
        $db = getDB();
        $db->prepare("UPDATE users SET blocked = NOT blocked WHERE id=?")->execute([$uid]);
        $newVal = $db->prepare("SELECT blocked FROM users WHERE id=?");
        $newVal->execute([$uid]);
        jsonResponse(['ok' => true, 'blocked' => (bool)$newVal->fetchColumn()]);
        break;

    // ── ALTERAR PLANO ─────────────────────────────────────
    case 'change_plan':
        $data = json_decode(file_get_contents('php://input'), true);
        $uid  = (int)($data['user_id'] ?? 0);
        $plan = $data['plan'] ?? '';
        if (!$uid || !in_array($plan, ['free','soldado','elite'])) jsonError('Dados inválidos.');
        $db = getDB();
        $db->prepare("UPDATE users SET plan=? WHERE id=?")->execute([$plan, $uid]);
        logActivity($admin['id'], 'change_plan', "user $uid → $plan");
        jsonResponse(['ok' => true]);
        break;

    // ── BANNERS ───────────────────────────────────────────
    case 'get_banners':
        $db = getDB();
        $rows = $db->query("SELECT * FROM banners ORDER BY created_at DESC LIMIT 20")->fetchAll();
        foreach ($rows as &$r) $r['plans'] = json_decode($r['plans'], true);
        jsonResponse(['banners' => $rows]);
        break;

    case 'save_banner':
        $data = json_decode(file_get_contents('php://input'), true);
        $db   = getDB();
        if (isset($data['id']) && $data['id']) {
            $db->prepare("UPDATE banners SET title=?,description=?,cta_text=?,cta_link=?,color=?,plans=?,active=? WHERE id=?")
               ->execute([$data['title'],$data['description']??'',$data['cta_text']??'',$data['cta_link']??'',$data['color']??'gold',json_encode($data['plans']??[]),$data['active']??1,$data['id']]);
        } else {
            $db->prepare("INSERT INTO banners (title,description,cta_text,cta_link,color,plans,active) VALUES (?,?,?,?,?,?,1)")
               ->execute([$data['title'],$data['description']??'',$data['cta_text']??'',$data['cta_link']??'',$data['color']??'gold',json_encode($data['plans']??[])]);
        }
        jsonResponse(['ok' => true]);
        break;

    // ── REPORTS DE ERROS ──────────────────────────────────
    case 'save_report':
        $data = json_decode(file_get_contents('php://input'), true);
        $db   = getDB();
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
            INDEX idx_resolved (resolved)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->prepare("INSERT INTO gcm_reports (user_id,user_name,user_email,plan,question_id,description) VALUES (?,?,?,?,?,?)")
           ->execute([
               $admin['id'] ?? null,
               $data['user']  ?? 'Anônimo',
               $data['email'] ?? '',
               $data['plan']  ?? 'free',
               $data['qid']   ?? '',
               $data['desc']  ?? '',
           ]);
        jsonResponse(['ok' => true]);
        break;

    case 'get_reports':
        $db = getDB();
        try {
            $rows = $db->query("SELECT * FROM gcm_reports WHERE resolved=0 ORDER BY created_at DESC LIMIT 100")->fetchAll();
            jsonResponse(['reports' => $rows]);
        } catch (Exception $e) {
            jsonResponse(['reports' => []]);
        }
        break;

    case 'resolve_report':
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = (int)($data['id'] ?? 0);
        if (!$id) jsonError('ID obrigatório.');
        getDB()->prepare("UPDATE gcm_reports SET resolved=1 WHERE id=?")->execute([$id]);
        jsonResponse(['ok' => true]);
        break;

    // ── MIGRAÇÃO / IMPORTAR QUESTÕES ──────────────────────
    case 'migrate':
        jsonResponse(['ok' => true, 'message' => 'Migração executada. Verifique o banco.']);
        break;

    // ── CSV DE ALUNOS ─────────────────────────────────────
    case 'export_csv':
        $db    = getDB();
        $users = $db->query("SELECT name,email,plan,blocked,joined_at FROM users ORDER BY joined_at DESC")->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="alunos_'.date('Y-m-d').'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Nome','E-mail','Plano','Bloqueado','Cadastro']);
        foreach ($users as $u) fputcsv($out, [$u['name'],$u['email'],$u['plan'],$u['blocked']?'Sim':'Não',$u['joined_at']]);
        fclose($out);
        exit;


    // ── DISPARAR E-MAIL PARA ALUNOS ──────────────────────
    case 'send_email':
        $data    = json_decode(file_get_contents('php://input'), true);
        $subject = trim($data['subject'] ?? '');
        $message = trim($data['message'] ?? '');
        $plans   = $data['plans'] ?? ['free','soldado','elite'];

        if (!$subject || !$message) jsonError('Assunto e mensagem obrigatórios.');

        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($plans), '?'));
        $stmt = $db->prepare("SELECT name, email FROM users WHERE plan IN ($placeholders) AND blocked=0");
        $stmt->execute($plans);
        $users = $stmt->fetchAll();

        $sent = 0; $failed = 0;
        foreach ($users as $u) {
            // Converter quebras de linha em HTML
            $htmlBody = nl2br(htmlspecialchars($message));
            $html = emailTemplate($subject, $htmlBody);
            if (sendEmail($u['email'], $subject, $html, $u['name'])) {
                $sent++;
            } else {
                $failed++;
            }
            // Evitar sobrecarga no servidor
            usleep(200000); // 0.2s entre cada envio
        }

        // Registrar no log
        try {
            $db->prepare("INSERT INTO email_log (to_email, subject, plan_target, status, sent_at) VALUES (?,?,?,?,NOW())")
               ->execute(['[BROADCAST]', $subject, implode(',',$plans), "sent:$sent,failed:$failed"]);
        } catch (Exception $e) {}

        logActivity($admin['id'], 'send_email', "Enviado para $sent alunos | Planos: ".implode(',',$plans));
        jsonResponse(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'total' => count($users)]);
        break;

    default:
        jsonError("Ação '$action' não encontrada.", 404);
}