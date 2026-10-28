<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__));
if ($root === false) throw new RuntimeException('Plugin root is unavailable.');
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
if (($manifest['name'] ?? null) !== 'docx-form' || ($manifest['version'] ?? null) !== '0.4.2') throw new RuntimeException('Unexpected plugin identity.');

$allowed = [
    'CHANGELOG.md','LICENSE','README.md','icon.svg','plugin.json','plugin.php',
    'admin/common.php','admin/index.php','admin/audit/index.php','admin/settings/index.php','admin/settings/save.php',
    'admin/submissions/review.php','admin/submissions/view.php','admin/templates/edit.php','admin/templates/save.php',
    'assets/css/admin.css','assets/css/frontend.css','assets/css/widget.css','assets/js/preview.js','assets/js/print.js',
    'assets/vendor/docx-preview-LICENSE.txt','assets/vendor/docx-preview.min.js','assets/vendor/jszip-LICENSE.markdown','assets/vendor/jszip.min.js',
    'includes/descriptor.php','includes/docx.php','includes/import.php','includes/integrations.php','includes/lifecycle.php',
    'includes/public.php','includes/repository.php','includes/storage.php','includes/validation.php',
    'languages/de.php','languages/id.php','migrations/0001-foundation.sql','migrations/0002-current-version-foreign-key.php','migrations/0003-verify-schema.php',
    'migrations/0004-template-import-ledger.sql','migrations/0005-verify-import-ledger.php','migrations/0006-workflow-mode.php',
    'migrations/0007-verify-workflow-schema.php',
];
sort($allowed, SORT_STRING);
$output = $argv[1] ?? dirname($root) . '/docx-form-0.4.2.zip';
$directory = realpath(dirname($output));
if ($directory === false || !is_writable($directory)) throw new RuntimeException('Package output directory is unavailable.');
$output = $directory . DIRECTORY_SEPARATOR . basename($output);
if (strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'zip' || $output === $root || str_starts_with($output, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Package output must be an external ZIP path.');
if (file_exists($output) || is_link($output)) throw new RuntimeException('Package output already exists.');
if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is required.');
foreach ($allowed as $relative) {
    $source = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $real = realpath($source);
    if ($real === false || is_link($source) || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Missing or unsafe allowlisted file: ' . $relative);
}

$stage = $output . '.tmp-' . bin2hex(random_bytes(6));
$zip = new ZipArchive();
if ($zip->open($stage, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Unable to create package stage.');
try {
    foreach ($allowed as $relative) {
        if (!$zip->addFile($root . '/' . $relative, $relative)) throw new RuntimeException('Unable to add package entry: ' . $relative);
        if (method_exists($zip, 'setMtimeName')) $zip->setMtimeName($relative, 946684800);
    }
    if (!$zip->close()) throw new RuntimeException('Unable to finalize package stage.');
    $verify = new ZipArchive();
    if ($verify->open($stage, ZipArchive::RDONLY) !== true) throw new RuntimeException('Unable to reopen package stage.');
    $entries = [];
    for ($index = 0; $index < $verify->numFiles; $index++) $entries[] = $verify->getNameIndex($index);
    $embedded = json_decode((string)$verify->getFromName('plugin.json'), true, 32, JSON_THROW_ON_ERROR);
    $verify->close();
    sort($entries, SORT_STRING);
    if ($entries !== $allowed || ($embedded['name'] ?? null) !== 'docx-form' || ($embedded['version'] ?? null) !== '0.4.2' || $entries[0] === 'docx-form/plugin.json') throw new RuntimeException('Package contract verification failed.');
    if (!rename($stage, $output)) throw new RuntimeException('Unable to publish package atomically.');
    echo json_encode(['path'=>$output,'entries'=>count($entries),'bytes'=>filesize($output),'sha256'=>hash_file('sha256',$output)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    $zip->close();
    if (is_file($stage)) unlink($stage);
    throw $error;
}
