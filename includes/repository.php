<?php
declare(strict_types=1);

function dxf_schema_ready(PDO $pdo): bool
{
    try {
        $tables = ['dxf_templates','dxf_template_versions','dxf_submissions','dxf_otp_challenges','dxf_submission_events','dxf_rate_limits'];
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
        $statement->execute($tables);
        return (int)$statement->fetchColumn() === count($tables);
    } catch (Throwable $error) {
        error_log('[docx-form] schema check failed: ' . $error->getMessage());
        return false;
    }
}

function dxf_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function dxf_json(array $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dxf_settings_defaults(): array
{
    return [
        'otp_expiry_minutes'=>10,'otp_max_attempts'=>5,'otp_resend_cooldown_seconds'=>60,
        'rate_window_seconds'=>3600,'rate_max_requests'=>10,'receipt_expiry_hours'=>168,
        'otp_retention_days'=>7,'pending_retention_days'=>30,'rejected_retention_days'=>30,'approved_retention_days'=>365,
        'output_retention_days'=>90,'event_retention_days'=>365,'staging_retention_hours'=>24,
    ];
}

function dxf_settings_from_rows(array $rows): array
{
    $values = [];
    foreach ($rows as $row) if (is_array($row) && is_string($row['key'] ?? null)) $values[(string)$row['key']] = $row['value'] ?? null;
    $defaults = dxf_settings_defaults();
    $bounds = [
        'otp_expiry_minutes'=>[5,30],'otp_max_attempts'=>[3,10],'otp_resend_cooldown_seconds'=>[30,3600],
        'rate_window_seconds'=>[60,86400],'rate_max_requests'=>[1,100],'receipt_expiry_hours'=>[1,8760],
        'otp_retention_days'=>[1,3650],'pending_retention_days'=>[1,3650],'rejected_retention_days'=>[1,3650],'approved_retention_days'=>[1,3650],'output_retention_days'=>[1,3650],
        'event_retention_days'=>[7,3650],'staging_retention_hours'=>[1,168],
    ];
    $settings = [];
    foreach ($defaults as $name => $default) $settings[$name] = dxf_bounded_int($values['dxf_'.$name] ?? $default, $bounds[$name][0], $bounds[$name][1], $default);
    return $settings;
}

function dxf_settings(PDO $pdo): array
{
    $defaults = dxf_settings_defaults();
    if (!function_exists('settings_get')) return $defaults;
    $rows = [];
    foreach ($defaults as $name => $default) $rows[] = ['key'=>'dxf_'.$name,'value'=>settings_get($pdo, 'dxf_'.$name, (string)$default)];
    return dxf_settings_from_rows($rows);
}

function dxf_public_template(PDO $pdo, string $slug): ?array
{
    $slug = dxf_slug($slug);
    if ($slug === null) return null;
    $statement = $pdo->prepare("SELECT t.id,t.slug,t.identity_mode,t.current_version_id,v.display_title AS title,v.display_description AS description,v.descriptor_json,v.descriptor_sha256,v.docx_storage_name,v.docx_sha256,v.source_pdf_storage_name,v.source_pdf_sha256,v.source_pdf_size,v.source_pdf_inline_validated,v.allow_source_view,v.allow_source_download,v.allow_docx_download,v.allow_print,v.allow_pdf FROM dxf_templates t JOIN dxf_template_versions v ON v.id=t.current_version_id AND v.template_id=t.id WHERE t.slug=? AND t.status='published' LIMIT 1");
    $statement->execute([$slug]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    try {
        $descriptor = json_decode((string)$row['descriptor_json'], true, 32, JSON_THROW_ON_ERROR);
        $row['descriptor'] = dxf_descriptor_validate(is_array($descriptor) ? $descriptor : []);
        if (!hash_equals((string)$row['descriptor_sha256'], hash('sha256', dxf_descriptor_json($row['descriptor'])))) return null;
        $docxPath = dxf_storage_path((string)$row['docx_storage_name']);
        if (!hash_equals((string)$row['docx_sha256'], hash_file('sha256', $docxPath))) return null;
    } catch (Throwable) { return null; }
    return $row;
}

function dxf_receipt_submission(PDO $pdo, string $publicId, string $token, bool $lock = false): ?array
{
    $token = dxf_token($token);
    if ($token === null || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $publicId) !== 1) return null;
    $statement = $pdo->prepare('SELECT s.*,v.display_title AS title,v.display_description AS description,v.descriptor_json,v.descriptor_sha256,v.allow_docx_download,v.allow_print,v.allow_pdf FROM dxf_submissions s JOIN dxf_template_versions v ON v.id=s.template_version_id AND v.template_id=s.template_id WHERE s.public_id=? AND s.receipt_token_hash=? AND s.receipt_revoked_at_utc IS NULL AND s.receipt_expires_at_utc>? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$publicId,hash('sha256', $token),dxf_now_utc()]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    try {
        $row['descriptor'] = dxf_descriptor_validate((array)json_decode((string)$row['descriptor_json'], true, 32, JSON_THROW_ON_ERROR));
        $row['values'] = (array)json_decode((string)$row['values_json'], true, 32, JSON_THROW_ON_ERROR);
        $row['identity'] = (array)json_decode((string)$row['identity_json'], true, 16, JSON_THROW_ON_ERROR);
        if (!hash_equals((string)$row['descriptor_sha256'], hash('sha256', dxf_descriptor_json($row['descriptor'])))
            || !hash_equals((string)$row['values_sha256'], hash('sha256', dxf_values_json($row['descriptor'], $row['values'])))
            || !hash_equals((string)$row['identity_sha256'], hash('sha256', dxf_identity_json($row['identity'])))) return null;
    } catch (Throwable) { return null; }
    return $row;
}

function dxf_lock_public_template(PDO $pdo, array $template): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Public template locking requires a transaction.');
    $statement = $pdo->prepare('SELECT status,current_version_id,identity_mode FROM dxf_templates WHERE id=? LIMIT 1 FOR UPDATE');
    $statement->execute([(int)$template['id']]);
    $locked = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($locked) || $locked['status'] !== 'published' || (int)$locked['current_version_id'] !== (int)$template['current_version_id'] || (string)$locked['identity_mode'] !== (string)$template['identity_mode']) throw new DomainException('The document form changed. Reload and try again.');
}

