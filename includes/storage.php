<?php
declare(strict_types=1);

function dxf_storage_root(): string
{
    $override = $GLOBALS['dxf_storage_root_override'] ?? null;
    $root = is_string($override) && $override !== ''
        ? $override
        : dirname(rtrim((string)BACKEND_PATH, '/\\')) . '/private_files/plugins/docx-form';
    return rtrim($root, '/\\');
}

function dxf_storage_ensure(): string
{
    $root = dxf_storage_root();
    $parent = dirname($root);
    $override = is_string($GLOBALS['dxf_storage_root_override'] ?? null) && $GLOBALS['dxf_storage_root_override'] !== '';
    if (!$override) {
        $private = dirname(rtrim((string)BACKEND_PATH, '/\\')) . '/private_files';
        if (is_link($private) || !is_dir($private) || realpath($private) === false) throw new RuntimeException('Core private storage is unavailable or unsafe.');
        if (is_link($parent) || (!is_dir($parent) && !mkdir($parent, 0770) && !is_dir($parent))) throw new RuntimeException('Private plugin storage parent is unavailable.');
        $privateReal = realpath($private);
        $parentReal = realpath($parent);
        if ($privateReal === false || $parentReal === false || !str_starts_with($parentReal, $privateReal . DIRECTORY_SEPARATOR)) throw new RuntimeException('Private plugin storage escaped Core private storage.');
    } elseif (is_link($parent) || !is_dir($parent)) {
        throw new RuntimeException('Private storage parent is unavailable.');
    }
    if (is_link($root) || (!is_dir($root) && !mkdir($root, 0770) && !is_dir($root))) throw new RuntimeException('Private DOCX Form storage is unavailable.');
    $real = realpath($root);
    $parentReal = realpath($parent);
    if ($real === false || $parentReal === false || is_link($root) || !str_starts_with($real, $parentReal . DIRECTORY_SEPARATOR)) throw new RuntimeException('Private DOCX Form storage is unsafe.');
    return $real;
}

function dxf_storage_name(string $extension): string
{
    $extension = strtolower($extension);
    if (!in_array($extension, ['docx', 'pdf'], true)) throw new InvalidArgumentException('Unsupported storage extension.');
    return bin2hex(random_bytes(24)) . '.' . $extension;
}

function dxf_storage_path(string $name, bool $mustExist = true): string
{
    if (preg_match('/\A[a-f0-9]{48}\.(?:docx|pdf)\z/D', $name) !== 1) throw new RuntimeException('Invalid private storage name.');
    $root = dxf_storage_ensure();
    $path = $root . DIRECTORY_SEPARATOR . $name;
    if (!$mustExist) return $path;
    if (is_link($path)) throw new RuntimeException('Private file symlinks are not allowed.');
    $real = realpath($path);
    if ($real === false || !is_file($real) || !is_readable($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Private file is unavailable.');
    return $real;
}

function dxf_store_file(string $source, string $extension): array
{
    $stage = dxf_stage_file($source, $extension);
    try { return dxf_publish_staged_file($stage, $extension); }
    catch (Throwable $error) { dxf_discard_stage($stage); throw $error; }
}

function dxf_staging_root(): string
{
    $root = dxf_storage_ensure();
    $stage = $root . DIRECTORY_SEPARATOR . 'staging';
    if (is_link($stage) || (!is_dir($stage) && !mkdir($stage, 0770) && !is_dir($stage))) throw new RuntimeException('Private staging storage is unavailable.');
    $real = realpath($stage);
    if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Private staging storage is unsafe.');
    return $real;
}

function dxf_stage_file(string $source, string $extension, int $maximumBytes = 20971520): string
{
    if (!in_array(strtolower($extension), ['docx','pdf'], true) || is_link($source) || !is_file($source) || !is_readable($source)) throw new RuntimeException('Upload stage is unavailable.');
    $size = filesize($source);
    if ($size === false || $size < 1 || $size > $maximumBytes) throw new DomainException('The staged file exceeds its bounded size.');
    $destination = dxf_staging_root() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24)) . '.' . strtolower($extension) . '.tmp';
    $input = fopen($source, 'rb');
    $output = fopen($destination, 'xb');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        throw new RuntimeException('Private file could not be stored.');
    }
    try {
        if (stream_copy_to_stream($input, $output) === false || !fflush($output)) throw new RuntimeException('Private file copy failed.');
    } catch (Throwable $error) {
        fclose($input); fclose($output); @unlink($destination); throw $error;
    }
    fclose($input); fclose($output); chmod($destination, 0660);
    if ((filesize($destination) ?: 0) !== $size) { @unlink($destination); throw new RuntimeException('Private staged file size changed.'); }
    return $destination;
}

