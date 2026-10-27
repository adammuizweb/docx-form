<?php
declare(strict_types=1);

const DXF_PUBLIC_CSRF_TTL = 7200;

function dxf_capture_resolved_path(mixed $path): mixed
{
    if (is_string($path)) $GLOBALS['dxf_resolved_path'] = $path;
    else unset($GLOBALS['dxf_resolved_path']);
    return $path;
}

function dxf_public_path_segments(?string $routerPath = null): ?array
{
    $path = $routerPath ?? ($GLOBALS['dxf_resolved_path'] ?? null);
    if (!is_string($path) || strlen($path) > 1000 || preg_match('/%2f|%5c/i', $path)) return null;
    $path = parse_url($path, PHP_URL_PATH);
    if (!is_string($path)) return null;
    $path = trim(rawurldecode($path), '/');
    if ($path === 'document-forms') return [];
    if (!str_starts_with($path, 'document-forms/')) return null;
    $suffix = substr($path, strlen('document-forms/'));
    if ($suffix === '' || str_contains($suffix, '//') || str_contains($suffix, '\\')) return null;
    $segments = explode('/', $suffix);
    foreach ($segments as $segment) if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 100) return null;
    return $segments;
}

function dxf_public_error(int $status, string $message): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=UTF-8');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo dxf_text($message, 500, false);
    exit;
}

function dxf_public_redirect(string $path): never
{
    if (!str_starts_with($path, '/document-forms')) throw new InvalidArgumentException('Unsafe redirect.');
    header('Location: ' . $path, true, 303);
    exit;
}

function dxf_require_public_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') dxf_public_error(405, dxf_t('Method not allowed.'));
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) dxf_public_error(413, dxf_t('The request is too large.'));
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    dxf_public_security_required();
    try { $valid = stateless_csrf_check($token, DXF_PUBLIC_CSRF_TTL); }
    catch (Throwable) {
        error_log('[docx-form] public CSRF verification is unavailable.');
        dxf_public_error(503, dxf_t('Document form security is temporarily unavailable.'));
    }
    if (!$valid) dxf_public_error(403, dxf_t('The security token is invalid or expired.'));
}

function dxf_public_security_required(): void
{
    $secret = getenv('SESSION_SECRET') ?: '';
    if (strlen($secret) < 32 || !function_exists('stateless_csrf_token') || !function_exists('stateless_csrf_check')) {
        dxf_public_error(503, dxf_t('Document form security is temporarily unavailable.'));
    }
}

function dxf_public_csrf_token(): string
{
    dxf_public_security_required();
    try { return stateless_csrf_token(); }
    catch (Throwable) {
        error_log('[docx-form] public CSRF token generation is unavailable.');
        dxf_public_error(503, dxf_t('Document form security is temporarily unavailable.'));
    }
}

function dxf_receipt_token(int $templateId, string $idempotency): string
{
    $secret = getenv('SESSION_SECRET') ?: '';
    if (strlen($secret) < 32) throw new RuntimeException('SESSION_SECRET is unavailable.');
    return hash_hmac('sha256', 'dxf:receipt:' . $templateId . ':' . $idempotency, $secret);
}

function dxf_cookie_name(string $purpose, int|string $id): string
{
    return 'dxf_' . $purpose . '_' . substr(hash('sha256', (string)$id), 0, 16);
}

function dxf_set_capability_cookie(string $name, string $token, string $path, int $expires): void
{
    if (!setcookie($name, $token, ['expires'=>$expires,'path'=>$path,'secure'=>true,'httponly'=>true,'samesite'=>'Strict'])) throw new RuntimeException('Capability cookie could not be set.');
}

function dxf_capability_cookie(string $name): ?string
{
    return dxf_token(is_string($_COOKIE[$name] ?? null) ? $_COOKIE[$name] : '');
}

function dxf_public_rate(PDO $pdo, string $action, int $multiplier = 1): void
{
    $settings = dxf_settings($pdo);
    if (!dxf_rate_limit($pdo, $action, $settings['rate_max_requests'] * $multiplier, $settings['rate_window_seconds'])) dxf_public_error(429, dxf_t('Too many requests. Please try again later.'));
}

