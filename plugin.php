<?php
declare(strict_types=1);

if (!defined('BACKEND_PATH')) return;

const DXF_VERSION = '0.1.0';
const DXF_PERMISSION_READ = 'plugin.docx-form.workspace.read';
const DXF_PERMISSION_TEMPLATES = 'plugin.docx-form.templates.manage';
const DXF_PERMISSION_REVIEW = 'plugin.docx-form.submissions.review';
const DXF_PERMISSION_AUDIT = 'plugin.docx-form.audit.view';
const DXF_PERMISSION_SETTINGS = 'plugin.docx-form.settings.manage';

foreach (['add_action', 'add_filter', 'register_frontend_route', 'register_widget_shortcode_handler', 'csrf_token', 'csrf_check', 'audit_log_record', 'authorization_lock_actor_permissions', 'router_core_path_is_owned'] as $api) {
    if (!function_exists($api)) throw new RuntimeException('DOCX Form requires Jyavani CMS 2.3.187 or newer.');
}
if (!(($GLOBALS['pdo'] ?? null) instanceof PDO) || router_core_path_is_owned($GLOBALS['pdo'], 'document-forms')) {
    throw new RuntimeException('DOCX Form requires the unclaimed public path /document-forms/.');
}

require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/descriptor.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/docx.php';
require_once __DIR__ . '/includes/repository.php';
require_once __DIR__ . '/includes/integrations.php';
require_once __DIR__ . '/includes/public.php';
require_once __DIR__ . '/includes/lifecycle.php';

add_action('admin_head', 'dxf_admin_assets');
add_action('plugin_uninstall', 'dxf_uninstall');
add_filter('router_path', 'dxf_capture_resolved_path', PHP_INT_MAX);

if (!register_frontend_route('document-forms', 'dxf_public_dispatch', [
    'match' => 'prefix',
    'methods' => ['GET', 'HEAD', 'POST'],
])) throw new RuntimeException('DOCX Form public route could not be registered.');

if (!dxf_register_shortcode()) throw new RuntimeException('DOCX Form shortcode integration collided with an existing registration.');
