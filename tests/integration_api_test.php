<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/validation.php';
require dirname(__DIR__).'/includes/storage.php';
require dirname(__DIR__).'/includes/integrations.php';
$failures=0;
$check=static function(bool $ok,string $message)use(&$failures):void{echo($ok?'ok':'not ok').' - '.$message.PHP_EOL;if(!$ok)$failures++;};
$throws=static function(callable $callback):bool{try{$callback();return false;}catch(DomainException|RuntimeException){return true;}};
unset($GLOBALS['dxf_otp_sender'],$GLOBALS['dxf_pdf_converter'],$GLOBALS['dxf_pdf_validator']);
$check(!dxf_otp_sender_available()&&!dxf_pdf_converter_available()&&!dxf_pdf_inline_available(),'optional capabilities default unavailable');
$check(dxf_register_otp_sender(static fn(string $email,string $code,array $context):bool=>$email==='user@example.test'&&strlen($code)===6)&&!dxf_register_otp_sender(static fn():bool=>true),'OTP sender registration is first-wins');
$check(dxf_send_otp('user@example.test','123456',['template_id'=>1,'template_title'=>'Form','expires_at_utc'=>'2026-01-01 00:00:00']),'registered OTP sender receives bounded context');
$check(dxf_register_pdf_converter(static fn():bool=>true)&&!dxf_register_pdf_converter(static fn():bool=>true),'PDF converter registration is first-wins');
$directory=sys_get_temp_dir().'/dxf-pdf-'.bin2hex(random_bytes(5));mkdir($directory,0700,true);
$safe="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\nstartxref\n0\n%%EOF\n";file_put_contents($directory.'/safe.pdf',$safe);dxf_validate_pdf($directory.'/safe.pdf');$check(true,'conservative built-in validator accepts a passive bounded PDF envelope');
foreach(['/Encrypt 2 0 R'=>'encryption','/En#63rypt 2 0 R'=>'encoded encryption','/JavaScript (alert)'=>'javascript','/J#53 (alert)'=>'encoded JavaScript','/OpenAction 1 0 R'=>'open action','/ObjStm'=>'compressed object stream','/AcroForm 1 0 R'=>'interactive form','/URI (https://example.test)'=>'external link','/GoToR 2 0 R'=>'remote action','/EmbeddedFiles 2 0 R'=>'embedded files'] as $payload=>$label){$path=$directory.'/'.$label.'.pdf';file_put_contents($path,"%PDF-1.4\n1 0 obj\n<< ".$payload." >>\nendobj\n%%EOF\n");$check($throws(static fn()=>dxf_validate_pdf($path)),'built-in PDF validation rejects '.$label);}
$check(dxf_register_pdf_validator(static function(string $source,string $destination,array $context)use($safe):bool{if(($context['purpose']??'')!=='source')return false;return file_put_contents($destination,$safe)!==false;})&&!dxf_register_pdf_validator(static fn():bool=>true)&&dxf_pdf_inline_available(),'PDF validator/sanitizer registration is first-wins and gates inline use');
$sanitized=$directory.'/sanitized.pdf';$metadata=dxf_sanitize_pdf($directory.'/safe.pdf',$sanitized,['purpose'=>'source','template_id'=>1]);$check($metadata['size']===strlen($safe)&&$metadata['sha256']===hash('sha256',$safe),'trusted PDF sanitizer output is conservatively revalidated');
foreach(scandir($directory)?:[] as $entry)if($entry!=='.'&&$entry!=='..')unlink($directory.'/'.$entry);rmdir($directory);exit($failures===0?0:1);
