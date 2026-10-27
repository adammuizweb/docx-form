<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/common.php';
$actorId = dxf_admin_guard($pdo, DXF_PERMISSION_SETTINGS);
dxf_admin_require_post();
try {
    $bounds = [
        'otp_expiry_minutes'=>[5,30],'otp_max_attempts'=>[3,10],'otp_resend_cooldown_seconds'=>[30,3600],
        'rate_window_seconds'=>[60,86400],'rate_max_requests'=>[1,100],'receipt_expiry_hours'=>[1,8760],
        'otp_retention_days'=>[1,3650],'pending_retention_days'=>[1,3650],'rejected_retention_days'=>[1,3650],'approved_retention_days'=>[1,3650],'output_retention_days'=>[1,3650],
        'event_retention_days'=>[7,3650],'staging_retention_hours'=>[1,168],
    ];
    $next = [];
    foreach ($bounds as $name => [$minimum,$maximum]) {
        $next[$name] = dxf_bounded_int($_POST[$name] ?? 0, $minimum, $maximum, 0);
        if ($next[$name] === 0) throw new DomainException(dxf_t('Enter valid bounded settings.'));
    }
    if (dxf_token($_POST['expected_hash'] ?? '') === null) throw new DomainException(dxf_t('Enter valid bounded settings.'));
    $keys = array_map(static fn(string $name): string => 'dxf_'.$name, array_keys(dxf_settings_defaults()));
    $pdo->beginTransaction();
    dxf_admin_reauthorize($pdo, $actorId, DXF_PERMISSION_SETTINGS);
    foreach ($keys as $key) $pdo->prepare('INSERT INTO settings (`key`,`value`,`autoload`) VALUES (?,NULL,1) ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)')->execute([$key]);
    $lock = $pdo->prepare('SELECT `key`,`value` FROM settings WHERE `key` IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY `key` FOR UPDATE');
    $lock->execute($keys);
    $before = dxf_settings_from_rows($lock->fetchAll(PDO::FETCH_ASSOC) ?: []);
    if (!hash_equals(hash('sha256', dxf_json($before)), (string)$_POST['expected_hash'])) throw new DomainException(dxf_t('Settings changed while this form was open. Reload and try again.'));
    foreach ($next as $name => $value) if (!settings_set($pdo, 'dxf_'.$name, (string)$value, 1)) throw new RuntimeException('Settings persistence failed.');
    dxf_core_audit($pdo,$actorId,'settings.updated','docx-form-settings',1,'DOCX Form settings',['before'=>$before,'after'=>$next]);
    $pdo->commit();
    dxf_admin_redirect('success', dxf_t('Settings saved.'), 'settings');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!$error instanceof DomainException) error_log('[docx-form] settings save failed: '.$error->getMessage());
    dxf_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : dxf_t('Settings could not be saved.'), 'settings');
}