function dxf_public_document(string $content, string $title, string $description = ''): never
{
    header('Cache-Control: no-store, private, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('Content-Type: text/html; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    $locale = function_exists('get_locale') ? str_replace('_', '-', (string)get_locale()) : 'en';
    if (preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?\z/D', $locale) !== 1) $locale = 'en';
    echo '<!doctype html><html lang="'.dxf_h($locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>'.dxf_h($title).'</title>';
    if ($description !== '') echo '<meta name="description" content="'.dxf_h($description).'">';
    echo '<link rel="stylesheet" href="/static/plugins/docx-form/frontend.css?v='.rawurlencode(DXF_VERSION).'"></head><body>'.$content.'</body></html>';
    exit;
}

function dxf_public_list(PDO $pdo): never
{
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'HEAD'], true)) dxf_public_error(405, dxf_t('Method not allowed.'));
    dxf_public_rate($pdo, 'list', 10);
    $rows = $pdo->query("SELECT v.display_title AS title,t.slug,v.display_description AS description FROM dxf_templates t JOIN dxf_template_versions v ON v.id=t.current_version_id AND v.template_id=t.id WHERE t.status='published' ORDER BY v.display_title,t.id LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $html = '<main class="dxf-shell"><header class="dxf-hero"><span>' . dxf_h(dxf_t('Document services')) . '</span><h1>' . dxf_h(dxf_t('Document forms')) . '</h1><p>' . dxf_h(dxf_t('Choose a published form to prepare a document submission.')) . '</p></header><div class="dxf-card-grid">';
    foreach ($rows as $row) $html .= '<article class="dxf-card"><h2>' . dxf_h($row['title']) . '</h2><p>' . dxf_h($row['description']) . '</p><a href="/document-forms/' . rawurlencode((string)$row['slug']) . '/">' . dxf_h(dxf_t('Open form')) . '</a></article>';
    if ($rows === []) $html .= '<p class="dxf-empty">' . dxf_h(dxf_t('No document forms are currently available.')) . '</p>';
    $html .= '</div></main>';
    dxf_public_document($html, dxf_t('Document forms'));
}

function dxf_challenge_state(PDO $pdo, int $templateId, int $versionId, string $token): ?array
{
    $token = dxf_token($token);
    if ($token === null) return null;
    $statement = $pdo->prepare('SELECT id,public_id,email,verified_at_utc,used_at_utc,invalidated_at_utc,expires_at_utc,attempt_count,max_attempts FROM dxf_otp_challenges WHERE template_id=? AND template_version_id=? AND token_hash=? LIMIT 1');
    $statement->execute([$templateId,$versionId,hash('sha256', $token)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['used_at_utc'] !== null || $row['invalidated_at_utc'] !== null || (string)$row['expires_at_utc'] <= dxf_now_utc()) return null;
    return $row;
}

function dxf_public_form(PDO $pdo, array $template): never
{
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'HEAD'], true)) dxf_public_error(405, dxf_t('Method not allowed.'));
    dxf_public_rate($pdo, 'form:' . (int)$template['id'], 10);
    $descriptor = $template['descriptor'];
    $challengeToken = dxf_capability_cookie(dxf_cookie_name('otp', (int)$template['id'])) ?? '';
    $challenge = $challengeToken !== '' ? dxf_challenge_state($pdo, (int)$template['id'], (int)$template['current_version_id'], $challengeToken) : null;
    $verified = is_array($challenge) && $challenge['verified_at_utc'] !== null;
    $csrf = dxf_public_csrf_token();
    $html = '<main class="dxf-shell"><a class="dxf-back" href="/document-forms/">' . dxf_h(dxf_t('All document forms')) . '</a><header class="dxf-hero"><span>' . dxf_h(dxf_t('Document form')) . '</span><h1>' . dxf_h($template['title']) . '</h1><p>' . dxf_h($template['description']) . '</p></header>';
    if ((string)$template['identity_mode'] === 'email_otp') {
        $html .= '<section class="dxf-identity"><h2>' . dxf_h(dxf_t('Email verification')) . '</h2>';
        if (!$verified) {
            $html .= '<form method="post" action="/document-forms/' . rawurlencode((string)$template['slug']) . '/otp/request/"><input type="hidden" name="csrf_token" value="' . dxf_h($csrf) . '"><label>' . dxf_h(dxf_t('Email address')) . '<input type="email" name="email" maxlength="254" required autocomplete="email"></label><button type="submit">' . dxf_h(dxf_t('Send verification code')) . '</button></form>';
            if ($challenge !== null) $html .= '<form method="post" action="/document-forms/' . rawurlencode((string)$template['slug']) . '/otp/verify/"><input type="hidden" name="csrf_token" value="' . dxf_h($csrf) . '"><label>' . dxf_h(dxf_t('Verification code')) . '<input inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="code" required autocomplete="one-time-code"></label><button type="submit">' . dxf_h(dxf_t('Verify email')) . '</button></form>';
        } else {
            $html .= '<p class="dxf-success" role="status">' . dxf_h(dxf_t('Email verified. Complete and submit the form below.')) . '</p>';
        }
        $html .= '</section>';
    } else {
        $html .= '<p class="dxf-notice" role="note">' . dxf_h(dxf_t('Identity details are self-declared and are not verified.')) . '</p>';
    }
    $disabled = (string)$template['identity_mode'] === 'email_otp' && !$verified;
    $html .= '<div class="dxf-workspace"><form class="dxf-form" method="post" action="/document-forms/' . rawurlencode((string)$template['slug']) . '/submit/" data-dxf-form data-preview-empty="' . dxf_h(dxf_t('Not provided')) . '" data-preview-updated="' . dxf_h(dxf_t('Preview updated')) . '"><input type="hidden" name="csrf_token" value="' . dxf_h($csrf) . '"><input type="hidden" name="idempotency" value="' . bin2hex(random_bytes(32)) . '"><fieldset' . ($disabled ? ' disabled' : '') . '><legend class="dxf-sr-only">' . dxf_h(dxf_t('Document details')) . '</legend>';
    foreach ($descriptor['fields'] as $index => $field) {
        $id = 'dxf-field-' . $index;
        $required = $field['required'] ? ' required' : '';
        $helpId = $field['help'] !== '' ? $id . '-help' : '';
        $described = $helpId !== '' ? ' aria-describedby="' . $helpId . '"' : '';
        $html .= '<div class="dxf-field" data-preview-field data-preview-label="' . dxf_h($field['label']) . '"><input type="hidden" name="fields[' . $index . '][key]" value="' . dxf_h($field['key']) . '">';
        if ($field['type'] === 'checkbox') {
            $html .= '<label class="dxf-check"><input id="' . $id . '" type="checkbox" name="fields[' . $index . '][value]" value="1"' . $required . $described . '> <span>' . dxf_h($field['label']) . '</span></label>';
        } elseif (in_array($field['type'], ['select', 'radio'], true)) {
            $html .= '<span class="dxf-label" id="' . $id . '-label">' . dxf_h($field['label']) . ($field['required'] ? ' *' : '') . '</span>';
            if ($field['type'] === 'select') {
                $html .= '<select id="' . $id . '" name="fields[' . $index . '][value]"' . $required . $described . '><option value="">' . dxf_h(dxf_t('Choose an option')) . '</option>';
                foreach ($field['options'] as $option) $html .= '<option value="' . dxf_h($option['value']) . '">' . dxf_h($option['label']) . '</option>';
                $html .= '</select>';
            } else {
                $html .= '<div role="radiogroup" aria-labelledby="' . $id . '-label"' . $described . '>';
                foreach ($field['options'] as $optionIndex => $option) $html .= '<label class="dxf-radio"><input type="radio" name="fields[' . $index . '][value]" value="' . dxf_h($option['value']) . '"' . ($field['required'] && $optionIndex === 0 ? ' required' : '') . '> ' . dxf_h($option['label']) . '</label>';
                $html .= '</div>';
            }
        } else {
            $html .= '<label for="' . $id . '">' . dxf_h($field['label']) . ($field['required'] ? ' *' : '') . '</label>';
            if ($field['type'] === 'textarea') $html .= '<textarea id="' . $id . '" name="fields[' . $index . '][value]" maxlength="' . (int)$field['max_length'] . '"' . $required . $described . '></textarea>';
            else $html .= '<input id="' . $id . '" type="' . dxf_h($field['type']) . '" name="fields[' . $index . '][value]" maxlength="' . (int)$field['max_length'] . '"' . $required . $described . '>';
        }
        if ($helpId !== '') $html .= '<small id="' . $helpId . '">' . dxf_h($field['help']) . '</small>';
        $html .= '</div>';
    }
    $html .= '<button class="dxf-submit" type="submit">' . dxf_h(dxf_t('Submit for review')) . '</button></fieldset></form><aside class="dxf-preview" aria-labelledby="dxf-preview-title"><span>' . dxf_h(dxf_t('Non-authoritative preview')) . '</span><h2 id="dxf-preview-title">' . dxf_h($descriptor['print']['title'] ?: $template['title']) . '</h2><p>' . dxf_h(dxf_t('The final document is generated by the server after approval.')) . '</p><dl>';
    foreach ($descriptor['fields'] as $index => $field) $html .= '<div><dt>' . dxf_h($field['label']) . '</dt><dd data-preview-output="' . $index . '">' . dxf_h(dxf_t('Not provided')) . '</dd></div>';
    $html .= '</dl><p class="dxf-preview-status" role="status" aria-live="polite" data-preview-status></p></aside></div>';
    if ($template['source_pdf_storage_name'] && ((int)$template['allow_source_view'] || (int)$template['allow_source_download'])) {
        $html .= '<nav class="dxf-source" aria-label="' . dxf_h(dxf_t('Source PDF')) . '">';
        if ((int)$template['allow_source_view'] && (int)$template['source_pdf_inline_validated'] === 1 && dxf_pdf_inline_available()) $html .= '<a href="/document-forms/' . rawurlencode((string)$template['slug']) . '/source/view/">' . dxf_h(dxf_t('View source PDF')) . '</a>';
        if ((int)$template['allow_source_download']) $html .= '<a href="/document-forms/' . rawurlencode((string)$template['slug']) . '/source/download/">' . dxf_h(dxf_t('Download source PDF')) . '</a>';
        $html .= '</nav>';
    }
    $html .= '<script src="/static/plugins/docx-form/preview.js?v=' . rawurlencode(DXF_VERSION) . '" defer></script></main>';
    dxf_public_document($html, (string)$template['title'], (string)$template['description']);
}

