<?php
declare(strict_types=1);

function dxf_import_identifier(mixed $value, string $label): string
{
    if (!is_string($value) || strlen($value) > 100 || preg_match('/\A[a-z0-9](?:[a-z0-9._-]{0,98}[a-z0-9])?\z/D', $value) !== 1) {
        throw new DomainException('Invalid DOCX Form import ' . $label . '.');
    }
    return $value;
}

function dxf_import_file(string $path, string $extension, string $expectedHash, int $expectedSize): array
{
    $stat = @lstat($path);
    $real = realpath($path);
    if (!is_array($stat) || $real === false || is_link($path) || !is_file($real) || !is_readable($real)
        || (int)($stat['nlink'] ?? 0) !== 1 || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== $extension
        || preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1 || $expectedSize < 1) {
        throw new DomainException('A DOCX Form import source file is unavailable or unsafe.');
    }
    $size = filesize($real);
    $hash = hash_file('sha256', $real);
    if ($size !== $expectedSize || !is_string($hash) || !hash_equals($expectedHash, $hash)) throw new DomainException('A DOCX Form import source file failed integrity verification.');
    return ['path'=>$real,'sha256'=>$hash,'size'=>$size];
}

function dxf_import_projection(array $definition): array
{
    return [
        'title'=>$definition['title'],'slug'=>$definition['slug'],'description'=>$definition['description'],
        'status'=>$definition['status'],'identity_mode'=>$definition['identity_mode'],
        'descriptor_sha256'=>$definition['descriptor_sha256'],
        'docx_sha256'=>$definition['docx']['sha256'],'docx_size'=>$definition['docx']['size'],
        'source_pdf_sha256'=>$definition['source_pdf']['sha256'] ?? null,'source_pdf_size'=>$definition['source_pdf']['size'] ?? null,
        'source_pdf_inline_validated'=>0,
        'allow_source_view'=>$definition['allow_source_view'] ? 1 : 0,
        'allow_source_download'=>$definition['allow_source_download'] ? 1 : 0,
        'allow_docx_download'=>$definition['allow_docx_download'] ? 1 : 0,
        'allow_print'=>$definition['allow_print'] ? 1 : 0,
        'allow_pdf'=>$definition['allow_pdf'] ? 1 : 0,
    ];
}

function dxf_import_projection_hash(array $definition): string
{
    return hash('sha256', dxf_json(dxf_import_projection($definition)));
}

