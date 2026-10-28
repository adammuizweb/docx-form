<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/validation.php';
require dirname(__DIR__) . '/includes/descriptor.php';
$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void { echo ($ok ? 'ok' : 'not ok') . ' - ' . $message . PHP_EOL; if (!$ok) $failures++; };
$throws = static function (callable $callback): bool { try { $callback(); return false; } catch (DomainException) { return true; } };
$descriptor = dxf_descriptor_decode(json_encode([
    'schema'=>'docx-form/v1','document_filename'=>'result.docx',
    'fields'=>[
        ['key'=>'person.full_name','label'=>'Full name','type'=>'text','required'=>true,'max_length'=>120,'token'=>'{{person.full_name}}','identity'=>'name'],
        ['key'=>'person.email','label'=>'Email','type'=>'email','required'=>true,'max_length'=>254,'token'=>'{{person.email}}','identity'=>'email'],
        ['key'=>'choice','label'=>'Choice','type'=>'select','required'=>true,'max_length'=>20,'token'=>'{{choice}}','options'=>[['value'=>'one','label'=>'One'],['value'=>'two','label'=>'Two']]],
    ],'print'=>['title'=>'Result','columns'=>2,'show_description'=>true],
], JSON_THROW_ON_ERROR));
$check($descriptor['schema'] === 'docx-form/v1' && count($descriptor['fields']) === 3, 'valid descriptor normalizes');
$validated = dxf_validate_values($descriptor, ['person.full_name'=>'Ada <One>','person.email'=>'USER@example.test','choice'=>'two'], 'email_otp', 'user@example.test');
$check($validated['values']['person.full_name'] === 'Ada <One>' && $validated['identity']['email_verified'] === true, 'typed values remain plain text and verified email binds case-insensitively');
$self = dxf_validate_values($descriptor, ['person.full_name'=>'Ada','person.email'=>'ada@example.test','choice'=>'one'], 'self_declared');
$check($self['identity']['email_verified'] === false, 'self-declared identity is never marked verified');
$partial = dxf_validate_values($descriptor, ['person.full_name'=>'','person.email'=>'','choice'=>''], 'email_otp', 'user@example.test', true);
$check($partial['values'] === ['person.full_name'=>'','person.email'=>'','choice'=>''], 'preview validation permits incomplete required values while preserving descriptor order');
$check($throws(static fn() => dxf_validate_values($descriptor, ['person.full_name'=>'','person.email'=>'wrong@example.test','choice'=>''], 'email_otp', 'user@example.test', true)), 'preview validation preserves verified email binding');
$valuesJson=dxf_values_json($descriptor,['choice'=>'two','person.email'=>'USER@example.test','person.full_name'=>'Ada <One>']);
$check(array_keys(json_decode($valuesJson,true,16,JSON_THROW_ON_ERROR))===['person.full_name','person.email','choice'],'value JSON follows immutable descriptor field order');
$identityJson=dxf_identity_json(['email_verified'=>true,'email'=>'USER@example.test','name'=>'Ada']);
$check($identityJson==='{"name":"Ada","email":"USER@example.test","email_verified":true}'&&hash_equals(hash('sha256',$identityJson),hash('sha256',dxf_identity_json((array)json_decode($identityJson,true,16,JSON_THROW_ON_ERROR)))),'identity JSON and hash are canonical after database decode');
$descriptorJson=dxf_descriptor_json($descriptor);$check(hash_equals(hash('sha256',$descriptorJson),hash('sha256',dxf_descriptor_json((array)json_decode($descriptorJson,true,32,JSON_THROW_ON_ERROR)))),'descriptor JSON and hash are canonical after database decode');
$check($throws(static fn() => dxf_descriptor_decode('{bad')), 'malformed JSON is rejected');
$check($throws(static fn() => dxf_descriptor_validate(['schema'=>'docx-form/v1','document_filename'=>'x.docx','fields'=>[['key'=>'x','label'=>'X','type'=>'text','token'=>'{{other}}']]])), 'token and key mismatch is rejected');
$check($throws(static fn() => dxf_validate_values($descriptor, ['person.full_name'=>'Ada','person.email'=>'bad','choice'=>'one'], 'self_declared')), 'invalid field values are rejected');
$check($throws(static fn() => dxf_validate_values($descriptor, ['person.full_name'=>str_repeat('x',121),'person.email'=>'ada@example.test','choice'=>'one'], 'self_declared')), 'overlong values are rejected instead of truncated');
$check($throws(static fn() => dxf_descriptor_validate(['schema'=>'docx-form/v1','document_filename'=>'x.docx','fields'=>[['key'=>'x','label'=>str_repeat('x',161),'type'=>'text','token'=>'{{x}}']]])), 'overlong descriptor text is rejected instead of truncated');
exit($failures === 0 ? 0 : 1);