function dxf_public_otp_request(PDO $pdo, array $template): never
{
    dxf_require_public_post();
    if ((string)$template['identity_mode'] !== 'email_otp' || !dxf_otp_sender_available()) dxf_public_error(503, dxf_t('Email verification is currently unavailable.'));
    $rawEmail = $_POST['email'] ?? null;
    if (!is_string($rawEmail) || strlen($rawEmail) > 254 || preg_match('//u', $rawEmail) !== 1) dxf_public_error(422, dxf_t('Enter a valid email address.'));
    $email = strtolower(trim($rawEmail));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email)) dxf_public_error(422, dxf_t('Enter a valid email address.'));
    $settings = dxf_settings($pdo);
    if (!dxf_rate_limit($pdo, 'otp:' . (int)$template['id'], $settings['rate_max_requests'], $settings['rate_window_seconds'])
        || !dxf_rate_limit_value($pdo, $email, 'otp-email:' . (int)$template['id'], max(2, intdiv($settings['rate_max_requests'], 2)), $settings['rate_window_seconds'])) dxf_public_error(429, dxf_t('Too many requests. Please try again later.'));
    $code = (string)random_int(100000, 999999);
    $challengeToken = bin2hex(random_bytes(32));
    $publicId = dxf_uuid();
    $expires = gmdate('Y-m-d H:i:s', time() + $settings['otp_expiry_minutes'] * 60);
    try {
        $pdo->beginTransaction();
        dxf_lock_public_template($pdo, $template);
        $cooldown = $pdo->prepare('SELECT created_at_utc FROM dxf_otp_challenges WHERE template_id=? AND email=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $cooldown->execute([(int)$template['id'],$email]);
        $last = $cooldown->fetchColumn();
        if (is_string($last) && $last > gmdate('Y-m-d H:i:s', time() - $settings['otp_resend_cooldown_seconds'])) throw new DomainException('Wait before requesting another verification code.');
        $pdo->prepare('UPDATE dxf_otp_challenges SET invalidated_at_utc=? WHERE template_id=? AND email=? AND used_at_utc IS NULL AND invalidated_at_utc IS NULL')->execute([dxf_now_utc(),(int)$template['id'],$email]);
        $statement = $pdo->prepare('INSERT INTO dxf_otp_challenges (public_id,template_id,template_version_id,token_hash,email,code_hash,max_attempts,expires_at_utc,created_at_utc) VALUES (?,?,?,?,?,?,?,?,?)');
        $statement->execute([$publicId,(int)$template['id'],(int)$template['current_version_id'],hash('sha256',$challengeToken),$email,password_hash($code,PASSWORD_DEFAULT),$settings['otp_max_attempts'],$expires,dxf_now_utc()]);
        dxf_event($pdo, 'otp_requested', (int)$template['id'], (int)$template['current_version_id'], null, $publicId, ['delivery' => 'email']);
        $pdo->commit();
        $sent = false;
        try { $sent = dxf_send_otp($email, $code, ['template_id'=>(int)$template['id'],'template_title'=>(string)$template['title'],'expires_at_utc'=>$expires]); }
        catch (Throwable) { $sent = false; }
        if (!$sent) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE dxf_otp_challenges SET invalidated_at_utc=? WHERE public_id=? AND invalidated_at_utc IS NULL')->execute([dxf_now_utc(),$publicId]);
            dxf_event($pdo, 'otp_delivery_failed', (int)$template['id'], (int)$template['current_version_id'], null, $publicId);
            $pdo->commit();
            throw new RuntimeException('OTP sender rejected delivery.');
        }
        dxf_set_capability_cookie(dxf_cookie_name('otp', (int)$template['id']), $challengeToken, '/document-forms/' . rawurlencode((string)$template['slug']), strtotime($expires . ' UTC'));
    } catch (DomainException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        dxf_public_error(429, dxf_t($error->getMessage()));
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[docx-form] OTP request or delivery failed.');
        dxf_public_error(503, dxf_t('The verification code could not be sent.'));
    }
    dxf_public_redirect('/document-forms/' . rawurlencode((string)$template['slug']) . '/');
}

