<?php
require_once __DIR__ . '/config.php';
setHeaders();

$action = $_GET['action'] ?? 'list';

const PLAN_LIMITS = ['free' => 75, 'soldado' => 1000, 'elite' => 1500];

function loadBestFile() {
    static $data = null;
    if ($data !== null) return $data;

    $paths = [
        __DIR__ . '/questoes_1500_completas.json',
        __DIR__ . '/questoes.json',
        __DIR__ . '/questoes2.json',
        __DIR__ . '/questoes_todos_lotes.json',
        __DIR__ . '/questoes_novos_lotes.json',
        __DIR__ . '/questoes_lotes3_4.json',
    ];

    $best = []; $bestCount = 0;
    foreach ($paths as $path) {
        if (!file_exists($path)) continue;
        $raw = json_decode(file_get_contents($path), true);
        if (!is_array($raw) || count($raw) <= $bestCount) continue;
        $best = $raw; $bestCount = count($raw);
    }

    $data = array_values(array_filter(array_map(function($q) {
        $r = $q['resposta'] ?? ($q['answer'] ?? 'C');
        if ($r === true  || in_array($r, [1,'1','true'], true))  $r = 'C';
        if ($r === false || in_array($r, [0,'0','false'], true)) $r = 'E';
        $r = strtoupper(trim((string)$r));
        if (!in_array($r, ['C','E'])) $r = 'C';
        return [
            'id'         => (int)($q['id'] ?? 0),
            'disciplina' => trim($q['disciplina'] ?? 'Geral'),
            'assunto'    => trim($q['assunto'] ?? ''),
            'enunciado'  => trim($q['enunciado'] ?? $q['question'] ?? ''),
            'resposta'   => $r,
            'comentario' => $q['explicacao'] ?? $q['comentario_pegadinha'] ?? $q['comentario'] ?? '',
        ];
    }, $best), fn($q) => $q['id'] > 0 && !empty($q['enunciado'])));

    usort($data, fn($a,$b) => $a['id'] - $b['id']);
    return $data;
}

switch ($action) {

    // ── PÚBLICO (sem autenticação) ─────────────────────────
    // Serve questões pelo plano passado como parâmetro
    // Menos seguro, mas garante que SEMPRE aparecem questões
    case 'public':
        $plan  = in_array($_GET['plan']??'', ['free','soldado','elite'])
               ? $_GET['plan'] : 'free';
        $limit = PLAN_LIMITS[$plan];
        $all   = loadBestFile();

        // Tentar validar com token se disponível
        $token = getToken();
        if ($token) {
            $user = verifyToken($token);
            if ($user) {
                $plan  = $user['plan'];
                $limit = PLAN_LIMITS[$plan] ?? 50;
            }
        }

        if (empty($all)) {
            jsonError('Arquivo de questões não encontrado na pasta api/. Suba o questoes.json!', 404);
        }

        $questions = array_slice($all, 0, $limit);

        jsonResponse([
            'questions' => $questions,
            'total'     => count($questions),
            'total_db'  => count($all),
            'plan'      => $plan,
            'limit'     => $limit,
        ]);
        break;

    // ── AUTENTICADO (com token DB) ─────────────────────────
    case 'list':
        // verifyToken() retorna null se inválido (não faz exit como requireAuth)
        $plan  = 'free';
        $limit = 50;
        $user  = verifyToken(getToken());
        if ($user) {
            $plan  = $user['plan'] ?? 'free';
            $limit = PLAN_LIMITS[$plan] ?? 50;
        }

        $all = loadBestFile();
        if (empty($all)) {
            jsonError('questoes.json não encontrado. Suba o arquivo na pasta api/.', 404);
        }

        $questions = array_slice($all, 0, $limit);

        $disc = $_GET['disciplina'] ?? '';
        $ass  = $_GET['assunto']    ?? '';
        if ($disc) $questions = array_values(array_filter($questions, fn($q) => stripos($q['disciplina'],$disc)!==false));
        if ($ass)  $questions = array_values(array_filter($questions, fn($q) => stripos($q['assunto'],$ass)!==false));

        jsonResponse([
            'questions' => $questions,
            'total'     => count($questions),
            'total_db'  => count($all),
            'plan'      => $plan,
            'limit'     => $limit,
        ]);
        break;

    // ── TOTAL POR PLANO (público) ──────────────────────────
    case 'counts':
        $all = loadBestFile();
        $total = count($all);
        jsonResponse([
            'total'   => $total,
            'free'    => min(75,   $total),
            'soldado' => min(1000, $total),
            'elite'   => $total,
        ]);
        break;

    // ── DISCIPLINAS DO PLANO ───────────────────────────────
    case 'disciplines':
        $plan = 'free';
        $user = verifyToken(getToken()); if ($user) $plan = $user['plan'] ?? 'free';
        $plan = in_array($_GET['plan']??'', ['free','soldado','elite']) ? $_GET['plan'] : $plan;
        $all  = loadBestFile();
        $subset = array_slice($all, 0, PLAN_LIMITS[$plan] ?? 50);
        $discs  = array_unique(array_column($subset, 'disciplina'));
        sort($discs);
        jsonResponse(['disciplines' => array_values($discs)]);
        break;

    // ── DIAGNÓSTICO ───────────────────────────────────────
    case 'diagnose':
        $files = [];
        foreach (['questoes_1500_completas.json','questoes.json','questoes2.json',
                  'questoes_todos_lotes.json','questoes_novos_lotes.json','questoes_lotes3_4.json'] as $name) {
            $path = __DIR__ . '/' . $name;
            if (!file_exists($path)) continue;
            $raw   = json_decode(file_get_contents($path), true);
            $files[] = ['file'=>$name,'size'=>round(filesize($path)/1024,1).' KiB','count'=>is_array($raw)?count($raw):0,'valid'=>is_array($raw)];
        }
        $all = loadBestFile();
        jsonResponse(['files'=>$files,'loaded'=>count($all),'free'=>min(75,count($all)),'soldado'=>min(1000,count($all)),'elite'=>count($all),'status'=>count($all)>0?'OK':'SEM QUESTÕES']);
        break;

    // ── STATS ADMIN ──────────────────────────────────────
    case 'stats':
        try { requireAdmin(); } catch(Exception $e) { jsonError('Acesso negado.', 403); }
        $all  = loadBestFile();
        $discs = [];
        foreach ($all as $q) $discs[$q['disciplina']] = ($discs[$q['disciplina']] ?? 0) + 1;
        jsonResponse(['total'=>count($all),'disciplines'=>$discs,'free'=>min(75,count($all)),'soldado'=>min(1000,count($all)),'elite'=>count($all)]);
        break;

    default:
        jsonError('Ação não encontrada.', 404);
}