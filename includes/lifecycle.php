<?php
declare(strict_types=1);

function dxf_validate_storage_tree(string $root): ?string
{
    if (is_link($root)) throw new RuntimeException('DOCX Form storage root is a symlink.');
    if (!file_exists($root)) return null;
    $real = realpath($root);
    if ($real === false || !is_dir($real) || basename($real) !== 'docx-form' || basename(dirname($real)) !== 'plugins') throw new RuntimeException('DOCX Form storage identity is invalid.');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isLink()) throw new RuntimeException('DOCX Form storage contains a symlink.');
        if (!$item->isDir() && !$item->isFile()) throw new RuntimeException('DOCX Form storage contains an unsupported node.');
    }
    return $real;
}

function dxf_remove_storage_tree(string $root): void
{
    $real = dxf_validate_storage_tree($root);
    if ($real === null) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) { if (!rmdir($path)) throw new RuntimeException('DOCX Form storage directory could not be removed.'); }
        elseif (!unlink($path)) throw new RuntimeException('DOCX Form storage file could not be removed.');
    }
    if (!rmdir($real)) throw new RuntimeException('DOCX Form storage root could not be removed.');
}

function dxf_uninstall(string $name): void
{
    if ($name !== 'docx-form') return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) throw new RuntimeException('DOCX Form database is unavailable.');
    dxf_validate_storage_tree(dxf_storage_root());
    $foreignKey = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='dxf_templates' AND CONSTRAINT_NAME='dxf_template_current_version_fk' AND CONSTRAINT_TYPE='FOREIGN KEY'");
    $foreignKey->execute();
    if ((int)$foreignKey->fetchColumn() === 1) $pdo->exec('ALTER TABLE `dxf_templates` DROP FOREIGN KEY `dxf_template_current_version_fk`');
    foreach (['dxf_submission_events','dxf_rate_limits','dxf_otp_challenges','dxf_submissions','dxf_template_versions','dxf_templates'] as $table) $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    $keys = array_map(static fn(string $name): string => 'dxf_'.$name, array_keys(dxf_settings_defaults()));
    $pdo->prepare('DELETE FROM settings WHERE `key` IN (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute($keys);
    dxf_remove_storage_tree(dxf_storage_root());
}