function dxf_request_hashes(): array
{
    $secret = getenv('SESSION_SECRET') ?: '';
    if (strlen($secret) < 32) throw new RuntimeException('SESSION_SECRET is required for privacy-safe event hashing.');
    $ip = dxf_scalar($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ua = substr(dxf_scalar($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1024);
    return [hash_hmac('sha256', 'dxf:ip:' . $ip, $secret), hash_hmac('sha256', 'dxf:ua:' . $ua, $secret)];
}

function dxf_event(PDO $pdo, string $event, int $templateId, int $versionId, ?int $submissionId = null, ?string $challengePublicId = null, array $metadata = [], ?int $actorId = null, ?string $dedupeScope = null): void
{
    if (!$pdo->inTransaction()) throw new LogicException('DOCX Form event writes require a transaction.');
    $allowed = ['otp_requested','otp_delivery_failed','otp_verified','submission_created','submission_reviewed','submission_approved','submission_rejected','receipt_revoked','docx_generated','docx_download_started','source_view_started','source_download_started','print_view_started','pdf_generated','pdf_download_started'];
    if (!in_array($event, $allowed, true)) throw new InvalidArgumentException('Unknown DOCX Form event.');
    if (strlen(dxf_json($metadata)) > 4096) throw new InvalidArgumentException('Event metadata is too large.');
    [$ipHash, $uaHash] = dxf_request_hashes();
    $dedupeKey = $dedupeScope === null ? null : hash('sha256', implode(':', ['dxf-event',$event,(string)$templateId,(string)($submissionId ?? 0),$ipHash,$dedupeScope]));
    $statement = $pdo->prepare(($dedupeKey === null ? 'INSERT' : 'INSERT IGNORE') . ' INTO dxf_submission_events (submission_id,template_id,template_version_id,challenge_public_id,event_key,actor_user_id,ip_hash,user_agent_hash,metadata_json,dedupe_key,created_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $statement->execute([$submissionId,$templateId,$versionId,$challengePublicId,$event,$actorId,$ipHash,$uaHash,dxf_json($metadata),$dedupeKey,dxf_now_utc()]);
}

function dxf_core_audit(PDO $pdo, int $actorId, string $event, string $type, int $id, string $label, array $changes = [], array $metadata = []): void
{
    if (!function_exists('audit_log_record')) throw new RuntimeException('Core audit logging is unavailable.');
    audit_log_record($pdo, ['provider'=>'docx-form','event_key'=>$event,'actor_user_id'=>$actorId,'resource_type'=>$type,'resource_id'=>(string)$id,'resource_label'=>$label,'source'=>'plugin.docx-form.admin','changes'=>$changes,'metadata'=>$metadata]);
}

function dxf_rate_limit(PDO $pdo, string $action, int $limit, int $window): bool
{
    [$subject] = dxf_request_hashes();
    return dxf_rate_limit_subject($pdo, $subject, $action, $limit, $window);
}

function dxf_rate_limit_value(PDO $pdo, string $value, string $action, int $limit, int $window): bool
{
    $secret = getenv('SESSION_SECRET') ?: '';
    if (strlen($secret) < 32) return false;
    return dxf_rate_limit_subject($pdo, hash_hmac('sha256', 'dxf:rate:' . $value, $secret), $action, $limit, $window);
}

function dxf_rate_limit_subject(PDO $pdo, string $subject, string $action, int $limit, int $window): bool
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $subject) !== 1 || preg_match('/\A[a-z0-9_.:-]{1,80}\z/D', $action) !== 1 || $limit < 1 || $window < 1) return false;
    $bucket = intdiv(time(), $window);
    $expires = gmdate('Y-m-d H:i:s', ($bucket + 2) * $window);
    try {
        $statement = $pdo->prepare('INSERT INTO dxf_rate_limits (subject_hash,action_key,bucket_index,request_count,expires_at_utc) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE request_count=request_count+1,expires_at_utc=VALUES(expires_at_utc)');
        $statement->execute([$subject,$action,$bucket,$expires]);
        $query = $pdo->prepare('SELECT request_count FROM dxf_rate_limits WHERE subject_hash=? AND action_key=? AND bucket_index=? LIMIT 1');
        $query->execute([$subject,$action,$bucket]);
        return (int)$query->fetchColumn() <= $limit;
    } catch (Throwable $error) {
        error_log('[docx-form] rate limit failed closed: ' . $error->getMessage());
        return false;
    }
}