function dxf_public_otp_verify(PDO $pdo, array $template): never
{
    dxf_require_public_post();
    if ((string)$template['identity_mode'] !== 'email_otp') dxf_public_error(404, dxf_t('Page not found.'));
    $token = dxf_capability_cookie(dxf_cookie_name('otp', (int)$template['id']));
    $code = dxf_scalar($_POST['code'] ?? '');
    if ($token === null || preg_match('/\A[0-9]{6}\z/D', $code) !== 1) dxf_public_error(422, dxf_t('Enter a valid verification code.'));
    $settings = dxf_settings($pdo);
    if (!dxf_rate_limit($pdo, 'verify:' . (int)$template['id'], $settings['rate_max_requests'] * 2, $settings['rate_window_seconds'])) dxf_public_error(429, dxf_t('Too many requests. Please try again later.'));
    try {
        $pdo->beginTransaction();
        dxf_lock_public_template($pdo, $template);
        $statement = $pdo->prepare('SELECT * FROM dxf_otp_challenges WHERE template_id=? AND token_hash=? LIMIT 1 FOR UPDATE');
        $statement->execute([(int)$template['id'],hash('sha256',$token)]);
        $challenge = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($challenge) || $challenge['used_at_utc'] !== null || $challenge['invalidated_at_utc'] !== null || (int)$challenge['template_version_id'] !== (int)$template['current_version_id'] || (string)$challenge['expires_at_utc'] <= dxf_now_utc() || (int)$challenge['attempt_count'] >= (int)$challenge['max_attempts']) throw new DomainException('The verification challenge is unavailable or expired.');
        if (!password_verify($code, (string)$challenge['code_hash'])) {
            $pdo->prepare('UPDATE dxf_otp_challenges SET attempt_count=attempt_count+1 WHERE id=?')->execute([(int)$challenge['id']]);
            $pdo->commit();
            dxf_public_error(422, dxf_t('The verification code is incorrect.'));
        }
        $pdo->prepare('UPDATE dxf_otp_challenges SET verified_at_utc=?,attempt_count=attempt_count+1 WHERE id=? AND verified_at_utc IS NULL')->execute([dxf_now_utc(),(int)$challenge['id']]);
        dxf_event($pdo, 'otp_verified', (int)$template['id'], (int)$template['current_version_id'], null, (string)$challenge['public_id'], ['identity' => 'email']);
        $pdo->commit();
    } catch (DomainException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        dxf_public_error(422, dxf_t($error->getMessage()));
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[docx-form] OTP verification failed: ' . $error->getMessage());
        dxf_public_error(503, dxf_t('Email verification could not be completed.'));
    }
    dxf_public_redirect('/document-forms/' . rawurlencode((string)$template['slug']) . '/');
}

