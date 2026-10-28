<?php
declare(strict_types=1);
require dirname(__DIR__) . '/common.php';
$actorId = dxf_admin_guard($pdo, DXF_PERMISSION_SETTINGS);
$settings = dxf_settings($pdo);
$hash = hash('sha256', dxf_json($settings));
$fields = [
    'otp_expiry_minutes'=>['OTP expiry in minutes',5,30],
    'otp_max_attempts'=>['Maximum OTP attempts',3,10],
    'otp_resend_cooldown_seconds'=>['OTP resend cooldown in seconds',30,3600],
    'rate_window_seconds'=>['Rate-limit window in seconds',60,86400],
    'rate_max_requests'=>['Maximum requests per window',1,100],
    'receipt_expiry_hours'=>['Receipt expiry in hours',1,8760],
    'otp_retention_days'=>['Expired OTP retention in days',1,3650],
    'pending_retention_days'=>['Pending submission retention in days',1,3650],
    'rejected_retention_days'=>['Rejected submission retention in days',1,3650],
    'approved_retention_days'=>['Approved submission retention in days',1,3650],
    'output_retention_days'=>['Generated output retention in days',1,3650],
    'event_retention_days'=>['Event retention in days',7,3650],
    'staging_retention_hours'=>['Staging retention in hours',1,168],
];
?>
<div class="dxf-admin"><?php dxf_admin_nav($pdo,$actorId,'settings');?><header class="dxf-admin-head"><div><span><?=dxf_h(dxf_t('Operational limits'))?></span><h1><?=dxf_h(dxf_t('Settings'))?></h1><p><?=dxf_h(dxf_t('Configure document previews, bounded public requests, and retention controls.'))?></p></div></header><form class="dxf-admin-card" method="post" action="<?=dxf_h(dxf_admin_url('settings/save'))?>"><input type="hidden" name="csrf_token" value="<?=dxf_h(csrf_token())?>"><input type="hidden" name="expected_hash" value="<?=$hash?>"><fieldset class="dxf-preview-setting"><legend><?=dxf_h(dxf_t('Public document preview'))?></legend><label><input type="radio" name="preview_mode" value="shadow"<?=$settings['preview_mode']==='shadow'?' checked':''?>> <span><strong><?=dxf_h(dxf_t('Shadow preview'))?></strong><small><?=dxf_h(dxf_t('Show the fast descriptor-based field summary.'))?></small></span></label><label><input type="radio" name="preview_mode" value="rendered_docx"<?=$settings['preview_mode']==='rendered_docx'?' checked':''?>> <span><strong><?=dxf_h(dxf_t('Rendered DOCX preview'))?></strong><small><?=dxf_h(dxf_t('Generate a temporary DOCX from current values and render its actual document structure in the browser.'))?></small></span></label><p><?=dxf_h(dxf_t('Rendered previews apply only to immediate-download templates and never create submissions or retained output.'))?></p></fieldset><div class="dxf-admin-grid"><?php foreach($fields as $name=>[$label,$minimum,$maximum]):?><label><?=dxf_h(dxf_t($label))?><input class="inp" type="number" min="<?=$minimum?>" max="<?=$maximum?>" name="<?=dxf_h($name)?>" value="<?=$settings[$name]?>"></label><?php endforeach;?></div><p><?=dxf_h(dxf_otp_sender_available()?dxf_t('Trusted OTP sender: available'):dxf_t('Trusted OTP sender: unavailable'))?><br><?=dxf_h(dxf_pdf_converter_available()?dxf_t('Trusted PDF converter: available'):dxf_t('Trusted PDF converter: unavailable'))?><br><?=dxf_h(dxf_pdf_inline_available()?dxf_t('Trusted PDF validator/sanitizer: available'):dxf_t('Trusted PDF validator/sanitizer: unavailable'))?></p><button class="adam-btn" type="submit"><?=dxf_h(dxf_t('Save settings'))?></button></form></div>
