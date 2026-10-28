<?php
declare(strict_types=1);
require dirname(__DIR__) . '/common.php';
$actorId = dxf_admin_guard($pdo, DXF_PERMISSION_TEMPLATES);
dxf_admin_require_post();
$id = dxf_positive_id($_POST['id'] ?? 0);
$version = dxf_bounded_int($_POST['version'] ?? 0, 0, PHP_INT_MAX, 0);
$stored = $stages = [];
try {
    $title = dxf_strict_text($_POST['title'] ?? null, 191);
    $slug = dxf_slug($_POST['slug'] ?? '');
    $description = dxf_strict_text($_POST['description'] ?? '', 2000, false);
    $status = dxf_scalar($_POST['status'] ?? '');
    $identityMode = dxf_scalar($_POST['identity_mode'] ?? '');
    $workflowMode = dxf_scalar($_POST['workflow_mode'] ?? 'operator_review');
    if ($title === '' || $slug === null || !in_array($status, ['draft','published','archived'], true) || !in_array($identityMode, ['email_otp','self_declared'], true) || !in_array($workflowMode, ['operator_review','immediate_download'], true)) throw new DomainException(dxf_t('Enter valid template details.'));
    $descriptor = dxf_descriptor_decode(dxf_scalar($_POST['descriptor_json'] ?? ''));
    $bindings = [];
    foreach ($descriptor['fields'] as $field) if (isset($field['identity'])) $bindings[$field['identity']] = true;
    if ($identityMode === 'email_otp' && !isset($bindings['email'])) throw new DomainException(dxf_t('Email OTP templates require an email identity binding.'));
    if ($status === 'published' && $identityMode === 'email_otp' && !dxf_otp_sender_available()) throw new DomainException(dxf_t('Register a trusted OTP sender before publishing an email OTP template.'));
    $current = null;
    if ($id) {
        $query = $pdo->prepare('SELECT t.*,v.* FROM dxf_templates t JOIN dxf_template_versions v ON v.id=t.current_version_id WHERE t.id=? LIMIT 1');
        $query->execute([$id]);
        $current = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) throw new DomainException(dxf_t('The selected template is unavailable.'));
    }
    $docxUpload = dxf_admin_uploaded_path('docx', $id === 0);
    $pdfUpload = dxf_admin_uploaded_path('source_pdf', false);
    if ($docxUpload !== null) dxf_validate_docx($docxUpload, $descriptor);
    else {
        $docxMetadata = dxf_validate_docx(dxf_storage_path((string)$current['docx_storage_name']), $descriptor);
        if (!hash_equals((string)$current['docx_sha256'], $docxMetadata['sha256'])) throw new RuntimeException('Stored DOCX integrity check failed.');
    }
    if ($pdfUpload !== null) dxf_validate_pdf($pdfUpload);
    $removePdf = dxf_bool($_POST['remove_source_pdf'] ?? 0) === 1;
    $toggles = [];
    foreach (['allow_source_view','allow_source_download','allow_docx_download','allow_print','allow_pdf'] as $key) $toggles[$key] = dxf_bool($_POST[$key] ?? 0);
    if ($workflowMode === 'immediate_download' && !$toggles['allow_docx_download']) throw new DomainException(dxf_t('Immediate download requires generated DOCX downloads to be enabled.'));
    if ($status === 'published' && $toggles['allow_pdf'] && !dxf_pdf_converter_available()) throw new DomainException(dxf_t('Register a trusted PDF converter before publishing a template with PDF output.'));
    $hasPdf = $pdfUpload !== null || (!$removePdf && is_array($current) && $current['source_pdf_storage_name']);
    if (!$hasPdf && ($toggles['allow_source_view'] || $toggles['allow_source_download'])) throw new DomainException(dxf_t('Source PDF controls require an uploaded PDF.'));
    $inlineValidated = $pdfUpload !== null ? dxf_pdf_inline_available() : (int)($current['source_pdf_inline_validated'] ?? 0) === 1;
    if ($toggles['allow_source_view'] && (!$inlineValidated || !dxf_pdf_inline_available())) throw new DomainException(dxf_t('Inline source viewing requires a trusted PDF validator/sanitizer.'));

    $descriptorJson = dxf_descriptor_json($descriptor);
    $now = dxf_now_utc();
    $pdo->beginTransaction();
    dxf_admin_reauthorize($pdo, $actorId, DXF_PERMISSION_TEMPLATES);
    $before = null;
    if ($id) {
        $lock = $pdo->prepare('SELECT * FROM dxf_templates WHERE id=? LIMIT 1 FOR UPDATE');
        $lock->execute([$id]);
        $locked = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($locked) || (int)$locked['version'] !== $version || (int)$locked['current_version_id'] !== (int)$current['current_version_id']) throw new DomainException(dxf_t('This template changed after it was loaded. Reload and try again.'));
        $before = ['status'=>$locked['status'],'version'=>(int)$locked['version'],'current_version_id'=>(int)$locked['current_version_id']];
        $revisionQuery = $pdo->prepare('SELECT revision FROM dxf_template_versions WHERE template_id=? ORDER BY revision FOR UPDATE');
        $revisionQuery->execute([$id]);
        $revisions = array_map('intval', $revisionQuery->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $revision = ($revisions === [] ? 0 : max($revisions)) + 1;
        $pdo->prepare('UPDATE dxf_templates SET title=?,slug=?,description=?,status=?,identity_mode=?,version=version+1,updated_by=?,updated_at_utc=? WHERE id=? AND version=?')->execute([$title,$slug,$description ?: null,$status,$identityMode,$actorId,$now,$id,$version]);
    } else {
        $duplicate = $pdo->prepare('SELECT id FROM dxf_templates WHERE slug=? LIMIT 1 FOR UPDATE');
        $duplicate->execute([$slug]);
        if ($duplicate->fetchColumn()) throw new DomainException(dxf_t('The template slug is already in use.'));
        $pdo->prepare('INSERT INTO dxf_templates (title,slug,description,status,identity_mode,created_by,updated_by,created_at_utc,updated_at_utc) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$title,$slug,$description ?: null,$status,$identityMode,$actorId,$actorId,$now,$now]);
        $id = (int)$pdo->lastInsertId();
        $revision = 1;
    }

    if ($docxUpload !== null) {
        $stage = dxf_stage_file($docxUpload, 'docx'); $stages[] = $stage;
        $docx = dxf_publish_staged_file($stage, 'docx'); $stages = array_diff($stages, [$stage]); $stored[] = $docx['name'];
    } else $docx = ['name'=>$current['docx_storage_name'],'sha256'=>$current['docx_sha256'],'size'=>(int)$current['docx_size']];
    if ($pdfUpload !== null) {
        if (dxf_pdf_inline_available()) {
            $stage = dxf_new_stage_path('pdf'); $stages[] = $stage;
            dxf_sanitize_pdf($pdfUpload, $stage, ['purpose'=>'source','template_id'=>$id]);
        } else { $stage = dxf_stage_file($pdfUpload, 'pdf'); $stages[] = $stage; }
        $pdf = dxf_publish_staged_file($stage, 'pdf'); $stages = array_diff($stages, [$stage]); $stored[] = $pdf['name'];
    } elseif ($removePdf || !$current) $pdf = null;
    else {
        $pdf = $current['source_pdf_storage_name'] ? ['name'=>$current['source_pdf_storage_name'],'sha256'=>$current['source_pdf_sha256'],'size'=>(int)$current['source_pdf_size']] : null;
        if ($pdf !== null) { $pdfPath = dxf_storage_path($pdf['name']); dxf_validate_pdf($pdfPath); if (!hash_equals((string)$pdf['sha256'], hash_file('sha256', $pdfPath))) throw new RuntimeException('Stored source PDF integrity check failed.'); }
    }
    $insert = $pdo->prepare('INSERT INTO dxf_template_versions (template_id,revision,display_title,display_description,workflow_mode,descriptor_json,descriptor_sha256,docx_storage_name,docx_sha256,docx_size,source_pdf_storage_name,source_pdf_sha256,source_pdf_size,source_pdf_inline_validated,allow_source_view,allow_source_download,allow_docx_download,allow_print,allow_pdf,created_by,created_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute([$id,$revision,$title,$description ?: null,$workflowMode,$descriptorJson,hash('sha256',$descriptorJson),$docx['name'],$docx['sha256'],$docx['size'],$pdf['name'] ?? null,$pdf['sha256'] ?? null,$pdf['size'] ?? null,$pdf !== null && $inlineValidated ? 1 : 0,$toggles['allow_source_view'],$toggles['allow_source_download'],$toggles['allow_docx_download'],$toggles['allow_print'],$toggles['allow_pdf'],$actorId,$now]);
    $versionId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE dxf_templates SET current_version_id=? WHERE id=?')->execute([$versionId,$id]);
    dxf_core_audit($pdo,$actorId,$before === null ? 'template.created' : 'template.revised','docx-form-template',$id,$title,['before'=>$before,'after'=>['status'=>$status,'version'=>$version+1,'revision'=>$revision,'workflow_mode'=>$workflowMode]],['descriptor_sha256'=>hash('sha256',$descriptorJson),'docx_sha256'=>$docx['sha256'],'source_pdf_sha256'=>$pdf['sha256'] ?? null]);
    $pdo->commit();
    dxf_admin_redirect('success', dxf_t('Template revision saved.'), 'templates/edit', ['id'=>$id]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($stages as $stage) try { dxf_discard_stage($stage); } catch (Throwable) {}
    foreach ($stored as $name) try { @unlink(dxf_storage_path($name)); } catch (Throwable) {}
    if (!$error instanceof DomainException) error_log('[docx-form] template save failed: '.$error->getMessage());
    dxf_admin_redirect('error', $error instanceof DomainException ? $error->getMessage() : dxf_t('The template could not be saved.'), 'templates/edit', $id ? ['id'=>$id] : []);
}