function dxf_posted_values(array $descriptor): array
{
    $rows = $_POST['fields'] ?? null;
    if (!is_array($rows) || count($rows) > 100) throw new DomainException('Submitted fields are invalid.');
    $values = [];
    foreach (array_slice($rows, 0, 100) as $row) {
        if (!is_array($row) || count($row) > 2 || !is_string($row['key'] ?? null)) throw new DomainException('Submitted fields are invalid.');
        $key = $row['key'];
        if (isset($values[$key])) throw new DomainException('A submitted field is duplicated.');
        $values[$key] = $row['value'] ?? null;
    }
    return $values;
}

function dxf_public_submit(PDO $pdo, array $template): never
{
    dxf_require_public_post();
    $settings = dxf_settings($pdo);
    if (!dxf_rate_limit($pdo, 'submit:' . (int)$template['id'], $settings['rate_max_requests'], $settings['rate_window_seconds'])) dxf_public_error(429, dxf_t('Too many requests. Please try again later.'));
    $idempotency = dxf_token($_POST['idempotency'] ?? '');
    if ($idempotency === null) dxf_public_error(422, dxf_t('The submission key is invalid.'));
    $receipt = dxf_receipt_token((int)$template['id'], $idempotency);
    $idempotencyHash = hash('sha256', $idempotency);
    $challengeToken = dxf_capability_cookie(dxf_cookie_name('otp', (int)$template['id']));
    $verifiedEmail = null;
    $challengeId = null;
    $publicId = '';
    $receiptExpires = '';
    try {
        $rawValues = dxf_posted_values($template['descriptor']);
        $pdo->beginTransaction();
        dxf_lock_public_template($pdo, $template);
        $existing = $pdo->prepare('SELECT public_id,receipt_token_hash,receipt_expires_at_utc,receipt_revoked_at_utc FROM dxf_submissions WHERE template_id=? AND idempotency_hash=? LIMIT 1 FOR UPDATE');
        $existing->execute([(int)$template['id'],$idempotencyHash]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($existingRow)) {
            if (!hash_equals((string)$existingRow['receipt_token_hash'], hash('sha256', $receipt))) throw new RuntimeException('Idempotency receipt integrity failed.');
            if ($existingRow['receipt_revoked_at_utc'] !== null || (string)$existingRow['receipt_expires_at_utc'] <= dxf_now_utc()) throw new DomainException('This submission receipt has expired.');
            $publicId = (string)$existingRow['public_id'];
            $receiptExpires = (string)$existingRow['receipt_expires_at_utc'];
            $pdo->commit();
            dxf_set_capability_cookie(dxf_cookie_name('receipt', $publicId), $receipt, '/document-forms/receipt/' . $publicId, strtotime($receiptExpires . ' UTC'));
            dxf_public_redirect('/document-forms/receipt/' . $publicId . '/');
        }
        if ((string)$template['identity_mode'] === 'email_otp') {
            if ($challengeToken === null) throw new DomainException('A verified email identity is required.');
            $challengeStatement = $pdo->prepare('SELECT * FROM dxf_otp_challenges WHERE template_id=? AND template_version_id=? AND token_hash=? LIMIT 1 FOR UPDATE');
            $challengeStatement->execute([(int)$template['id'],(int)$template['current_version_id'],hash('sha256',$challengeToken)]);
            $challenge = $challengeStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($challenge) || $challenge['verified_at_utc'] === null || $challenge['used_at_utc'] !== null || $challenge['invalidated_at_utc'] !== null || (string)$challenge['expires_at_utc'] <= dxf_now_utc()) throw new DomainException('A verified email identity is required.');
            $verifiedEmail = (string)$challenge['email'];
            $challengeId = (int)$challenge['id'];
        }
        $validated = dxf_validate_values($template['descriptor'], $rawValues, (string)$template['identity_mode'], $verifiedEmail);
        $valuesJson = dxf_values_json($template['descriptor'], $validated['values']);
        $identityJson = dxf_identity_json($validated['identity']);
        if (strlen($valuesJson) > 131072) throw new DomainException('Submitted values exceed the allowed size.');
        $publicId = dxf_uuid();
        $now = dxf_now_utc();
        $receiptExpires = gmdate('Y-m-d H:i:s', time() + $settings['receipt_expiry_hours'] * 3600);
        $insert = $pdo->prepare("INSERT INTO dxf_submissions (public_id,template_id,template_version_id,identity_mode,identity_json,identity_sha256,values_json,values_sha256,idempotency_hash,receipt_token_hash,receipt_expires_at_utc,status,created_at_utc,updated_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)");
        $insert->execute([$publicId,(int)$template['id'],(int)$template['current_version_id'],(string)$template['identity_mode'],$identityJson,hash('sha256',$identityJson),$valuesJson,hash('sha256',$valuesJson),$idempotencyHash,hash('sha256',$receipt),$receiptExpires,$now,$now]);
        $submissionId = (int)$pdo->lastInsertId();
        if ($challengeId !== null) $pdo->prepare('UPDATE dxf_otp_challenges SET used_at_utc=? WHERE id=? AND used_at_utc IS NULL')->execute([$now,$challengeId]);
        dxf_event($pdo, 'submission_created', (int)$template['id'], (int)$template['current_version_id'], $submissionId, null, ['identity_mode'=>(string)$template['identity_mode']]);
        $pdo->commit();
    } catch (DomainException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        dxf_public_error(422, $error->getMessage());
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[docx-form] submission failed: ' . $error->getMessage());
        dxf_public_error(503, dxf_t('The submission could not be saved.'));
    }
    dxf_set_capability_cookie(dxf_cookie_name('receipt', $publicId), $receipt, '/document-forms/receipt/' . $publicId, strtotime($receiptExpires . ' UTC'));
    dxf_public_redirect('/document-forms/receipt/' . $publicId . '/');
}