function dxf_cleanup_retention(PDO $pdo, array $settings): void
{
    $now = time();
    try {
        $pdo->prepare('DELETE FROM dxf_rate_limits WHERE expires_at_utc<? LIMIT 100')->execute([dxf_now_utc()]);
        $pdo->prepare('DELETE FROM dxf_otp_challenges WHERE expires_at_utc<? LIMIT 100')->execute([gmdate('Y-m-d H:i:s', $now - (int)$settings['otp_retention_days'] * 86400)]);
        $pdo->prepare('DELETE FROM dxf_submission_events WHERE created_at_utc<? LIMIT 100')->execute([gmdate('Y-m-d H:i:s', $now - (int)$settings['event_retention_days'] * 86400)]);
        foreach (['pending'=>'pending_retention_days','rejected'=>'rejected_retention_days','approved'=>'approved_retention_days'] as $status => $setting) {
            $query = $pdo->prepare('SELECT id,output_docx_storage_name,output_pdf_storage_name FROM dxf_submissions WHERE status=? AND updated_at_utc<? ORDER BY id LIMIT 25');
            $query->execute([$status,gmdate('Y-m-d H:i:s', $now - (int)$settings[$setting] * 86400)]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT status FROM dxf_submissions WHERE id=? LIMIT 1 FOR UPDATE');
                $lock->execute([(int)$row['id']]);
                if ($lock->fetchColumn() !== $status) { $pdo->rollBack(); continue; }
                $pdo->prepare('DELETE FROM dxf_submission_events WHERE submission_id=? LIMIT 100')->execute([(int)$row['id']]);
                $remaining = $pdo->prepare('SELECT 1 FROM dxf_submission_events WHERE submission_id=? LIMIT 1');
                $remaining->execute([(int)$row['id']]);
                $deleted = false;
                if (!$remaining->fetchColumn()) { $delete=$pdo->prepare('DELETE FROM dxf_submissions WHERE id=? AND status=?'); $delete->execute([(int)$row['id'],$status]); $deleted=$delete->rowCount()===1; }
                $pdo->commit();
                if ($deleted) foreach (['output_docx_storage_name','output_pdf_storage_name'] as $column) if (is_string($row[$column]) && $row[$column] !== '') { try { @unlink(dxf_storage_path($row[$column])); } catch (Throwable) {} }
            }
        }
        $cutoff = gmdate('Y-m-d H:i:s', $now - (int)$settings['output_retention_days'] * 86400);
        $query = $pdo->prepare("SELECT id,output_docx_storage_name,output_pdf_storage_name FROM dxf_submissions WHERE status='approved' AND output_generated_at_utc<? AND (output_docx_storage_name IS NOT NULL OR output_pdf_storage_name IS NOT NULL) ORDER BY id LIMIT 25");
        $query->execute([$cutoff]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $update = $pdo->prepare("UPDATE dxf_submissions SET output_docx_storage_name=NULL,output_docx_sha256=NULL,output_pdf_storage_name=NULL,output_pdf_sha256=NULL WHERE id=? AND status='approved' AND output_generated_at_utc<?");
            $update->execute([(int)$row['id'],$cutoff]);
            if ($update->rowCount() === 1) foreach (['output_docx_storage_name','output_pdf_storage_name'] as $column) if (is_string($row[$column]) && $row[$column] !== '') { try { @unlink(dxf_storage_path($row[$column])); } catch (Throwable) {} }
        }
        $stage = dxf_staging_root(); $stageCutoff = $now - (int)$settings['staging_retention_hours'] * 3600; $seen = 0;
        foreach (new DirectoryIterator($stage) as $item) { if ($seen++ >= 100) break; if ($item->isFile() && !$item->isLink() && $item->getMTime() < $stageCutoff) @unlink($item->getPathname()); }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[docx-form] retention cleanup failed: ' . $error->getMessage());
    }
}

function dxf_current_actor_id(): ?int
{
    if (!function_exists('current_user_id') || session_status() !== PHP_SESSION_ACTIVE) return null;
    $id = (int)current_user_id();
    return $id > 0 ? $id : null;
}