function dxf_import_prepare_package(array $package): array
{
    if (array_diff(array_keys($package), ['namespace','release','predecessor_release','templates']) !== []) throw new DomainException('The DOCX Form import package contains unsupported properties.');
    $namespace = dxf_import_identifier($package['namespace'] ?? null, 'namespace');
    $release = dxf_import_identifier($package['release'] ?? null, 'release');
    $predecessor = $package['predecessor_release'] ?? null;
    if ($predecessor !== null) $predecessor = dxf_import_identifier($predecessor, 'predecessor release');
    $templates = $package['templates'] ?? null;
    if (!is_array($templates) || !array_is_list($templates) || $templates === [] || count($templates) > 100) throw new DomainException('The DOCX Form import package requires a bounded template list.');

    $prepared = [];
    $keys = $slugs = [];
    $allowed = ['definition_id','title','slug','description','status','identity_mode','descriptor','descriptor_sha256','docx_path','docx_sha256','docx_size','source_pdf_path','source_pdf_sha256','source_pdf_size','allow_source_view','allow_source_download','allow_docx_download','allow_print','allow_pdf'];
    foreach ($templates as $input) {
        if (!is_array($input) || array_is_list($input) || array_diff(array_keys($input), $allowed) !== []) throw new DomainException('A DOCX Form import definition contains unsupported properties.');
        $definitionId = dxf_import_identifier($input['definition_id'] ?? null, 'definition ID');
        $title = dxf_strict_text($input['title'] ?? null, 191);
        $slug = dxf_slug($input['slug'] ?? null);
        $description = dxf_strict_text($input['description'] ?? '', 2000, false);
        $status = $input['status'] ?? null;
        $identity = $input['identity_mode'] ?? null;
        if ($title === '' || $slug === null || isset($keys[$definitionId]) || isset($slugs[$slug])
            || !in_array($status, ['draft','published','archived'], true) || !in_array($identity, ['email_otp','self_declared'], true)) {
            throw new DomainException('A DOCX Form import definition has invalid identity or metadata.');
        }
        foreach (['allow_source_view','allow_source_download','allow_docx_download','allow_print','allow_pdf'] as $toggle) if (!is_bool($input[$toggle] ?? null)) throw new DomainException('DOCX Form import capability flags must be booleans.');
        if (!is_array($input['descriptor'] ?? null)) throw new DomainException('A DOCX Form import descriptor is unavailable.');
        $descriptor = dxf_descriptor_validate($input['descriptor']);
        $descriptorJson = dxf_descriptor_json($descriptor);
        $descriptorHash = hash('sha256', $descriptorJson);
        if (!is_string($input['descriptor_sha256'] ?? null) || !hash_equals($input['descriptor_sha256'], $descriptorHash)) throw new DomainException('A DOCX Form import descriptor failed canonical integrity verification.');
        $bindings = [];
        foreach ($descriptor['fields'] as $field) if (isset($field['identity'])) $bindings[$field['identity']] = true;
        if ($identity === 'email_otp' && !isset($bindings['email'])) throw new DomainException('Email OTP imports require an email identity binding.');
        if ($status === 'published' && $identity === 'email_otp' && !dxf_otp_sender_available()) throw new DomainException('A trusted OTP sender is required before publishing this import.');
        if (($input['allow_pdf'] ?? false) && !dxf_pdf_converter_available()) throw new DomainException('A trusted PDF converter is required before enabling imported PDF output.');

        $docx = dxf_import_file((string)($input['docx_path'] ?? ''), 'docx', (string)($input['docx_sha256'] ?? ''), (int)($input['docx_size'] ?? 0));
        dxf_validate_docx($docx['path'], $descriptor);
        $pdf = null;
        $pdfPath = $input['source_pdf_path'] ?? null;
        if ($pdfPath !== null) {
            if (!is_string($pdfPath)) throw new DomainException('A DOCX Form import source PDF path is invalid.');
            $pdf = dxf_import_file($pdfPath, 'pdf', (string)($input['source_pdf_sha256'] ?? ''), (int)($input['source_pdf_size'] ?? 0));
            dxf_validate_pdf($pdf['path']);
        } elseif (($input['source_pdf_sha256'] ?? null) !== null || ($input['source_pdf_size'] ?? null) !== null) throw new DomainException('DOCX Form source PDF metadata is incomplete.');
        if ($pdf === null && (($input['allow_source_view'] ?? false) || ($input['allow_source_download'] ?? false))) throw new DomainException('Source PDF controls require an imported PDF.');
        if (($input['allow_source_view'] ?? false) || dxf_bool($input['allow_source_view'] ?? false) === 1) throw new DomainException('Batch imports cannot claim trusted inline PDF validation.');

        $definition = [
            'definition_id'=>$definitionId,'title'=>$title,'slug'=>$slug,'description'=>$description,
            'status'=>$status,'identity_mode'=>$identity,'descriptor'=>$descriptor,'descriptor_json'=>$descriptorJson,'descriptor_sha256'=>$descriptorHash,
            'docx'=>$docx,'source_pdf'=>$pdf,
            'allow_source_view'=>$input['allow_source_view'],'allow_source_download'=>$input['allow_source_download'],
            'allow_docx_download'=>$input['allow_docx_download'],'allow_print'=>$input['allow_print'],'allow_pdf'=>$input['allow_pdf'],
        ];
        $definition['projection_sha256'] = dxf_import_projection_hash($definition);
        $prepared[] = $definition;
        $keys[$definitionId] = true;
        $slugs[$slug] = true;
    }
    usort($prepared, static fn(array $a, array $b): int => strcmp($a['definition_id'], $b['definition_id']));
    return ['namespace'=>$namespace,'release'=>$release,'predecessor_release'=>$predecessor,'templates'=>$prepared];
}

