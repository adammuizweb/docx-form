<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$actorId = dxf_admin_guard($pdo, DXF_PERMISSION_READ);
$templates = $pdo->query('SELECT t.id,t.title,t.slug,t.status,t.identity_mode,t.version,t.updated_at_utc,v.revision FROM dxf_templates t LEFT JOIN dxf_template_versions v ON v.id=t.current_version_id ORDER BY t.updated_at_utc DESC,t.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$submissions = [];
if (dxf_admin_can($pdo, $actorId, DXF_PERMISSION_REVIEW)) $submissions = $pdo->query('SELECT s.id,s.public_id,s.status,s.identity_mode,s.version,s.created_at_utc,v.display_title AS title FROM dxf_submissions s JOIN dxf_template_versions v ON v.id=s.template_version_id AND v.template_id=s.template_id ORDER BY (s.status=\'pending\') DESC,s.created_at_utc DESC,s.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="dxf-admin">
  <?php dxf_admin_nav($pdo, $actorId, 'workspace'); ?>
  <header class="dxf-admin-head"><div><span><?=dxf_h(dxf_t('Document workflow'))?></span><h1><?=dxf_h(dxf_t('DOCX Form'))?></h1><p><?=dxf_h(dxf_t('Manage immutable form revisions and submitted documents.'))?></p></div><?php if(dxf_admin_can($pdo,$actorId,DXF_PERMISSION_TEMPLATES)):?><a class="adam-btn" href="<?=dxf_h(dxf_admin_url('templates/edit'))?>"><?=dxf_h(dxf_t('Add template'))?></a><?php endif;?></header>
  <section class="dxf-admin-card"><h2><?=dxf_h(dxf_t('Templates'))?></h2><div class="dxf-admin-table"><table class="adam-table"><thead><tr><th><?=dxf_h(dxf_t('Template'))?></th><th><?=dxf_h(dxf_t('Status'))?></th><th><?=dxf_h(dxf_t('Identity'))?></th><th><?=dxf_h(dxf_t('Revision'))?></th><th><?=dxf_h(dxf_t('Updated'))?></th><th><?=dxf_h(dxf_t('Action'))?></th></tr></thead><tbody>
  <?php if($templates===[]):?><tr><td colspan="6"><?=dxf_h(dxf_t('No templates have been created.'))?></td></tr><?php endif;?>
  <?php foreach($templates as $row):?><tr><td><strong><?=dxf_h($row['title'])?></strong><small>/document-forms/<?=dxf_h($row['slug'])?></small></td><td><?=dxf_h(dxf_t(ucfirst((string)$row['status'])))?></td><td><?=dxf_h(dxf_t($row['identity_mode']==='email_otp'?'Email OTP':'Self-declared'))?></td><td><?= (int)($row['revision']??0) ?></td><td><?=dxf_h(dxf_admin_datetime($row['updated_at_utc']))?></td><td><?php if(dxf_admin_can($pdo,$actorId,DXF_PERMISSION_TEMPLATES)):?><a href="<?=dxf_h(dxf_admin_url('templates/edit',['id'=>(int)$row['id']]))?>"><?=dxf_h(dxf_t('Edit'))?></a><?php endif;?></td></tr><?php endforeach;?>
  </tbody></table></div></section>
  <?php if(dxf_admin_can($pdo,$actorId,DXF_PERMISSION_REVIEW)):?><section class="dxf-admin-card"><h2><?=dxf_h(dxf_t('Recent submissions'))?></h2><div class="dxf-admin-table"><table class="adam-table"><thead><tr><th><?=dxf_h(dxf_t('Submission'))?></th><th><?=dxf_h(dxf_t('Template'))?></th><th><?=dxf_h(dxf_t('Status'))?></th><th><?=dxf_h(dxf_t('Received'))?></th><th><?=dxf_h(dxf_t('Action'))?></th></tr></thead><tbody>
  <?php if($submissions===[]):?><tr><td colspan="5"><?=dxf_h(dxf_t('No submissions have been received.'))?></td></tr><?php endif;?>
  <?php foreach($submissions as $row):?><tr><td><code><?=dxf_h($row['public_id'])?></code></td><td><?=dxf_h($row['title'])?></td><td><?=dxf_h(dxf_t(ucfirst((string)$row['status'])))?></td><td><?=dxf_h(dxf_admin_datetime($row['created_at_utc']))?></td><td><a href="<?=dxf_h(dxf_admin_url('submissions/view',['id'=>(int)$row['id']]))?>"><?=dxf_h(dxf_t('View'))?></a></td></tr><?php endforeach;?>
  </tbody></table></div></section><?php endif;?>
</div>
