<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok' : 'not ok') . ' - ' . $message . PHP_EOL;
    if (!$ok) $failures++;
};
$public = (string)file_get_contents($root . '/includes/public.php');
$repository = (string)file_get_contents($root . '/includes/repository.php');
$save = (string)file_get_contents($root . '/admin/templates/save.php');
$view = (string)file_get_contents($root . '/admin/submissions/view.php');
$foundation = (string)file_get_contents($root . '/migrations/0001-foundation.sql');
$workflowMigration = (string)file_get_contents($root . '/migrations/0006-workflow-mode.php');

$created = strpos($public, "dxf_event(\$pdo, 'submission_created'");
$generated = strpos($public, "dxf_event(\$pdo, 'docx_generated'");
$ready = strpos($public, "dxf_event(\$pdo, 'submission_ready'");
$commit = strpos($public, '$pdo->commit();', $created === false ? 0 : $created);
$check($created !== false && $generated !== false && $ready !== false && $commit !== false && $created < $generated && $generated < $ready && $ready < $commit, 'immediate workflow records ordered append-only events before commit');
$check(str_contains($public, "SET status='ready'") && !preg_match("/SET status='ready'[^;]*(?:review_reason|reviewed_by|reviewed_at_utc)/", $public), 'ready transition does not populate review fields');
$check(str_contains($public, "throw new RuntimeException('Immediate output generation failed:") && str_contains($public, '$readyAt = dxf_now_utc();'), 'immediate generation failures are server errors and completion time follows generation');
$check(str_contains($save, "['operator_review','immediate_download']") && str_contains($save, "\$workflowMode === 'immediate_download' && !\$toggles['allow_docx_download']"), 'template revisions validate exact workflow modes and immediate DOCX capability');
$check(!str_contains($foundation, 'workflow_mode') && str_contains($workflowMigration, "workflow_mode` IN ('operator_review','immediate_download')") && str_contains($workflowMigration, "'pending','approved','rejected','ready'"), 'append-only migration adds workflow mode and ready status after the immutable foundation');
$check(str_contains($repository, "'ready'=>'approved_retention_days'") && substr_count($repository, "status IN ('approved','ready')") >= 2, 'ready submissions use approved and generated-output retention');
$check(str_contains($view, "\$submission['status']==='pending'") && str_contains($view, "\$submission['status']==='ready'") && str_contains($view, 'Output was generated automatically when the form was submitted.'), 'admin decisions remain pending-only and ready output is described as automatic');

exit($failures === 0 ? 0 : 1);
