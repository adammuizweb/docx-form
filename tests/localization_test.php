<?php
declare(strict_types=1);
$root=dirname(__DIR__);$keys=[];
foreach(['admin','includes'] as $directory){$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS));foreach($iterator as $file){if($file->getExtension()!=='php')continue;$source=(string)file_get_contents($file->getPathname());if(preg_match_all("/dxf_t\\('((?:[^'\\\\]|\\\\.)*)'/",$source,$matches))foreach($matches[1] as $key)$keys[stripcslashes($key)]=true;}}
$failures=0;foreach(['id','de'] as $locale){$catalog=require $root.'/languages/'.$locale.'.php';$missing=array_values(array_diff(array_keys($keys),array_keys($catalog)));sort($missing,SORT_STRING);if($missing!==[]){$failures++;echo 'not ok - '.$locale.' catalog is missing: '.implode(' | ',$missing).PHP_EOL;}else echo 'ok - '.$locale.' catalog covers every literal plugin UI key'.PHP_EOL;}
exit($failures===0?0:1);
