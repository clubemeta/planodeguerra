<?php
// ════════════════════════════════════════════════════
// CONFIGURAÇÃO — Plano de Guerra GCM Fortaleza 2026
// Arquivo: public_html/plataforma/api/config.php
// ════════════════════════════════════════════════════

// ── BANCO DE DADOS ───────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'u538714447_brumadinho');
define('DB_USER',    'u538714447_brumadinho');
define('DB_PASS',    'Sempredeus10@_');
define('DB_CHARSET', 'utf8mb4');

// ── SITE ─────────────────────────────────────────────
define('SITE_URL',    'https://uselegora.com.br');
define('ADMIN_EMAIL', 'admin@planodeguerra.com.br');
define('ADMIN_PASS',  'guerra2026@admin');

// ── HOTMART ──────────────────────────────────────────
define('HOTMART_SECRET',    'AEMVPkjyQITwGEM1RKmHAS7CkppMc3202074');
define('HOTMART_CLIENT_ID', '971634f4-8248-476a-ae09-e1d4bf53aa55');
define('HOTMART_SECRET_KEY','8b742680-3dc2-4b4d-89b6-510d93ed5dba');
define('HOTMART_SOLDADO',   'https://pay.hotmart.com/W105716827B?off=rw2e5oeq');
define('HOTMART_ELITE',     'https://pay.hotmart.com/W105716827B?off=d9a8k894');

// ── E-MAIL SMTP (Hostinger) ───────────────────────────
//
// COMO CONFIGURAR:
// 1. hPanel → E-mails → Contas de E-mail → Criar conta
// 2. E-mail: contato@uselegora.com.br
// 3. Crie uma senha forte e cole em SMTP_PASS abaixo
// 4. Mude SMTP_ENABLED de false para true
//
define('SMTP_HOST',     'smtp.hostinger.com');
define('SMTP_PORT',     465);
define('SMTP_USER',     'contato@uselegora.com.br');
define('SMTP_PASS',     'PlanoGuerra2026!');  // ← senha atualizada
define('SMTP_FROM',     'Plano de Guerra <contato@uselegora.com.br>');
define('SMTP_ENABLED',  true);    // ← ATIVO

// ════════════════════════════════════════════════════
// FUNÇÕES DO SISTEMA — não altere abaixo desta linha
// ════════════════════════════════════════════════════

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[PlanodeGuerra] DB Error: ' . $e->getMessage());
            jsonError('Erro de conexão com o banco. Contate o suporte.', 500);
        }
    }
    return $pdo;
}

function setHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function getToken() {
    $h = getallheaders();
    $a = $h['Authorization'] ?? $h['authorization'] ?? '';
    if (strpos($a, 'Bearer ') === 0) return substr($a, 7);
    return $_GET['token'] ?? null;
}

function verifyToken($token) {
    if (!$token) return null;
    if (strpos($token, 'reset_') === 0) return null;
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT u.* FROM users u
            JOIN sessions s ON u.id = s.user_id
            WHERE s.token = ?
              AND s.expires_at > NOW()
              AND u.blocked = 0
        ");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function requireAuth() {
    $user = verifyToken(getToken());
    if (!$user) jsonError('Não autorizado. Faça login novamente.', 401);
    return $user;
}

function requireAdmin() {
    $user = requireAuth();
    if ($user['role'] !== 'admin') jsonError('Acesso negado.', 403);
    return $user;
}

function generateToken()        { return bin2hex(random_bytes(32)); }
function hashPassword($p)       { return password_hash($p, PASSWORD_BCRYPT); }
function verifyPassword($p, $h) { return password_verify($p, $h); }

function logActivity($userId, $action, $detail = '') {
    try {
        $db = getDB();
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $db->prepare("
            INSERT INTO activity_log (user_id, action, detail, ip, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$userId, $action, $detail, $ip]);
    } catch (Exception $e) {}
}

