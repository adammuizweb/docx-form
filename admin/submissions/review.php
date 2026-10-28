<?php
declare(strict_types=1);
require dirname(__DIR__) . '/common.php';
$actorId = dxf_admin_guard($pdo, DXF_PERMISSION_REVIEW);
dxf_admin_require_post();
$id = dxf_positive_id($_POST['id'] ?? 0);
$version = dxf_bounded_int($_POST['version'] ?? 0, 1, PHP_INT_MAX, 0);
$decision = dxf_scalar($_POST['decision'] ?? '');
$published = $stages = [];
$commitAttempted = false;
try {
    $reason = dxf_strict_text($_POST['reason'] ?? '', 1000, false);
    $revoke = $decision === 'revoke_receipt';
    if ($id < 1 || !in_array($decision, ['approve','reject','revoke_receipt'], true) || (in_array($decision, ['reject','revoke_receipt'], true) && $reason === '')) throw new DomainException(dxf_t('Choose a valid decision and provide a rejection reason.'));
    $query = $pdo->prepare('SELECT s.*,v.display_title AS title,v.descriptor_json,v.descriptor_sha256,v.docx_storage_name,v.docx_sha256,v.allow_docx_download,v.allow_pdf FROM dxf_submissions s JOIN dxf_template_versions v ON v.id=s.template_version_id AND v.template_id=s.template_id WHERE s.id=? LIMIT 1');
    $query->execute([$id]);
    $candidate = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($candidate) || (int)$candidate['version'] !== $version || (!$revoke && $candidate['status'] !== 'pending') || ($revoke && $candidate['receipt_revoked_at_utc'] !== null)) throw new DomainException(dxf_t('This submission changed after it was loaded.'));
    $descriptor = dxf_descriptor_validate((array)json_decode((string)$candidate['descriptor_json'], true, 32, JSON_THROW_ON_ERROR));
    $values = (array)json_decode((string)$candidate['values_json'], true, 32, JSON_THROW_ON_ERROR);
    $identity = (array)json_decode((string)$candidate['identity_json'], true, 16, JSON_THROW_ON_ERROR);
    if (!hash_equals((string)$candidate['values_sha256'], hash('sha256', dxf_values_json($descriptor, $values)))
        || !hash_equals((string)$candidate['identity_sha256'], hash('sha256', dxf_identity_json($identity)))
        || !hash_equals((string)$candidate['descriptor_sha256'], hash('sha256', dxf_descriptor_json($descriptor)))) throw new RuntimeException('Submission revision integrity check failed.');
    if ($decision === 'approve' && (int)$candidate['allow_pdf'] && !dxf_pdf_converter_available()) throw new DomainException(dxf_t('A trusted PDF converter is required for this approved output.'));

    $pdo->beginTransaction();
    dxf_admin_reauthorize($pdo, $actorId, DXF_PERMISSION_REVIEW);
    $lock = $pdo->prepare('SELECT status,version,template_id,template_version_id,receipt_revoked_at_utc FROM dxf_submissions WHERE id=? LIMIT 1 FOR UPDATE');
    $lock->execute([$id]);
    $row = $lock->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (int)$row['version'] !== $version || (int)$row['template_version_id'] !== (int)$candidate['template_version_id'] || (!$revoke && $row['status'] !== 'pending') || ($revoke && $row['receipt_revoked_at_utc'] !== null)) throw new DomainException(dxf_t('This submission changed after it was loaded.'));
    if ($revoke) {
        $now = dxf_now_utc();
        $update = $pdo->prepare('UPDATE dxf_submissions SET receipt_revoked_at_utc=?,version=version+1,updated_at_utc=? WHERE id=? AND version=? AND receipt_revoked_at_utc IS NULL');
        $update->execute([$now,$now,$id,$version]);
        if ($update->rowCount() !== 1) throw new DomainException(dxf_t('This submission changed after it was loaded.'));
        dxf_event($pdo,'receipt_revoked',(int)$row['template_id'],(int)$row['template_version_id'],$id,null,['reason'=>$reason],$actorId);
        dxf_core_audit($pdo,$actorId,'submission.receipt_revoked','docx-form-submission',$id,(string)$candidate['public_id'],['receipt_revoked_at_utc'=>['from'=>null,'to'=>$now]],['reason'=>$reason]);
        $pdo->commit();
        dxf_admin_redirect('success', dxf_t('Receipt access revoked.'), 'submissions/view', ['id'=>$id]);
    }
    $outputs = $decision === 'approve' ? dxf_generate_submission_outputs($candidate, $descriptor, $values, $id, $stages, $published) : ['docx'=>null,'pdf'=>null];
    $docx = $outputs['docx'];
    $pdf = $outputs['pdf'];
    $now = dxf_now_utc();
    $status = $decision === 'approve' ? 'approved' : 'rejected';
    $update = $pdo->prepare('UPDATE dxf_submissions SET status=?,review_reason=?,reviewed_by=?,reviewed_at_utc=?,output_docx_storage_name=?,output_docx_sha256=?,output_pdf_storage_name=?,output_pdf_sha256=?,output_generated_at_utc=?,version=version+1,updated_at_utc=? WHERE id=? AND version=?');
    $update->execute([$status,$reason ?: null,$actorId,$now,$docx['name'] ?? null,$docx['sha256'] ?? null,$pdf['name'] ?? null,$pdf['sha256'] ?? null,($docx || $pdf) ? $now : null,$now,$id,$version]);
    if ($update->rowCount() !== 1) throw new DomainException(dxf_t('This submission changed after it was loaded.'));
    dxf_event($pdo,'submission_reviewed',(int)$row['template_id'],(int)$row['template_version_id'],$id,null,['decision'=>$status],$actorId);
    dxf_event($pdo,$decision === 'approve' ? 'submission_approved' : 'submission_rejected',(int)$row['template_id'],(int)$row['template_version_id'],$id,null,[],$actorId);
    if ($docx) dxf_event($pdo,'docx_generated',(int)$row['template_id'],(int)$row['template_version_id'],$id,null,['sha256'=>$docx['sha256']],$actorId);
    if ($pdf) dxf_event($pdo,'pdf_generated',(int)$row['template_id'],(int)$row['template_version_id'],$id,null,['sha256'=>$pdf['sha256']],$actorId);
    dxf_core_audit($pdo,$actorId,'submission.'.$status,'docx-form-submission',$id,(string)$candidate['public_id'],['status'=>['from'=>'pending','to'=>$status]],['template_id'=>(int)$row['template_id']]);
    $commitAttempted = true;
    $pdo->commit();
    dxf_admin_redirect('success', dxf_t('Submission review saved.'), 'submissions/view', ['id'=>$id]);
} catch (Throwable $error) {
    $rolledBack = false;
    if ($pdo->inTransaction()) try { $pdo->rollBack(); $rolledBack = true; } catch (Throwable) {}
    dxf_cleanup_generated_outputs($stages, $published, $rolledBack || !$commitAttempted);
    if (!$error instanceof DomainException) error_log('[docx-form] review failed: '.$error->getMessage());
    dxf_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : dxf_t('The review could not be saved.'), 'submissions/view', ['id'=>$id]);
}