function dxf_import_row_projection(array $row): array
{
    return [
        'title'=>(string)$row['title'],'slug'=>(string)$row['slug'],'description'=>(string)($row['description'] ?? ''),
        'status'=>(string)$row['status'],'identity_mode'=>(string)$row['identity_mode'],
        'descriptor_sha256'=>(string)$row['descriptor_sha256'],
        'docx_sha256'=>(string)$row['docx_sha256'],'docx_size'=>(int)$row['docx_size'],
        'source_pdf_sha256'=>$row['source_pdf_sha256'] === null ? null : (string)$row['source_pdf_sha256'],
        'source_pdf_size'=>$row['source_pdf_size'] === null ? null : (int)$row['source_pdf_size'],
        'source_pdf_inline_validated'=>(int)$row['source_pdf_inline_validated'],
        'allow_source_view'=>(int)$row['allow_source_view'],'allow_source_download'=>(int)$row['allow_source_download'],
        'allow_docx_download'=>(int)$row['allow_docx_download'],'allow_print'=>(int)$row['allow_print'],'allow_pdf'=>(int)$row['allow_pdf'],
    ];
}

function dxf_import_plan_prepared(PDO $pdo, array $package, bool $lock = false): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $ledgerQuery = $pdo->prepare('SELECT * FROM dxf_import_ledger WHERE namespace_id=? ORDER BY definition_id' . $suffix);
    $ledgerQuery->execute([$package['namespace']]);
    $ledger = [];
    foreach ($ledgerQuery->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $ledger[(string)$row['definition_id']] = $row;
    $expectedKeys = array_column($package['templates'], 'definition_id');
    $extra = array_diff(array_keys($ledger), $expectedKeys);
    if ($extra !== []) throw new DomainException('The managed DOCX Form import package cannot silently retire definitions: ' . implode(', ', $extra));

    $actions = [];
    foreach ($package['templates'] as $definition) {
        $managed = $ledger[$definition['definition_id']] ?? null;
        $slugQuery = $pdo->prepare('SELECT id FROM dxf_templates WHERE slug=? LIMIT 1' . $suffix);
        $slugQuery->execute([$definition['slug']]);
        $slugId = (int)($slugQuery->fetchColumn() ?: 0);
        if (!is_array($managed)) {
            if ($slugId > 0) throw new DomainException('An unmanaged DOCX Form template already owns slug: ' . $definition['slug']);
            $actions[$definition['definition_id']] = ['action'=>'create','template_id'=>null,'template_version_id'=>null];
            continue;
        }
        $templateId = (int)$managed['template_id'];
        $versionId = (int)$managed['template_version_id'];
        if ($slugId !== $templateId) throw new DomainException('A managed DOCX Form slug or ledger reference drifted: ' . $definition['slug']);
        $query = $pdo->prepare('SELECT t.*,v.display_title AS version_title,v.display_description AS version_description,v.descriptor_sha256,v.docx_storage_name,v.docx_sha256,v.docx_size,v.source_pdf_storage_name,v.source_pdf_sha256,v.source_pdf_size,v.source_pdf_inline_validated,v.allow_source_view,v.allow_source_download,v.allow_docx_download,v.allow_print,v.allow_pdf FROM dxf_templates t JOIN dxf_template_versions v ON v.id=t.current_version_id AND v.template_id=t.id WHERE t.id=? LIMIT 1' . $suffix);
        $query->execute([$templateId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['current_version_id'] !== $versionId) throw new DomainException('A managed DOCX Form current revision drifted: ' . $definition['slug']);
        if ((string)$row['title'] !== (string)$row['version_title'] || (string)($row['description'] ?? '') !== (string)($row['version_description'] ?? '')) throw new DomainException('A managed DOCX Form mutable metadata drifted from its current revision: ' . $definition['slug']);
        $currentProjection = hash('sha256', dxf_json(dxf_import_row_projection($row)));
        if (!hash_equals((string)$managed['projection_sha256'], $currentProjection)) throw new DomainException('A managed DOCX Form projection drifted: ' . $definition['slug']);
        $docxPath = dxf_storage_path((string)$row['docx_storage_name']);
        if ((int)filesize($docxPath) !== (int)$row['docx_size'] || !hash_equals((string)$row['docx_sha256'], (string)hash_file('sha256', $docxPath))) throw new DomainException('A managed DOCX Form DOCX file drifted: ' . $definition['slug']);
        if ($row['source_pdf_storage_name'] !== null) {
            $pdfPath = dxf_storage_path((string)$row['source_pdf_storage_name']);
            if ((int)filesize($pdfPath) !== (int)$row['source_pdf_size'] || !hash_equals((string)$row['source_pdf_sha256'], (string)hash_file('sha256', $pdfPath))) throw new DomainException('A managed DOCX Form PDF file drifted: ' . $definition['slug']);
        } elseif ($row['source_pdf_sha256'] !== null || $row['source_pdf_size'] !== null) throw new DomainException('A managed DOCX Form PDF record is incomplete: ' . $definition['slug']);
        if (hash_equals((string)$managed['release_id'], $package['release'])) {
            if (!hash_equals((string)$managed['projection_sha256'], $definition['projection_sha256'])) throw new DomainException('A DOCX Form import release was changed without a new release ID: ' . $definition['definition_id']);
            $action = 'current';
        } else {
            if ($package['predecessor_release'] === null || !hash_equals((string)$managed['release_id'], $package['predecessor_release'])) throw new DomainException('A DOCX Form import predecessor does not match managed state: ' . $definition['definition_id']);
            $action = 'update';
        }
        $actions[$definition['definition_id']] = ['action'=>$action,'template_id'=>$templateId,'template_version_id'=>$versionId];
    }
    return ['namespace'=>$package['namespace'],'release'=>$package['release'],'actions'=>$actions,'changes'=>count(array_filter($actions, static fn(array $item): bool => $item['action'] !== 'current'))];
}

function dxf_import_templates(PDO $pdo, array $package, int $actorId = 0, bool $apply = false): array
{
    if (!dxf_schema_ready($pdo)) throw new RuntimeException('DOCX Form schema is unavailable for import.');
    $prepared = dxf_import_prepare_package($package);
    $plan = dxf_import_plan_prepared($pdo, $prepared);
    if (!$apply || $plan['changes'] === 0) return $plan;
    if ($actorId < 1 || !function_exists('authorization_lock_actor_permissions') || !function_exists('user_can') || !function_exists('audit_log_record')) throw new RuntimeException('DOCX Form import authorization APIs are unavailable.');

    $lockName = 'dxf-import-' . substr(hash('sha256', $prepared['namespace']), 0, 48);
    $lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('DOCX Form import lock is unavailable.');
    $stages = $published = [];
    $commitAttempted = false;
    try {
        $plan = dxf_import_plan_prepared($pdo, $prepared);
        foreach ($prepared['templates'] as $definition) {
            if (($plan['actions'][$definition['definition_id']]['action'] ?? 'current') === 'current') continue;
            $docxStage = dxf_stage_file($definition['docx']['path'], 'docx');
            $stages[$definition['definition_id']]['docx'] = $docxStage;
            if ($definition['source_pdf'] !== null) $stages[$definition['definition_id']]['pdf'] = dxf_stage_file($definition['source_pdf']['path'], 'pdf');
        }

        $pdo->beginTransaction();
        if (!authorization_lock_actor_permissions($pdo, $actorId) || !user_can($pdo, $actorId, DXF_PERMISSION_TEMPLATES)) throw new RuntimeException('DOCX Form import permission changed.');
        $plan = dxf_import_plan_prepared($pdo, $prepared, true);
        $now = dxf_now_utc();
        foreach ($prepared['templates'] as $definition) {
            $item = $plan['actions'][$definition['definition_id']];
            if ($item['action'] === 'current') continue;
            $docx = dxf_publish_staged_file($stages[$definition['definition_id']]['docx'], 'docx');
            unset($stages[$definition['definition_id']]['docx']);
            $published[] = $docx['name'];
            $pdf = null;
            if (isset($stages[$definition['definition_id']]['pdf'])) {
                $pdf = dxf_publish_staged_file($stages[$definition['definition_id']]['pdf'], 'pdf');
                unset($stages[$definition['definition_id']]['pdf']);
                $published[] = $pdf['name'];
            }
            if ($item['action'] === 'create') {
                $pdo->prepare('INSERT INTO dxf_templates (title,slug,description,status,identity_mode,created_by,updated_by,created_at_utc,updated_at_utc) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$definition['title'],$definition['slug'],$definition['description'] ?: null,$definition['status'],$definition['identity_mode'],$actorId,$actorId,$now,$now]);
                $templateId = (int)$pdo->lastInsertId();
                $revision = 1;
            } else {
                $templateId = (int)$item['template_id'];
                $revisionQuery = $pdo->prepare('SELECT revision FROM dxf_template_versions WHERE template_id=? ORDER BY revision FOR UPDATE');
                $revisionQuery->execute([$templateId]);
                $revisions = array_map('intval', $revisionQuery->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $revision = ($revisions === [] ? 0 : max($revisions)) + 1;
                $pdo->prepare('UPDATE dxf_templates SET title=?,slug=?,description=?,status=?,identity_mode=?,version=version+1,updated_by=?,updated_at_utc=? WHERE id=?')->execute([$definition['title'],$definition['slug'],$definition['description'] ?: null,$definition['status'],$definition['identity_mode'],$actorId,$now,$templateId]);
            }
            $insert = $pdo->prepare('INSERT INTO dxf_template_versions (template_id,revision,display_title,display_description,descriptor_json,descriptor_sha256,docx_storage_name,docx_sha256,docx_size,source_pdf_storage_name,source_pdf_sha256,source_pdf_size,source_pdf_inline_validated,allow_source_view,allow_source_download,allow_docx_download,allow_print,allow_pdf,created_by,created_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?)');
            $insert->execute([$templateId,$revision,$definition['title'],$definition['description'] ?: null,$definition['descriptor_json'],$definition['descriptor_sha256'],$docx['name'],$docx['sha256'],$docx['size'],$pdf['name'] ?? null,$pdf['sha256'] ?? null,$pdf['size'] ?? null,0,$definition['allow_source_download'] ? 1 : 0,$definition['allow_docx_download'] ? 1 : 0,$definition['allow_print'] ? 1 : 0,$definition['allow_pdf'] ? 1 : 0,$actorId,$now]);
            $versionId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE dxf_templates SET current_version_id=? WHERE id=?')->execute([$versionId,$templateId]);
            if ($item['action'] === 'create') {
                $pdo->prepare('INSERT INTO dxf_import_ledger (namespace_id,definition_id,release_id,template_id,template_version_id,projection_sha256,created_by,updated_by,created_at_utc,updated_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$prepared['namespace'],$definition['definition_id'],$prepared['release'],$templateId,$versionId,$definition['projection_sha256'],$actorId,$actorId,$now,$now]);
            } else {
                $pdo->prepare('UPDATE dxf_import_ledger SET release_id=?,template_version_id=?,projection_sha256=?,updated_by=?,updated_at_utc=? WHERE namespace_id=? AND definition_id=?')->execute([$prepared['release'],$versionId,$definition['projection_sha256'],$actorId,$now,$prepared['namespace'],$definition['definition_id']]);
            }
            dxf_core_audit($pdo,$actorId,$item['action'] === 'create' ? 'template.imported' : 'template.import_revised','docx-form-template',$templateId,$definition['title'],[],['namespace'=>$prepared['namespace'],'definition_id'=>$definition['definition_id'],'release_id'=>$prepared['release'],'projection_sha256'=>$definition['projection_sha256']]);
        }
        $commitAttempted = true;
        $pdo->commit();
    } catch (Throwable $error) {
        $rolledBack = false;
        if ($pdo->inTransaction()) { $pdo->rollBack(); $rolledBack = true; }
        foreach ($stages as $files) foreach ($files as $stage) dxf_discard_stage($stage);
        if ($rolledBack || !$commitAttempted) foreach ($published as $name) @unlink(dxf_storage_path($name));
        throw $error;
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
    return dxf_import_plan_prepared($pdo, $prepared);
}
