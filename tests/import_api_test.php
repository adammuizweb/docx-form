<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/includes/validation.php';
require $root . '/includes/descriptor.php';
require $root . '/includes/storage.php';
require $root . '/includes/docx.php';
require $root . '/includes/repository.php';
require $root . '/includes/integrations.php';
require $root . '/includes/import.php';

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void { echo ($ok ? 'ok' : 'not ok') . ' - ' . $message . PHP_EOL; if (!$ok) $failures++; };
$throws = static function (callable $operation): bool { try { $operation(); return false; } catch (DomainException|RuntimeException) { return true; } };
$directory = sys_get_temp_dir() . '/dxf-import-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);

$docx = $directory . '/template.docx';
$zip = new ZipArchive();
if ($zip->open($docx, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Unable to create import fixture.');
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>{{person.name}}</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
$pdf = $directory . '/source.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\nstartxref\n0\n%%EOF\n");
$descriptor = dxf_descriptor_validate([
    'schema'=>'docx-form/v1','document_filename'=>'result.docx',
    'fields'=>[['key'=>'person.name','label'=>'Name','type'=>'text','required'=>true,'max_length'=>100,'token'=>'{{person.name}}','identity'=>'name']],
    'print'=>[],
]);
$definition = [
    'definition_id'=>'example','title'=>'Example form','slug'=>'example-form','description'=>'Example managed import.',
    'status'=>'published','identity_mode'=>'self_declared','descriptor'=>$descriptor,'descriptor_sha256'=>hash('sha256', dxf_descriptor_json($descriptor)),
    'docx_path'=>$docx,'docx_sha256'=>hash_file('sha256', $docx),'docx_size'=>filesize($docx),
    'source_pdf_path'=>$pdf,'source_pdf_sha256'=>hash_file('sha256', $pdf),'source_pdf_size'=>filesize($pdf),
    'allow_source_view'=>false,'allow_source_download'=>true,'allow_docx_download'=>true,'allow_print'=>false,'allow_pdf'=>false,
];
$prepared = dxf_import_prepare_package(['namespace'=>'example.forms','release'=>'v1','predecessor_release'=>null,'templates'=>[$definition]]);
$check(($prepared['templates'][0]['projection_sha256'] ?? '') === dxf_import_projection_hash($prepared['templates'][0]), 'managed import canonicalizes and fingerprints a complete definition');
$check($throws(static function () use ($definition): void { $definition['allow_source_view'] = true; dxf_import_prepare_package(['namespace'=>'example.forms','release'=>'v1','predecessor_release'=>null,'templates'=>[$definition]]); }), 'managed import cannot claim offline inline-PDF validation');
$check($throws(static function () use ($definition): void { $definition['docx_sha256'] = str_repeat('0', 64); dxf_import_prepare_package(['namespace'=>'example.forms','release'=>'v1','predecessor_release'=>null,'templates'=>[$definition]]); }), 'managed import rejects source integrity drift');
$check($throws(static function () use ($definition): void { dxf_import_prepare_package(['namespace'=>'example.forms','release'=>'v1','predecessor_release'=>null,'templates'=>[$definition,$definition]]); }), 'managed import rejects duplicate definition IDs and slugs');

unlink($docx);
unlink($pdf);
rmdir($directory);
exit($failures === 0 ? 0 : 1);