function dxf_public_receipt_submission(PDO $pdo, string $publicId): ?array
{
    $token = dxf_capability_cookie(dxf_cookie_name('receipt', $publicId));
    return $token === null ? null : dxf_receipt_submission($pdo, $publicId, $token);
}

function dxf_public_receipt(PDO $pdo, string $publicId): never
{
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'HEAD'], true)) dxf_public_error(405, dxf_t('Method not allowed.'));
    dxf_public_rate($pdo, 'receipt', 10);
    $submission = dxf_public_receipt_submission($pdo, $publicId);
    if ($submission === null) dxf_public_error(404, dxf_t('Receipt not found.'));
    header('X-Robots-Tag: noindex, nofollow');
    $status = (string)$submission['status'];
    $html = '<main class="dxf-shell"><section class="dxf-receipt"><span>' . dxf_h(dxf_t('Submission receipt')) . '</span><h1>' . dxf_h($submission['title']) . '</h1><p><strong>' . dxf_h(dxf_t('Status')) . ':</strong> ' . dxf_h(dxf_t(ucfirst($status))) . '</p>';
    if ($status === 'pending') $html .= '<p>' . dxf_h(dxf_t('Your submission is awaiting review. Return with this browser before the receipt expires.')) . '</p>';
    elseif ($status === 'rejected') $html .= '<p>' . dxf_h(dxf_t('This submission was not approved.')) . '</p>';
    else {
        $html .= '<nav aria-label="' . dxf_h(dxf_t('Approved output')) . '">';
        if ((int)$submission['allow_docx_download'] && $submission['output_docx_storage_name']) $html .= '<a href="/document-forms/receipt/' . $publicId . '/docx/">' . dxf_h(dxf_t('Download DOCX')) . '</a>';
        if ((int)$submission['allow_pdf'] && $submission['output_pdf_storage_name']) $html .= '<a href="/document-forms/receipt/' . $publicId . '/pdf/">' . dxf_h(dxf_t('Download PDF')) . '</a>';
        if ((int)$submission['allow_print']) $html .= '<a href="/document-forms/receipt/' . $publicId . '/print/">' . dxf_h(dxf_t('Print approved submission')) . '</a>';
        $html .= '</nav>';
    }
    $html .= '</section></main>';
    dxf_public_document($html, dxf_t('Submission receipt'));
}

