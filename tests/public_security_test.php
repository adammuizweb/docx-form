<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void { echo ($ok ? 'ok' : 'not ok') . ' - ' . $message . PHP_EOL; if (!$ok) $failures++; };
$run = static function (string $setup, string $call) use ($root): array {
    $code = $setup . 'require ' . var_export($root . '/includes/validation.php', true) . ';require ' . var_export($root . '/includes/public.php', true) . ';' . $call;
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $code], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start public security subprocess.');
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process),$stdout,$stderr];
};

[$status,$stdout,$stderr] = $run("register_shutdown_function(static function():void{echo '|'.http_response_code();});putenv('SESSION_SECRET=" . str_repeat('s', 32) . "');", 'dxf_public_csrf_token();');
$check($status === 0 && $stdout === 'Document form security is temporarily unavailable.|503' && $stderr === '', 'missing stateless Core CSRF APIs produce a controlled public 503 response');

[$status,$stdout,$stderr] = $run("register_shutdown_function(static function():void{echo '|'.http_response_code();});function stateless_csrf_token():string{return 'token';}function stateless_csrf_check(?string \$token,int \$ttl=300):bool{return true;}putenv('SESSION_SECRET');", 'dxf_public_csrf_token();');
$check($status === 0 && $stdout === 'Document form security is temporarily unavailable.|503' && $stderr === '', 'missing SESSION_SECRET produces a controlled 503 without secret-bearing diagnostics');

[$status,$stdout,$stderr] = $run("function csrf_check(?string \$token):bool{return false;}function stateless_csrf_token():string{return 'token';}function stateless_csrf_check(?string \$token,int \$ttl=300):bool{\$GLOBALS['ttl']=\$ttl;return \$token==='token';}putenv('SESSION_SECRET=" . str_repeat('s', 32) . "');\$_SERVER['REQUEST_METHOD']='POST';\$_POST['csrf_token']='token';", "dxf_require_public_post();echo \$GLOBALS['ttl'];");
$check($status === 0 && $stdout === '7200' && $stderr === '', 'public POST exclusively uses stateless CSRF with the documented two-hour TTL');

exit($failures === 0 ? 0 : 1);
