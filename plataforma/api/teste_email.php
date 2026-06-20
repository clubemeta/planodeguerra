<?php
// test_email.php — Testar SMTP
// REMOVER após testar! Acesse: /plataforma/api/test_email.php?admin=1
require_once __DIR__ . '/config.php';

if($_GET['admin']!=='1'){ die('Acesso negado.'); }

$to    = $_GET['to'] ?? 'contato@uselegora.com.br';
$html  = emailTemplate(
    '✅ Teste de E-mail — Plano de Guerra',
    'Este é um e-mail de teste enviado pelo sistema.<br><br>
    Se você recebeu este e-mail, o SMTP está funcionando corretamente!<br><br>
    <b>Configurações:</b><br>
    Host: ' . SMTP_HOST . '<br>
    Porta: ' . SMTP_PORT . '<br>
    From: ' . SMTP_FROM,
    'Acessar Plataforma →',
    SITE_URL . '/plataforma/'
);

$ok = sendEmail($to, '✅ Teste SMTP — Plano de Guerra', $html, 'Admin');

echo json_encode([
    'ok'   => $ok,
    'to'   => $to,
    'smtp' => SMTP_HOST . ':' . SMTP_PORT,
    'from' => SMTP_FROM,
    'msg'  => $ok ? 'E-mail enviado com sucesso!' : 'Falha ao enviar. Verifique o SMTP.'
]);