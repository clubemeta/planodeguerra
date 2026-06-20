<?php
// ═══════════════════════════════════════════════
// fix_admin_pass.php
// EXECUTE UMA VEZ e depois DELETE este arquivo!
// Coloque em: public_html/plataforma/api/fix_admin_pass.php
// Acesse: https://uselegora.com.br/plataforma/api/fix_admin_pass.php
// ═══════════════════════════════════════════════

require_once __DIR__ . '/config.php';

try {
    $db = getDB();
    
    // Hash correto da senha guerra2026@admin
    $novaSenha = 'guerra2026@admin';
    $hash = password_hash($novaSenha, PASSWORD_BCRYPT);
    
    // Atualizar todos os admins
    $db->prepare("UPDATE users SET password = ? WHERE email IN ('admin@planodeguerra.com.br','admin@planodeguerra.com','clubemetaoficial@gmail.com')")
       ->execute([$hash]);
    
    $count = $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    
    echo "<h2 style='color:green'>✅ Senha atualizada com sucesso!</h2>";
    echo "<p>Hash gerado para <strong>guerra2026@admin</strong></p>";
    echo "<p>Admins atualizados: <strong>$count</strong></p>";
    echo "<p>Login: <strong>admin@planodeguerra.com.br</strong> / <strong>guerra2026@admin</strong></p>";
    echo "<br><strong style='color:red'>⚠️ DELETE ESTE ARQUIVO AGORA!</strong>";
    echo "<p>No gerenciador de arquivos da Hostinger, apague: <code>public_html/plataforma/api/fix_admin_pass.php</code></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ Erro: " . htmlspecialchars($e->getMessage()) . "</h2>";
    echo "<p>Verifique se o config.php tem as credenciais corretas do banco.</p>";
}
