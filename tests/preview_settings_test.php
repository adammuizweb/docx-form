<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/validation.php';
require dirname(__DIR__) . '/includes/repository.php';

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok' : 'not ok') . ' - ' . $message . PHP_EOL;
    if (!$ok) $failures++;
};

$defaults = dxf_settings_defaults();
$check($defaults['preview_mode'] === 'shadow', 'new installs default to the non-authoritative shadow preview');

$rendered = dxf_settings_from_rows([
    ['key'=>'dxf_preview_mode','value'=>'rendered_docx'],
    ['key'=>'dxf_rate_max_requests','value'=>'15'],
]);
$check($rendered['preview_mode'] === 'rendered_docx' && $rendered['rate_max_requests'] === 15, 'rendered preview mode and numeric controls load from settings rows');

$invalid = dxf_settings_from_rows([
    ['key'=>'dxf_preview_mode','value'=>'external_renderer'],
    ['key'=>'dxf_rate_max_requests','value'=>'1000'],
]);
$check($invalid['preview_mode'] === 'shadow' && $invalid['rate_max_requests'] === 10, 'unknown preview modes and out-of-range controls fail closed to defaults');

exit($failures === 0 ? 0 : 1);