function dxf_new_stage_path(string $extension): string
{
    $extension = strtolower($extension);
    if (!in_array($extension, ['docx','pdf'], true)) throw new InvalidArgumentException('Unsupported stage extension.');
    return dxf_staging_root() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24)) . '.' . $extension . '.tmp';
}

function dxf_publish_staged_file(string $stage, string $extension): array
{
    $stageReal = realpath($stage);
    $stagingRoot = dxf_staging_root();
    if ($stageReal === false || is_link($stage) || !is_file($stageReal) || !str_starts_with($stageReal, $stagingRoot . DIRECTORY_SEPARATOR)) throw new RuntimeException('Private stage is unavailable.');
    $name = dxf_storage_name($extension);
    $destination = dxf_storage_path($name, false);
    if (!rename($stageReal, $destination)) throw new RuntimeException('Private staged file could not be published atomically.');
    chmod($destination, 0660);
    return ['name' => $name, 'sha256' => hash_file('sha256', $destination), 'size' => filesize($destination) ?: 0];
}

function dxf_discard_stage(string $stage): void
{
    $real = realpath($stage);
    $root = dxf_staging_root();
    if ($real !== false && !is_link($stage) && is_file($real) && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) @unlink($real);
}

function dxf_validate_pdf(string $path): void
{
    dxf_validate_pdf_envelope($path);
    $content = file_get_contents($path);
    if (!is_string($content)) throw new DomainException('The source file is not a readable PDF.');
    $normalized = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn(array $match): string => chr(hexdec($match[1])), $content) ?? $content;
    $normalized = preg_replace('/%[^\r\n]*/', '', $normalized) ?? $normalized;
    if (preg_match('~/\s*(?:Encrypt|JavaScript|JS|Launch|OpenAction|AA|RichMedia|EmbeddedFiles?|Filespec|Collection|XFA|AcroForm|SubmitForm|ImportData|URI|GoToR|GoToE|Sound|Movie|Rendition|Hide|ObjStm)\b~i', $normalized)) {
        throw new DomainException('Encrypted PDFs, active content, forms, attachments, links, and compressed object streams are not supported.');
    }
}

function dxf_validate_pdf_envelope(string $path): void
{
    if (is_link($path) || !is_file($path) || ($size = filesize($path)) === false || $size < 8 || $size > 20971520) throw new DomainException('The PDF is missing or exceeds 20 MiB.');
    $content = file_get_contents($path);
    if (!is_string($content) || !preg_match('/\A%PDF-1\.[0-7](?:\r\n|\r|\n)/', $content) || !preg_match('/%%EOF[\x00\x09\x0A\x0C\x0D\x20]*\z/D', $content)) throw new DomainException('The source file is not a complete supported PDF.');
}

function dxf_assert_file_integrity(string $storageName, string $expectedSha256): string
{
    $path = dxf_storage_path($storageName);
    if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1 || !hash_equals($expectedSha256, hash_file('sha256', $path))) throw new RuntimeException('Private file integrity check failed.');
    return $path;
}

function dxf_stream_private_file(string $storageName, string $expectedSha256, string $contentType, string $downloadName, string $disposition): never
{
    dxf_stream_private_descriptor(['name'=>$storageName,'sha256'=>$expectedSha256], $contentType, $downloadName, $disposition, false);
}

function dxf_stream_private_descriptor(array $descriptor, string $contentType, string $downloadName, string $disposition, bool $head): never
{
    if (!in_array($disposition, ['inline', 'attachment'], true)) throw new InvalidArgumentException('Invalid disposition.');
    $name = $descriptor['name'] ?? null; $expectedSha256 = $descriptor['sha256'] ?? null;
    if (!is_string($name) || !is_string($expectedSha256)) throw new RuntimeException('Private file descriptor is invalid.');
    $path = dxf_storage_path($name);
    $stream = fopen($path, 'rb');
    if ($stream === false) throw new RuntimeException('Private file could not be opened.');
    $stat = fstat($stream);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000 || ($descriptor['size'] ?? $stat['size']) !== $stat['size']) { fclose($stream); throw new RuntimeException('Private file size or type changed.'); }
    $hash = hash_init('sha256');
    hash_update_stream($hash, $stream);
    if (!hash_equals($expectedSha256, hash_final($hash)) || fseek($stream, 0) !== 0) { fclose($stream); throw new RuntimeException('Private file integrity check failed.'); }
    $size = (int)$stat['size'];
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . (string)$size);
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace(['"', '\\'], '', $downloadName) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    if ($head) { fclose($stream); exit; }
    while (!feof($stream)) { $chunk = fread($stream, 65536); if ($chunk === false) break; echo $chunk; }
    fclose($stream);
    exit;
}