// ── ENVIO DE E-MAIL ──────────────────────────────────
function sendEmail($to, $subject, $htmlBody, $toName = '') {
    // Log da tentativa
    try {
        getDB()->prepare("
            INSERT INTO email_log (to_email, subject, status, sent_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([$to, $subject, SMTP_ENABLED ? 'sending' : 'mail()']);
    } catch (Exception $e) {}

    // Se SMTP desativado, usa PHP mail() nativo da Hostinger
    if (!SMTP_ENABLED) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: " . SMTP_FROM . "\r\n";
        $headers .= "Reply-To: " . SMTP_USER . "\r\n";
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
    }

    // SMTP SSL porta 465
    try {
        $smtp = @fsockopen('ssl://' . SMTP_HOST, SMTP_PORT, $errno, $errstr, 15);
        if (!$smtp) {
            error_log("[Email] Falha SMTP: $errstr ($errno)");
            return false;
        }

        $read  = function () use ($smtp) {
            $buf = '';
            while ($line = fgets($smtp, 515)) {
                $buf .= $line;
                if ($line[3] === ' ') break;
            }
            return $buf;
        };
        $write = function ($cmd) use ($smtp) { fwrite($smtp, $cmd . "\r\n"); };

        $read();
        $write('EHLO ' . parse_url(SITE_URL, PHP_URL_HOST));
        $read();
        $write('AUTH LOGIN');
        $read();
        $write(base64_encode(SMTP_USER));
        $read();
        $write(base64_encode(SMTP_PASS));
        $resp = $read();

        if (strpos($resp, '235') === false) {
            error_log("[Email] Autenticação SMTP falhou: $resp");
            fclose($smtp);
            return false;
        }

        $write('MAIL FROM: <' . SMTP_USER . '>');
        $read();
        $write('RCPT TO: <' . $to . '>');
        $read();
        $write('DATA');
        $read();

        $toLine  = $toName ? "$toName <$to>" : $to;
        $message  = "To: $toLine\r\n";
        $message .= "From: " . SMTP_FROM . "\r\n";
        $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "\r\n";
        $message .= chunk_split(base64_encode($htmlBody));
        $message .= "\r\n.\r\n";

        $write($message);
        $resp = $read();
        $write('QUIT');
        fclose($smtp);

        $ok = strpos($resp, '250') !== false;
        if (!$ok) error_log("[Email] Envio falhou: $resp");
        return $ok;

    } catch (Exception $e) {
        error_log("[Email] Exceção: " . $e->getMessage());
        return false;
    }
}

// ── TEMPLATE DE E-MAIL ───────────────────────────────
function emailTemplate($title, $content, $btnText = '', $btnUrl = '') {
    $btn = $btnText
        ? "<div style='text-align:center;margin:28px 0'>
             <a href='$btnUrl'
                style='background:#D4AF37;color:#000;padding:14px 32px;border-radius:8px;
                       text-decoration:none;font-weight:700;font-size:15px;
                       font-family:Arial,sans-serif;display:inline-block'>
               $btnText
             </a>
           </div>"
        : '';

    return "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width,initial-scale=1'>
  <title>$title</title>
</head>
<body style='margin:0;padding:0;background:#080D1A;font-family:Arial,Helvetica,sans-serif'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#080D1A;padding:40px 0'>
    <tr><td align='center'>
      <table width='560' cellpadding='0' cellspacing='0' style='max-width:560px;width:100%'>

        <!-- Cabeçalho -->
        <tr><td align='center' style='padding-bottom:24px'>
          <div style='font-size:40px;margin-bottom:8px'>⚔️</div>
          <div style='color:#D4AF37;font-size:20px;font-weight:700;letter-spacing:4px;
                      text-transform:uppercase;font-family:Arial,sans-serif'>
            PLANO DE GUERRA
          </div>
          <div style='color:#5A5448;font-size:11px;letter-spacing:3px;
                      text-transform:uppercase;margin-top:4px'>
            GCM FORTALEZA 2026
          </div>
        </td></tr>

        <!-- Conteúdo -->
        <tr><td style='background:#111827;border:1px solid rgba(212,175,55,0.2);
                       border-radius:12px;padding:32px'>
          <h2 style='color:#D4AF37;margin:0 0 16px;font-size:20px;font-family:Arial,sans-serif'>
            $title
          </h2>
          <div style='color:#C8C2B0;font-size:14px;line-height:1.8;font-family:Arial,sans-serif'>
            $content
          </div>
          $btn
        </td></tr>

        <!-- Rodapé -->
        <tr><td align='center' style='padding-top:20px'>
          <p style='color:#3A3428;font-size:11px;margin:0;font-family:Arial,sans-serif'>
            &copy; " . date('Y') . " Plano de Guerra Concursos<br>
            <a href='" . SITE_URL . "'
               style='color:#5A5448;text-decoration:none'>
              uselegora.com.br
            </a>
          </p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>";
}