<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) { http_response_code(403); exit; }
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) throw new RuntimeException('DOCX Form database is unavailable.');
if (!dxf_schema_ready($pdo)) throw new RuntimeException(dxf_t('Document form storage is unavailable.'));
if (!function_exists('adiwira_redirect_with_flash') && defined('DASH_PATH')) require_once rtrim((string)DASH_PATH, DIRECTORY_SEPARATOR) . '/admin/_notify.php';

function dxf_admin_guard(PDO $pdo, string $permission): int
{
    [$actorId] = adiwira_require_permission($pdo, $permission, false);
    return (int)$actorId;
}

function dxf_admin_can(PDO $pdo, int $actorId, string $permission): bool
{
    return function_exists('user_can') && user_can($pdo, $actorId, $permission);
}

function dxf_admin_url(string $route = '', array $query = []): string
{
    $page = 'admin/tools/docx-form' . ($route === '' ? '' : '/' . trim($route, '/'));
    return rtrim((string)ADMIN_BASE_PATH, '/') . '/?' . http_build_query(['page' => $page] + $query);
}

function dxf_admin_redirect(string $type, string $message, string $route = '', array $query = []): never
{
    $target = dxf_admin_url($route, $query);
    if (function_exists('adiwira_redirect_with_flash')) adiwira_redirect_with_flash($target, $type, $message, 303);
    header('Location: ' . $target, true, 303); exit;
}

function dxf_admin_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { adiwira_render_404(); exit; }
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!csrf_check($token)) dxf_admin_redirect('error', dxf_t('The security token is invalid or expired.'));
}

function dxf_admin_reauthorize(PDO $pdo, int $actorId, string $permission): void
{
    if (!$pdo->inTransaction() || !authorization_lock_actor_permissions($pdo, $actorId) || !user_can($pdo, $actorId, $permission)) throw new RuntimeException('DOCX Form permission changed.');
}

function dxf_admin_nav(PDO $pdo, int $actorId, string $active): void
{
    $items = [
        ['workspace', '', dxf_t('Workspace'), DXF_PERMISSION_READ],
        ['new', 'templates/edit', dxf_t('New template'), DXF_PERMISSION_TEMPLATES],
        ['audit', 'audit', dxf_t('Audit'), DXF_PERMISSION_AUDIT],
        ['settings', 'settings', dxf_t('Settings'), DXF_PERMISSION_SETTINGS],
    ];
    echo '<nav class="dxf-admin-nav" aria-label="' . dxf_h(dxf_t('DOCX Form workspace')) . '">';
    foreach ($items as [$key,$route,$label,$permission]) if (dxf_admin_can($pdo,$actorId,$permission)) echo '<a href="' . dxf_h(dxf_admin_url($route)) . '"' . ($active === $key ? ' aria-current="page"' : '') . '>' . dxf_h($label) . '</a>';
    echo '</nav>';
}

function dxf_admin_datetime(?string $value): string
{
    if (!$value) return '-';
    if (function_exists('app_utc_mysql_to_site') && function_exists('app_display_datetime')) {
        $date = app_utc_mysql_to_site($value);
        if ($date instanceof DateTimeInterface) return app_display_datetime($date);
    }
    return substr($value, 0, 16) . ' UTC';
}

function dxf_admin_uploaded_path(string $key, bool $required): ?string
{
    $file = $_FILES[$key] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) throw new DomainException(dxf_t('Choose the required upload.'));
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new DomainException(dxf_t('The upload did not complete safely.'));
    return $file['tmp_name'];
}
