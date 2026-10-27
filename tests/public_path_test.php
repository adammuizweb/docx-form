<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/public.php';
$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void { echo ($ok ? 'ok' : 'not ok') . ' - ' . $message . PHP_EOL; if (!$ok) $failures++; };
unset($GLOBALS['dxf_resolved_path']);
$check(dxf_public_path_segments() === null, 'public path fails closed before Core resolves a route');
$path = 'document-forms/example/source/view';
$check(dxf_capture_resolved_path($path) === $path, 'route capture preserves the filtered Core path');
$check(dxf_public_path_segments() === ['example','source','view'], 'dispatch consumes the captured filtered Core path');
$check(dxf_public_path_segments('document-forms/other') === ['other'], 'an explicit test path overrides request-local capture');
$check(dxf_capture_resolved_path(['invalid']) === ['invalid'] && dxf_public_path_segments() === null, 'malformed filter output clears captured route state');
exit($failures === 0 ? 0 : 1);