function dxf_log_retrieval(PDO $pdo, array $submission, string $event): void
{
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT status,receipt_expires_at_utc,receipt_revoked_at_utc FROM dxf_submissions WHERE id=? LIMIT 1 FOR UPDATE');
        $lock->execute([(int)$submission['id']]);
        $live = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($live) || $live['status'] !== 'approved' || $live['receipt_revoked_at_utc'] !== null || (string)$live['receipt_expires_at_utc'] <= dxf_now_utc()) throw new DomainException('Submission receipt is unavailable.');
        dxf_event($pdo, $event, (int)$submission['template_id'], (int)$submission['template_version_id'], (int)$submission['id'], null, [], dxf_current_actor_id(), gmdate('Y-m-d-H'));
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function dxf_public_output(PDO $pdo, string $publicId, string $kind): never
{
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    if (!in_array($method, ['GET','HEAD'], true)) dxf_public_error(405, dxf_t('Method not allowed.'));
    dxf_public_rate($pdo, 'output:' . $kind, 10);
    $submission = dxf_public_receipt_submission($pdo, $publicId);
    if ($submission === null || $submission['status'] !== 'approved') dxf_public_error(404, dxf_t('Approved output not found.'));
    if ($kind === 'docx' && (!(int)$submission['allow_docx_download'] || !$submission['output_docx_storage_name'])) dxf_public_error(404, dxf_t('Approved output not found.'));
    if ($kind === 'pdf' && (!(int)$submission['allow_pdf'] || !$submission['output_pdf_storage_name'])) dxf_public_error(404, dxf_t('Approved output not found.'));
    try {
        dxf_assert_file_integrity((string)$submission[$kind === 'docx' ? 'output_docx_storage_name' : 'output_pdf_storage_name'], (string)$submission[$kind === 'docx' ? 'output_docx_sha256' : 'output_pdf_sha256']);
    } catch (Throwable) { dxf_public_error(404, dxf_t('Approved output not found.')); }
    $event = $kind === 'docx' ? 'docx_download_started' : 'pdf_download_started';
    try { dxf_log_retrieval($pdo, $submission, $event); }
    catch (Throwable) { dxf_public_error(404, dxf_t('Approved output not found.')); }
    $filename = (string)$submission['descriptor']['document_filename'];
    try {
        if ($kind === 'docx') dxf_stream_private_descriptor(['name'=>$submission['output_docx_storage_name'],'sha256'=>$submission['output_docx_sha256']], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', dxf_safe_download_name($filename, 'docx'), 'attachment', $method === 'HEAD');
        dxf_stream_private_descriptor(['name'=>$submission['output_pdf_storage_name'],'sha256'=>$submission['output_pdf_sha256']], 'application/pdf', dxf_safe_download_name(pathinfo($filename, PATHINFO_FILENAME), 'pdf'), 'attachment', $method === 'HEAD');
    } catch (Throwable) { dxf_public_error(404, dxf_t('Approved output not found.')); }
}

function dxf_public_source(PDO $pdo, array $template, string $mode): never
{
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    if (!in_array($method, ['GET','HEAD'], true)) dxf_public_error(405, dxf_t('Method not allowed.'));
    dxf_public_rate($pdo, 'source:' . (int)$template['id'] . ':' . $mode, 10);
    if (!$template['source_pdf_storage_name'] || ($mode === 'view' ? !(int)$template['allow_source_view'] : !(int)$template['allow_source_download'])) dxf_public_error(404, dxf_t('Source PDF not found.'));
    if ($mode === 'view' && (!(int)$template['source_pdf_inline_validated'] || !dxf_pdf_inline_available())) dxf_public_error(404, dxf_t('Source PDF not found.'));
    try { dxf_assert_file_integrity((string)$template['source_pdf_storage_name'], (string)$template['source_pdf_sha256']); }
    catch (Throwable) { dxf_public_error(404, dxf_t('Source PDF not found.')); }
    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare("SELECT status,current_version_id FROM dxf_templates WHERE id=? LIMIT 1 FOR UPDATE");
        $check->execute([(int)$template['id']]);
        $live = $check->fetch(PDO::FETCH_ASSOC);
        if (!is_array($live) || $live['status'] !== 'published' || (int)$live['current_version_id'] !== (int)$template['current_version_id']) throw new DomainException('Source unavailable.');
        dxf_event($pdo, $mode === 'view' ? 'source_view_started' : 'source_download_started', (int)$template['id'], (int)$template['current_version_id'], null, null, [], dxf_current_actor_id(), gmdate('Y-m-d-H'));
        $pdo->commit();
    } catch (Throwable) { if ($pdo->inTransaction()) $pdo->rollBack(); dxf_public_error(404, dxf_t('Source PDF not found.')); }
    try { dxf_stream_private_descriptor(['name'=>$template['source_pdf_storage_name'],'sha256'=>$template['source_pdf_sha256'],'size'=>(int)$template['source_pdf_size']], 'application/pdf', dxf_safe_download_name((string)$template['slug'] . '-source', 'pdf'), $mode === 'view' ? 'inline' : 'attachment', $method === 'HEAD'); }
    catch (Throwable) { dxf_public_error(404, dxf_t('Source PDF not found.')); }
}

