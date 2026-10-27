<?php
declare(strict_types=1);

$dsn = getenv('DXF_CONTRACT_MYSQL_DSN') ?: '';
if ($dsn === '') { echo "ok - disposable MySQL schema contract skipped (DXF_CONTRACT_MYSQL_DSN is unset)\n"; exit(0); }

$root = dirname(__DIR__);
$user = getenv('DXF_CONTRACT_MYSQL_USER') ?: '';
$password = getenv('DXF_CONTRACT_MYSQL_PASSWORD') ?: '';
$database = 'dxf_contract_' . bin2hex(random_bytes(8));
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$foundation = (string)file_get_contents($root . '/migrations/0001-foundation.sql');
$foreignKey = require $root . '/migrations/0002-current-version-foreign-key.php';
$verify = require $root . '/migrations/0003-verify-schema.php';
$importLedger = (string)file_get_contents($root . '/migrations/0004-template-import-ledger.sql');
$verifyImport = require $root . '/migrations/0005-verify-import-ledger.php';
if (!is_callable($foreignKey) || !is_callable($verify) || !is_callable($verifyImport)) throw new RuntimeException('Schema PHP migrations are not callable.');

$createDatabase = static function () use ($pdo, $database): void {
    $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    $pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $database . '`');
};
$runFoundation = static function () use ($pdo, $foundation): void {
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', $foundation, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) $pdo->exec($statement);
};
$runAll = static function () use ($runFoundation, $foreignKey, $verify, $importLedger, $verifyImport, $pdo): void {
    $runFoundation();
    $foreignKey($pdo);
    $verify($pdo);
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', $importLedger, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $statement) $pdo->exec($statement);
    $verifyImport($pdo);
};
$mustReject = static function (callable $operation, string $message) use ($verify, $pdo): void {
    $operation();
    try { $verify($pdo); }
    catch (RuntimeException) { return; }
    throw new RuntimeException($message);
};

