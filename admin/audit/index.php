<?php
declare(strict_types=1);

require dirname(__DIR__) . '/common.php';
$actorId = dxf_admin_guard($pdo, DXF_PERMISSION_AUDIT);
$page = dxf_bounded_int($_GET['p'] ?? 1, 1, 1000000, 1);
$perPage = 50;
$total = (int)$pdo->query('SELECT COUNT(*) FROM dxf_submission_events')->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$query = $pdo->prepare('SELECT e.id,e.event_key,e.submission_id,e.template_id,e.actor_user_id,e.ip_hash,e.user_agent_hash,e.created_at_utc,v.display_title AS title FROM dxf_submission_events e JOIN dxf_template_versions v ON v.id=e.template_version_id AND v.template_id=e.template_id ORDER BY e.id DESC LIMIT :limit OFFSET :offset');
$query->bindValue(':limit', $perPage, PDO::PARAM_INT);
$query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$query->execute();
$events = $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="dxf-admin">
  <?php dxf_admin_nav($pdo, $actorId, 'audit'); ?>
  <header class="dxf-admin-head">
    <div><span><?=dxf_h(dxf_t('Privacy-safe trail'))?></span><h1><?=dxf_h(dxf_t('Audit'))?></h1><p><?=dxf_h(dxf_t('Append-only domain events with hashed network identifiers.'))?></p></div>
  </header>
  <section class="dxf-admin-card">
    <div class="dxf-admin-table">
      <table class="adam-table">
        <thead><tr><th><?=dxf_h(dxf_t('Event'))?></th><th><?=dxf_h(dxf_t('Template'))?></th><th><?=dxf_h(dxf_t('Submission'))?></th><th><?=dxf_h(dxf_t('Actor'))?></th><th><?=dxf_h(dxf_t('Request hashes'))?></th><th><?=dxf_h(dxf_t('Time'))?></th></tr></thead>
        <tbody>
          <?php if ($events === []): ?><tr><td class="dxf-empty" colspan="6"><?=dxf_h(dxf_t('No audit events have been recorded.'))?></td></tr><?php endif; ?>
          <?php foreach ($events as $event): ?>
            <tr><td><?=dxf_h($event['event_key'])?></td><td><?=dxf_h($event['title'])?></td><td><?=$event['submission_id'] ? '#' . (int)$event['submission_id'] : '-'?></td><td><?=$event['actor_user_id'] ? '#' . (int)$event['actor_user_id'] : dxf_h(dxf_t('Public user'))?></td><td><code><?=dxf_h(substr($event['ip_hash'], 0, 12))?>...</code> <code><?=dxf_h(substr($event['user_agent_hash'], 0, 12))?>...</code></td><td><?=dxf_h(dxf_admin_datetime($event['created_at_utc']))?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php if ($pages > 1): ?>
    <nav class="dxf-pagination" aria-label="<?=dxf_h(dxf_t('Audit pagination'))?>"><span><?=dxf_h(dxf_t('Page %d of %d', $page, $pages))?></span><?php if ($page > 1): ?><a href="<?=dxf_h(dxf_admin_url('audit', ['p'=>$page - 1]))?>"><?=dxf_h(dxf_t('Previous'))?></a><?php endif; ?><?php if ($page < $pages): ?><a href="<?=dxf_h(dxf_admin_url('audit', ['p'=>$page + 1]))?>"><?=dxf_h(dxf_t('Next'))?></a><?php endif; ?></nav>
  <?php endif; ?>
</div>
