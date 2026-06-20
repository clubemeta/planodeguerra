<?php
require_once __DIR__ . '/config.php';
setHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$user   = requireAuth();

switch ($action) {

    // ── SALVAR RESPOSTA ───────────────────────────
    case 'answer':
        if ($method !== 'POST') jsonError('Método inválido.');
        $qid     = (int)($body['question_id'] ?? 0);
        $answer  = strtoupper(trim($body['answer'] ?? ''));
        $correct = (int)($body['correct'] ?? 0);

        if (!$qid || !in_array($answer, ['C','E'])) jsonError('Dados inválidos.');

        $db = getDB();
        // UPSERT — atualiza se já respondeu antes
        $stmt = $db->prepare("
            INSERT INTO progress (user_id, question_id, answer, correct, answered_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE answer = VALUES(answer), correct = VALUES(correct), answered_at = NOW()
        ");
        $stmt->execute([$user['id'], $qid, $answer, $correct]);

        jsonResponse(['ok' => true]);
        break;

    // ── SALVAR VÁRIAS RESPOSTAS (batch) ───────────
    case 'answer_batch':
        if ($method !== 'POST') jsonError('Método inválido.');
        $answers = $body['answers'] ?? [];
        if (!is_array($answers) || empty($answers)) jsonError('Sem respostas.');

        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO progress (user_id, question_id, answer, correct, answered_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE answer = VALUES(answer), correct = VALUES(correct), answered_at = NOW()
        ");
        foreach ($answers as $a) {
            $qid    = (int)($a['question_id'] ?? 0);
            $answer = strtoupper(trim($a['answer'] ?? ''));
            $correct= (int)($a['correct'] ?? 0);
            if ($qid && in_array($answer, ['C','E'])) {
                $stmt->execute([$user['id'], $qid, $answer, $correct]);
            }
        }
        jsonResponse(['ok' => true, 'saved' => count($answers)]);
        break;

    // ── BUSCAR PROGRESSO ──────────────────────────
    case 'get':
        $db = getDB();
        $stmt = $db->prepare("SELECT question_id, answer, correct, answered_at FROM progress WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();

        // Montar objeto {qid: {answer, correct}}
        $progress = [];
        foreach ($rows as $r) {
            $progress[$r['question_id']] = [
                'answer'  => $r['answer'],
                'correct' => (bool)$r['correct'],
            ];
        }

        jsonResponse(['progress' => $progress, 'total' => count($rows)]);
        break;

    // ── ESTATÍSTICAS RESUMIDAS ─────────────────────
    case 'stats':
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(correct) as correct,
                COUNT(*) - SUM(correct) as wrong
            FROM progress WHERE user_id = ?
        ");
        $stmt->execute([$user['id']]);
        $stats = $stmt->fetch();

        // Simulados
        $simStmt = $db->prepare("SELECT score, correct, total, time_sec, disciplina, done_at FROM simulations WHERE user_id = ? ORDER BY done_at DESC LIMIT 20");
        $simStmt->execute([$user['id']]);
        $sims = $simStmt->fetchAll();

        $pct = $stats['total'] > 0 ? round($stats['correct'] / $stats['total'] * 100) : 0;

        jsonResponse([
            'total'   => (int)$stats['total'],
            'correct' => (int)$stats['correct'],
            'wrong'   => (int)$stats['wrong'],
            'pct'     => $pct,
            'sims'    => $sims,
        ]);
        break;

    // ── SALVAR SIMULADO ───────────────────────────
    case 'save_simulation':
        if ($method !== 'POST') jsonError('Método inválido.');
        $score     = (int)($body['score'] ?? 0);
        $correct   = (int)($body['correct'] ?? 0);
        $total     = (int)($body['total'] ?? 0);
        $time_sec  = (int)($body['time_sec'] ?? 0);
        $disciplina= $body['disciplina'] ?? null;
        $answers   = $body['answers'] ?? null;

        if (!$total) jsonError('Total de questões inválido.');

        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO simulations (user_id, score, correct, total, time_sec, disciplina, answers, done_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user['id'], $score, $correct, $total, $time_sec, $disciplina, json_encode($answers)]);

        // Salvar respostas individuais também
        if (is_array($answers)) {
            $pStmt = $db->prepare("
                INSERT INTO progress (user_id, question_id, answer, correct, answered_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE answer = VALUES(answer), correct = VALUES(correct), answered_at = NOW()
            ");
            foreach ($answers as $qid => $a) {
                if (is_array($a) && isset($a['answer'])) {
                    $pStmt->execute([$user['id'], $qid, $a['answer'], (int)($a['correct'] ?? 0)]);
                }
            }
        }

        jsonResponse(['ok' => true, 'sim_id' => $db->lastInsertId()]);
        break;

    // ── RESETAR PROGRESSO ─────────────────────────
    case 'reset':
        if ($method !== 'POST') jsonError('Método inválido.');
        $db = getDB();
        $db->prepare("DELETE FROM progress WHERE user_id = ?")->execute([$user['id']]);
        $db->prepare("DELETE FROM simulations WHERE user_id = ?")->execute([$user['id']]);
        logActivity($user['id'], 'reset_progress');
        jsonResponse(['ok' => true]);
        break;

    default:
        jsonError('Ação não encontrada.', 404);
}
