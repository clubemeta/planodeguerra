<?php
require_once __DIR__ . '/config.php';
setHeaders();

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {

    // ── RANKING ──────────────────────────────────
    case 'ranking':
        $by = in_array($_GET['by']??'', ['pct','total','sims']) ? $_GET['by'] : 'pct';
        $orderBy = match($by) {
            'total' => 'total_q DESC',
            'sims'  => 'sims DESC',
            default => 'pct DESC, total_q DESC',
        };
        $users = $db->query("
            SELECT u.id, u.name, u.plan,
                   COUNT(p.id) as total_q,
                   SUM(p.correct) as correct_q,
                   (SELECT COUNT(*) FROM simulations s WHERE s.user_id = u.id) as sims
            FROM users u
            LEFT JOIN progress p ON p.user_id = u.id
            WHERE u.role = 'student' AND u.blocked = 0
            GROUP BY u.id
            ORDER BY $orderBy
            LIMIT 50
        ")->fetchAll();

        foreach ($users as &$u) {
            $u['pct'] = $u['total_q'] > 0 ? round($u['correct_q']/$u['total_q']*100) : 0;
            $u['total_q'] = (int)$u['total_q'];
            $u['sims'] = (int)$u['sims'];
        }
        jsonResponse(['ranking' => $users]);
        break;

    // ── BANNERS ATIVOS (para alunos) ─────────────
    case 'banners':
        $plan = $_GET['plan'] ?? 'free';
        $banners = $db->query("SELECT id, title, description, cta_text, cta_link, color, type, plans FROM banners WHERE active = 1")->fetchAll();
        $visible = [];
        foreach ($banners as $b) {
            $plans = json_decode($b['plans'], true);
            if (!empty($plans[$plan])) {
                $b['plans'] = $plans;
                $visible[] = $b;
            }
        }
        jsonResponse(['banners' => $visible]);
        break;

    default:
        jsonError('Ação não encontrada.', 404);
}
