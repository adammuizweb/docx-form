<?php
declare(strict_types=1);

function dxf_register_otp_sender(callable $sender): bool
{
    if (isset($GLOBALS['dxf_otp_sender'])) return false;
    $GLOBALS['dxf_otp_sender'] = $sender;
    return true;
}

function dxf_otp_sender_available(): bool
{
    return is_callable($GLOBALS['dxf_otp_sender'] ?? null);
}

function dxf_send_otp(string $email, string $code, array $context): bool
{
    $sender = $GLOBALS['dxf_otp_sender'] ?? null;
    if (!is_callable($sender)) return false;
    $result = $sender($email, $code, [
        'template_id' => (int)($context['template_id'] ?? 0),
        'template_title' => dxf_text($context['template_title'] ?? '', 191),
        'expires_at_utc' => dxf_text($context['expires_at_utc'] ?? '', 30),
    ]);
    return $result === true;
}

function dxf_register_pdf_converter(callable $converter): bool
{
    if (isset($GLOBALS['dxf_pdf_converter'])) return false;
    $GLOBALS['dxf_pdf_converter'] = $converter;
    return true;
}

function dxf_pdf_converter_available(): bool
{
    return is_callable($GLOBALS['dxf_pdf_converter'] ?? null);
}

function dxf_register_pdf_validator(callable $validator): bool
{
    if (isset($GLOBALS['dxf_pdf_validator'])) return false;
    $GLOBALS['dxf_pdf_validator'] = $validator;
    return true;
}

function dxf_pdf_inline_available(): bool
{
    return is_callable($GLOBALS['dxf_pdf_validator'] ?? null);
}

function dxf_sanitize_pdf(string $sourcePath, string $destinationPath, array $context = []): array
{
    $validator = $GLOBALS['dxf_pdf_validator'] ?? null;
    if (!is_callable($validator)) throw new RuntimeException('No trusted PDF validator/sanitizer is registered.');
    if (file_exists($destinationPath) || is_link($destinationPath)) throw new RuntimeException('PDF validation stage already exists.');
    $result = $validator($sourcePath, $destinationPath, [
        'purpose' => dxf_text($context['purpose'] ?? 'source', 40),
        'template_id' => (int)($context['template_id'] ?? 0),
    ]);
    if ($result !== true || !is_file($destinationPath) || is_link($destinationPath)) throw new RuntimeException('The trusted PDF validator/sanitizer did not produce output.');
    dxf_validate_pdf($destinationPath);
    chmod($destinationPath, 0660);
    return ['sha256' => hash_file('sha256', $destinationPath), 'size' => filesize($destinationPath) ?: 0];
}

function dxf_convert_pdf(string $docxPath, string $pdfPath, array $context): array
{
    $converter = $GLOBALS['dxf_pdf_converter'] ?? null;
    if (!is_callable($converter)) throw new RuntimeException('No trusted PDF converter is registered.');
    if (file_exists($pdfPath) || is_link($pdfPath)) throw new RuntimeException('PDF output stage already exists.');
    $result = $converter($docxPath, $pdfPath, [
        'submission_id' => (int)($context['submission_id'] ?? 0),
        'document_filename' => dxf_safe_download_name((string)($context['document_filename'] ?? 'document.docx'), 'docx'),
    ]);
    if ($result !== true) throw new RuntimeException('The trusted PDF converter did not complete.');
    dxf_validate_pdf($pdfPath);
    chmod($pdfPath, 0660);
    return ['sha256' => hash_file('sha256', $pdfPath), 'size' => filesize($pdfPath) ?: 0];
}

function dxf_admin_assets(): void
{
    $page = trim(dxf_scalar($_GET['page'] ?? ''), '/');
    if ($page !== 'admin/tools/docx-form' && !str_starts_with($page, 'admin/tools/docx-form/')) return;
    echo '<link rel="stylesheet" href="/static/plugins/docx-form/admin.css?v=' . rawurlencode(DXF_VERSION) . '">' . PHP_EOL;
}

function dxf_register_shortcode(): bool
{
    if (isset($GLOBALS['_widget_shortcode_handlers']['docx_form'])) return false;
    register_widget_shortcode_handler('docx_form', static function (PDO $pdo, array $attrs): string {
        $slug = dxf_slug($attrs['slug'] ?? '');
        if ($slug === null) return '';
        $template = dxf_public_template($pdo, $slug);
        if ($template === null) return '';
        return '<link rel="stylesheet" href="/static/plugins/docx-form/widget.css?v=' . rawurlencode(DXF_VERSION) . '"><aside class="dxf-widget"><strong>' . dxf_h($template['title']) . '</strong><p>' . dxf_h($template['description']) . '</p><a href="/document-forms/' . rawurlencode($slug) . '/">' . dxf_h(dxf_t('Open form')) . '</a></aside>';
    }, ['slug' => '']);
    return isset($GLOBALS['_widget_shortcode_handlers']['docx_form']);
}