try {
    $createDatabase();
    $runFoundation();
    $pdo->exec('DROP TABLE `dxf_rate_limits`');
    $runAll();
    $runAll();

    require_once $root . '/includes/validation.php';
    require_once $root . '/includes/descriptor.php';
    $descriptor = dxf_descriptor_validate([
        'schema'=>'docx-form/v1','document_filename'=>'result.docx',
        'fields'=>[['key'=>'person.name','label'=>'Full name','type'=>'text','required'=>true,'max_length'=>120,'token'=>'{{person.name}}','identity'=>'name']],
        'print'=>['title'=>'Result','columns'=>1,'show_description'=>true],
    ]);
    $descriptorJson = dxf_descriptor_json($descriptor);
    $valuesJson = dxf_values_json($descriptor, ['person.name'=>'Ada Lovelace']);
    $identityJson = dxf_identity_json(['name'=>'Ada Lovelace','email_verified'=>false]);
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO dxf_templates (title,slug,description,status,identity_mode,created_by,updated_by,created_at_utc,updated_at_utc) VALUES (?,?,?,'published','self_declared',1,1,?,?)")->execute(['Current mutable title','contract','Current mutable description',$now,$now]);
    $templateId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO dxf_template_versions (template_id,revision,display_title,display_description,descriptor_json,descriptor_sha256,docx_storage_name,docx_sha256,docx_size,created_by,created_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$templateId,1,'Immutable title','Immutable description',$descriptorJson,hash('sha256',$descriptorJson),str_repeat('a',48).'.docx',str_repeat('b',64),1,1,$now]);
    $versionId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE dxf_templates SET current_version_id=? WHERE id=?')->execute([$versionId,$templateId]);
    $publicId = '123e4567-e89b-42d3-a456-426614174000';
    $pdo->prepare("INSERT INTO dxf_submissions (public_id,template_id,template_version_id,identity_mode,identity_json,identity_sha256,values_json,values_sha256,idempotency_hash,receipt_token_hash,receipt_expires_at_utc,status,created_at_utc,updated_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)")->execute([$publicId,$templateId,$versionId,'self_declared',$identityJson,hash('sha256',$identityJson),$valuesJson,hash('sha256',$valuesJson),str_repeat('c',64),str_repeat('d',64),gmdate('Y-m-d H:i:s',time()+3600),$now,$now]);
    $row = $pdo->query("SELECT v.display_title,v.display_description,v.descriptor_json,s.values_json,s.identity_json FROM dxf_submissions s JOIN dxf_template_versions v ON v.id=s.template_version_id WHERE s.public_id='123e4567-e89b-42d3-a456-426614174000'")->fetch();
    if (!is_array($row) || $row['display_title'] !== 'Immutable title' || $row['display_description'] !== 'Immutable description'
        || dxf_descriptor_json((array)json_decode((string)$row['descriptor_json'], true, 32, JSON_THROW_ON_ERROR)) !== $descriptorJson
        || dxf_values_json($descriptor, (array)json_decode((string)$row['values_json'], true, 32, JSON_THROW_ON_ERROR)) !== $valuesJson
        || dxf_identity_json((array)json_decode((string)$row['identity_json'], true, 16, JSON_THROW_ON_ERROR)) !== $identityJson) throw new RuntimeException('Canonical JSON or immutable display data changed during database round-trip.');

    $createDatabase(); $runAll();
    $mustReject(static fn() => $pdo->exec('ALTER TABLE `dxf_submissions` DROP INDEX `dxf_submission_receipt_uq`, ADD INDEX `dxf_submission_receipt_uq` (`receipt_token_hash`)'), 'Schema verifier accepted a weakened receipt index.');

    $createDatabase(); $runAll();
    $isMariaDb = stripos((string)$pdo->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
    $dropCheck = $isMariaDb ? 'DROP CONSTRAINT' : 'DROP CHECK';
    $mustReject(static fn() => $pdo->exec("ALTER TABLE `dxf_templates` {$dropCheck} `dxf_template_status_ck`, ADD CONSTRAINT `dxf_template_status_ck` CHECK (`status` IN ('draft','published'))"), 'Schema verifier accepted a weakened status check.');

    $createDatabase(); $runAll();
    $mustReject(static fn() => $pdo->exec('CREATE TABLE `dxf_contract_reference` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`template_id` BIGINT UNSIGNED NOT NULL,PRIMARY KEY (`id`),CONSTRAINT `dxf_contract_unexpected_fk` FOREIGN KEY (`template_id`) REFERENCES `dxf_templates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB'), 'Schema verifier accepted an unexpected foreign key into plugin tables.');

    if ($isMariaDb) {
        $createDatabase(); $runAll();
        $jsonConstraint = $pdo->query("SELECT tc.CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME='dxf_template_versions' AND LOWER(cc.CHECK_CLAUSE) LIKE '%json_valid%descriptor_json%' LIMIT 1")->fetchColumn();
        if (!is_string($jsonConstraint)) throw new RuntimeException('MariaDB descriptor JSON constraint was not discoverable.');
        $mustReject(static fn() => $pdo->exec('ALTER TABLE `dxf_template_versions` MODIFY `descriptor_json` LONGTEXT NOT NULL'), 'Schema verifier accepted a missing MariaDB JSON_VALID constraint.');
    }

    $createDatabase(); $runAll();
    $pdo->exec('CREATE TABLE `settings` (`key` VARCHAR(191) NOT NULL,`value` TEXT DEFAULT NULL,PRIMARY KEY (`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec("INSERT INTO `settings` (`key`,`value`) VALUES ('dxf_rate_max_requests','10')");
    if (!defined('DXF_PERMISSION_TEMPLATES')) define('DXF_PERMISSION_TEMPLATES', 'plugin.docx-form.templates.manage');
    if (!function_exists('authorization_lock_actor_permissions')) { function authorization_lock_actor_permissions(PDO $pdo, int $actorId): bool { return $actorId === 1 && $pdo->inTransaction(); } }
    if (!function_exists('user_can')) { function user_can(PDO $pdo, int $actorId, string $permission): bool { return $actorId === 1 && $permission === DXF_PERMISSION_TEMPLATES; } }
    if (!function_exists('audit_log_record')) { function audit_log_record(PDO $pdo, array $record): void { $GLOBALS['dxf_contract_audits'][] = $record; } }
    require_once $root . '/includes/repository.php';
    require_once $root . '/includes/storage.php';
    require_once $root . '/includes/docx.php';
    require_once $root . '/includes/integrations.php';
    require_once $root . '/includes/import.php';
    require_once $root . '/includes/lifecycle.php';
    $storageBase = sys_get_temp_dir() . '/dxf-contract-life-' . bin2hex(random_bytes(5));
    if (!mkdir($storageBase . '/plugins/docx-form', 0700, true)) throw new RuntimeException('Unable to create contract storage root.');
    $GLOBALS['dxf_storage_root_override'] = $storageBase . '/plugins/docx-form';
    $GLOBALS['pdo'] = $pdo;
    $sourceDirectory = $storageBase . '/sources';
    mkdir($sourceDirectory, 0700);
    $sourceDocx = $sourceDirectory . '/template.docx';
    $zip = new ZipArchive();
    if ($zip->open($sourceDocx, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Unable to create contract import DOCX.');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{person.name}}</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $sourcePdf = $sourceDirectory . '/source.pdf';
    file_put_contents($sourcePdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\nstartxref\n0\n%%EOF\n");
    $importDescriptor = dxf_descriptor_validate(['schema'=>'docx-form/v1','document_filename'=>'result.docx','fields'=>[['key'=>'person.name','label'=>'Name','type'=>'text','required'=>true,'max_length'=>100,'token'=>'{{person.name}}','identity'=>'name']],'print'=>[]]);
    $importPackage = ['namespace'=>'contract.forms','release'=>'v1','predecessor_release'=>null,'templates'=>[array_merge([
        'definition_id'=>'example','title'=>'Example form','slug'=>'example-form','description'=>'Managed import contract.',
        'status'=>'published','identity_mode'=>'self_declared','descriptor'=>$importDescriptor,'descriptor_sha256'=>hash('sha256', dxf_descriptor_json($importDescriptor)),
        'docx_path'=>$sourceDocx,'docx_sha256'=>hash_file('sha256', $sourceDocx),'docx_size'=>filesize($sourceDocx),
        'source_pdf_path'=>$sourcePdf,'source_pdf_sha256'=>hash_file('sha256', $sourcePdf),'source_pdf_size'=>filesize($sourcePdf),
    ], ['allow_source_view'=>false,'allow_source_download'=>true,'allow_docx_download'=>true,'allow_print'=>false,'allow_pdf'=>false])]];
    $dryRun = dxf_import_templates($pdo, $importPackage);
    if (($dryRun['changes'] ?? null) !== 1 || ($dryRun['actions']['example']['action'] ?? null) !== 'create') throw new RuntimeException('Managed import dry run did not plan an initial create.');
    $applied = dxf_import_templates($pdo, $importPackage, 1, true);
    if (($applied['changes'] ?? null) !== 0 || ($applied['actions']['example']['action'] ?? null) !== 'current'
        || (int)$pdo->query('SELECT COUNT(*) FROM dxf_templates')->fetchColumn() !== 1
        || (int)$pdo->query('SELECT COUNT(*) FROM dxf_import_ledger')->fetchColumn() !== 1
        || count($GLOBALS['dxf_contract_audits'] ?? []) !== 1) throw new RuntimeException('Managed import did not apply and converge exactly once.');
    $pdo->exec("UPDATE dxf_templates SET title='Operator edit' WHERE slug='example-form'");
    try { dxf_import_templates($pdo, $importPackage); throw new RuntimeException('Managed import accepted operator drift.'); }
    catch (DomainException) {}
    unlink($sourceDocx); unlink($sourcePdf); rmdir($sourceDirectory);
    dxf_uninstall('docx-form');
    dxf_uninstall('docx-form');
    $tableCount = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'dxf\\_%'")->fetchColumn();
    if ((int)$tableCount !== 0 || (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE `key` LIKE 'dxf\\_%'")->fetchColumn() !== 0 || file_exists($storageBase . '/plugins/docx-form')) throw new RuntimeException('Complete uninstall did not remove only plugin-owned state idempotently.');
    rmdir($storageBase . '/plugins'); rmdir($storageBase);
    unset($GLOBALS['dxf_storage_root_override']);

    echo "ok - disposable MySQL/MariaDB migrations, drift checks, JSON round-trip, and uninstall pass\n";
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