function dxf_public_print(PDO $pdo, string $publicId): never
{
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'HEAD'], true)) dxf_public_error(405, dxf_t('Method not allowed.'));
    dxf_public_rate($pdo, 'print', 10);
    $submission = dxf_public_receipt_submission($pdo, $publicId);
    if ($submission === null || $submission['status'] !== 'approved' || !(int)$submission['allow_print']) dxf_public_error(404, dxf_t('Approved print view not found.'));
    try { dxf_log_retrieval($pdo, $submission, 'print_view_started'); } catch (Throwable) { dxf_public_error(404, dxf_t('Approved print view not found.')); }
    $descriptor = $submission['descriptor'];
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    $locale = function_exists('get_locale') ? str_replace('_', '-', (string)get_locale()) : 'en';
    if (preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?\z/D', $locale) !== 1) $locale = 'en';
    echo '<!doctype html><html lang="' . dxf_h($locale) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . dxf_h($submission['title']) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;color:#111;margin:2rem auto;max-width:850px;padding:0 1rem}header{border-bottom:2px solid #111;margin-bottom:2rem}dl{display:grid;grid-template-columns:repeat(' . (int)$descriptor['print']['columns'] . ',minmax(0,1fr));gap:1rem}dl div{break-inside:avoid;border-bottom:1px solid #bbb;padding:.5rem 0}dt{font-weight:700}dd{margin:.25rem 0;white-space:pre-wrap}@media print{button{display:none}body{margin:0;max-width:none}}</style><script src="/static/plugins/docx-form/print.js?v=' . rawurlencode(DXF_VERSION) . '" defer></script></head><body><button type="button" data-dxf-print>' . dxf_h(dxf_t('Print')) . '</button><header><h1>' . dxf_h($descriptor['print']['title'] ?: $submission['title']) . '</h1>' . ($descriptor['print']['show_description'] ? '<p>' . dxf_h($submission['description']) . '</p>' : '') . '</header><dl>';
    foreach ($descriptor['fields'] as $field) echo '<div><dt>' . dxf_h($field['label']) . '</dt><dd>' . dxf_h($submission['values'][$field['key']] ?? '') . '</dd></div>';
    echo '</dl></body></html>';
    exit;
}

function dxf_public_dispatch(PDO $pdo, ?string $routerPath = null): void
{
    dxf_public_security_required();
    if (!dxf_schema_ready($pdo)) dxf_public_error(503, dxf_t('Document form storage is unavailable.'));
    if (random_int(1, 100) === 1) dxf_cleanup_retention($pdo, dxf_settings($pdo));
    $segments = dxf_public_path_segments($routerPath);
    if ($segments === null) dxf_public_error(404, dxf_t('Page not found.'));
    if ($segments === []) dxf_public_list($pdo);
    if (($segments[0] ?? '') === 'receipt') {
        $publicId = strtolower((string)($segments[1] ?? ''));
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $publicId) !== 1 || count($segments) > 3) dxf_public_error(404, dxf_t('Receipt not found.'));
        if (count($segments) === 2) dxf_public_receipt($pdo, $publicId);
        $action = $segments[2] ?? '';
        if (in_array($action, ['docx', 'pdf'], true)) dxf_public_output($pdo, $publicId, $action);
        if ($action === 'print') dxf_public_print($pdo, $publicId);
        dxf_public_error(404, dxf_t('Page not found.'));
    }
    $slug = dxf_slug($segments[0] ?? '');
    if ($slug === null) dxf_public_error(404, dxf_t('Page not found.'));
    dxf_public_rate($pdo, 'template-route', 10);
    $template = dxf_public_template($pdo, $slug);
    if ($template === null) dxf_public_error(404, dxf_t('Page not found.'));
    if (count($segments) === 1) dxf_public_form($pdo, $template);
    $action = implode('/', array_slice($segments, 1));
    if ($action === 'otp/request') dxf_public_otp_request($pdo, $template);
    if ($action === 'otp/verify') dxf_public_otp_verify($pdo, $template);
    if ($action === 'submit') dxf_public_submit($pdo, $template);
    if ($action === 'source/view') dxf_public_source($pdo, $template, 'view');
    if ($action === 'source/download') dxf_public_source($pdo, $template, 'download');
    dxf_public_error(404, dxf_t('Page not found.'));
}
