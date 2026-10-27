<?php
declare(strict_types=1);

const DXF_DOCX_MAX_ARCHIVE_BYTES = 20971520;
const DXF_DOCX_MAX_ENTRY_BYTES = 20971520;
const DXF_DOCX_MAX_TOTAL_BYTES = 104857600;
const DXF_DOCX_MAX_ENTRIES = 2000;

function dxf_zip_central_flags(string $path): array
{
    $size = filesize($path);
    if ($size === false || $size < 22) throw new DomainException('The DOCX ZIP structure is incomplete.');
    $stream = fopen($path, 'rb');
    if ($stream === false) throw new DomainException('The DOCX could not be opened.');
    $window = min($size, 65557);
    fseek($stream, $size - $window);
    $tail = (string)fread($stream, $window);
    $eocd = strrpos($tail, "PK\x05\x06");
    if ($eocd === false || strlen($tail) - $eocd < 22) { fclose($stream); throw new DomainException('The DOCX ZIP directory is missing.'); }
    $record = unpack('vdisk/vstart/vdisk_entries/ventries/Vsize/Voffset/vcomment', substr($tail, $eocd + 4, 18));
    if (!is_array($record) || $record['disk'] !== 0 || $record['start'] !== 0 || $record['disk_entries'] !== $record['entries']
        || $record['entries'] === 0xffff || $record['size'] === 0xffffffff || $record['offset'] === 0xffffffff) {
        fclose($stream); throw new DomainException('Multi-disk and ZIP64 DOCX archives are not supported.');
    }
    if ($record['entries'] < 1 || $record['entries'] > DXF_DOCX_MAX_ENTRIES || $record['offset'] + $record['size'] > $size) {
        fclose($stream); throw new DomainException('The DOCX ZIP directory exceeds safety limits.');
    }
    $eocdOffset = $size - $window + $eocd;
    if ($eocdOffset + 22 + $record['comment'] !== $size || $record['offset'] + $record['size'] !== $eocdOffset) {
        fclose($stream); throw new DomainException('The DOCX ZIP has unsupported prefixed or trailing data.');
    }
    fseek($stream, $record['offset']);
    $flags = [];
    for ($index = 0; $index < $record['entries']; $index++) {
        $header = (string)fread($stream, 46);
        if (strlen($header) !== 46 || substr($header, 0, 4) !== "PK\x01\x02") { fclose($stream); throw new DomainException('The DOCX ZIP directory is malformed.'); }
        $data = unpack('vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal', substr($header, 4));
        if (!is_array($data) || $data['name'] < 1 || $data['name'] > 1024 || $data['extra'] > 65535 || $data['comment'] > 65535) {
            fclose($stream); throw new DomainException('The DOCX ZIP entry metadata is invalid.');
        }
        $name = (string)fread($stream, $data['name']);
        if (strlen($name) !== $data['name']) { fclose($stream); throw new DomainException('The DOCX ZIP entry name is incomplete.'); }
        fseek($stream, $data['extra'] + $data['comment'], SEEK_CUR);
        $flags[] = ['name' => $name, 'flags' => $data['flags'], 'method' => $data['method'], 'local' => $data['local']];
    }
    foreach ($flags as $entry) {
        if ($entry['local'] + 30 > $record['offset']) { fclose($stream); throw new DomainException('A DOCX local ZIP header is outside the data region.'); }
        fseek($stream, $entry['local']);
        $header = (string)fread($stream, 30);
        if (strlen($header) !== 30 || substr($header, 0, 4) !== "PK\x03\x04") { fclose($stream); throw new DomainException('A DOCX local ZIP header is malformed.'); }
        $local = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr($header, 4));
        if (!is_array($local) || $local['name'] < 1 || $local['name'] > 1024 || $local['flags'] !== $entry['flags'] || $local['method'] !== $entry['method']) {
            fclose($stream); throw new DomainException('DOCX local and central ZIP metadata differ.');
        }
        $localName = (string)fread($stream, $local['name']);
        if ($localName !== $entry['name'] || $entry['local'] + 30 + $local['name'] + $local['extra'] > $record['offset']) {
            fclose($stream); throw new DomainException('A DOCX local ZIP entry is inconsistent.');
        }
    }
    fclose($stream);
    return $flags;
}

function dxf_zip_name_is_safe(string $name): bool
{
    if ($name === '' || strlen($name) > 1024 || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) return false;
    $trimmed = rtrim($name, '/');
    if ($trimmed === '') return false;
    foreach (explode('/', $trimmed) as $segment) if ($segment === '' || $segment === '.' || $segment === '..') return false;
    return preg_match('//u', $name) === 1;
}

function dxf_xml_document(string $xml, string $entry): DOMDocument
{
    if ($xml === '' || strlen($xml) > DXF_DOCX_MAX_ENTRY_BYTES || str_contains($xml, "\0") || preg_match('//u', $xml) !== 1
        || str_starts_with($xml, "\xEF\xBB\xBF") || preg_match('/\A\x{FEFF}/u', $xml)
        || preg_match('/<\?xml[^?]*\bencoding\s*=\s*[\'\"](?!UTF-8[\'\"])[^\'\"]+[\'\"]/i', substr($xml, 0, 512))
        || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new DomainException('Only bounded UTF-8 XML without DTDs or entities is supported in ' . $entry . '.');
    }
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || $document->documentElement === null || ($document->encoding !== null && strcasecmp($document->encoding, 'UTF-8') !== 0)
        || $document->doctype !== null || $document->getElementsByTagName('*')->length > 200000) throw new DomainException('Malformed or excessive XML in ' . $entry . '.');
    $pending = [$document];
    while (($node = array_pop($pending)) instanceof DOMNode) {
        if ($node instanceof DOMEntityReference || $node->nodeType === XML_ENTITY_NODE || $node->nodeType === XML_DOCUMENT_TYPE_NODE) throw new DomainException('XML entity nodes are not supported in ' . $entry . '.');
        for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) $pending[] = $child;
    }
    return $document;
}

function dxf_validate_docx(string $path, ?array $descriptor = null): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is required.');
    if (is_link($path) || !is_file($path) || !is_readable($path)) throw new DomainException('The DOCX upload is unavailable.');
    $archiveSize = filesize($path);
    if ($archiveSize === false || $archiveSize < 22 || $archiveSize > DXF_DOCX_MAX_ARCHIVE_BYTES) throw new DomainException('The DOCX must not exceed 20 MiB.');
    $central = dxf_zip_central_flags($path);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new DomainException('The uploaded file is not a readable DOCX ZIP package.');
    try {
        if ($zip->numFiles !== count($central) || $zip->numFiles > DXF_DOCX_MAX_ENTRIES) throw new DomainException('The DOCX ZIP directory is inconsistent.');
        $names = $folded = [];
        $total = 0;
        $required = ['[Content_Types].xml' => false, '_rels/.rels' => false, 'word/document.xml' => false];
        foreach ($central as $index => $centralEntry) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            $name = $stat['name'] ?? null;
            if (!is_array($stat) || !is_string($name) || $name !== $centralEntry['name'] || !dxf_zip_name_is_safe($name)) throw new DomainException('The DOCX contains an unsafe ZIP entry.');
            $canonical = mb_strtolower($name, 'UTF-8');
            if (isset($folded[$canonical])) throw new DomainException('The DOCX contains duplicate or case-colliding ZIP entries.');
            $folded[$canonical] = true;
            if (($centralEntry['flags'] & 0x0041) !== 0) throw new DomainException('Encrypted DOCX entries are not supported.');
            if (!in_array((int)$centralEntry['method'], [0, 8], true)) throw new DomainException('The DOCX uses an unsupported ZIP compression method.');
            $entrySize = (int)($stat['size'] ?? -1);
            $compressed = (int)($stat['comp_size'] ?? -1);
            if ($entrySize < 0 || $compressed < 0 || $entrySize > DXF_DOCX_MAX_ENTRY_BYTES) throw new DomainException('A DOCX ZIP entry exceeds safety limits.');
            $total += $entrySize;
            if ($total > DXF_DOCX_MAX_TOTAL_BYTES || ($compressed === 0 && $entrySize > 0) || ($compressed > 0 && $entrySize / $compressed > 200)) throw new DomainException('The DOCX expanded size or compression ratio exceeds safety limits.');
            if (isset($required[$name])) $required[$name] = true;
            if (preg_match('~(?:^|/)(?:vbaProject\.bin|vbaData\.xml)$~i', $name)
                || preg_match('~^(?:word/)?(?:activeX|embeddings)/~i', $name)) throw new DomainException('Macros and embedded active content are not supported.');
            $names[] = $name;
        }
        if (in_array(false, $required, true)) throw new DomainException('The DOCX package is missing required Word parts.');

        $xmlParts = [];
        foreach ($names as $name) {
            if (!str_ends_with(strtolower($name), '.xml') && !str_ends_with(strtolower($name), '.rels')) continue;
            $xml = $zip->getFromName($name, 0, ZipArchive::FL_UNCHANGED);
            if (!is_string($xml)) throw new DomainException('A DOCX XML part could not be read.');
            $document = dxf_xml_document($xml, $name);
            if (str_ends_with(strtolower($name), '.rels')) {
                foreach ($document->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
                    if (strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0) throw new DomainException('External DOCX relationships are not supported.');
                    if (preg_match('~/(?:attachedTemplate|oleObject|package|control|externalLink|customXml)$~i', $relationship->getAttribute('Type'))) throw new DomainException('Active or externally bound DOCX relationship types are not supported.');
                    $target = $relationship->getAttribute('Target');
                    $decodedTarget = rawurldecode($target);
                    if ($target === '' || preg_match('/[\x00-\x1F\x7F]/', $decodedTarget) || str_contains($decodedTarget, '\\')
                        || str_starts_with($decodedTarget, '/') || str_contains($decodedTarget, '://') || str_contains(explode('/', $decodedTarget, 2)[0], ':')
                        || preg_match('~(?:^|/)\.\.(?:/|$)~', $decodedTarget)) throw new DomainException('A DOCX relationship target is unsafe.');
                }
            }
            foreach (['altChunk','oleObject','object','control','fldSimple','fldChar','instrText','dataBinding','attachedTemplate','ddeLink','ddeItem','customXml'] as $forbidden) {
                if ($document->getElementsByTagNameNS('*', $forbidden)->length > 0) throw new DomainException('The DOCX contains unsupported fields, bindings, or active content.');
            }
            $xmlParts[$name] = $document;
        }
        $contentTypes = $zip->getFromName('[Content_Types].xml');
        if (!is_string($contentTypes) || stripos($contentTypes, 'macroEnabled') !== false || stripos($contentTypes, 'vbaProject') !== false) throw new DomainException('Macro-enabled DOCX content types are not supported.');

        if ($descriptor !== null) {
            $descriptor = dxf_descriptor_validate($descriptor);
            $declared = array_fill_keys(array_column($descriptor['fields'], 'token'), false);
            foreach ($xmlParts as $name => $document) {
                if (preg_match('~\Aword/(?:document|header[0-9]+|footer[0-9]+|footnotes|endnotes|comments)\.xml\z~', $name) !== 1) continue;
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
                    $text = '';
                    foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) $text .= $node->textContent;
                    if (str_contains($text, '{{') || str_contains($text, '}}')) {
                        preg_match_all('/\{\{[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){0,5}\}\}/', $text, $matches);
                        $stripped = preg_replace('/\{\{[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){0,5}\}\}/', '', $text) ?? $text;
                        if (str_contains($stripped, '{{') || str_contains($stripped, '}}')) throw new DomainException('The DOCX contains a malformed placeholder.');
                        foreach ($matches[0] as $token) {
                            if (!array_key_exists($token, $declared)) throw new DomainException('The DOCX contains a placeholder not declared by the descriptor.');
                            $declared[$token] = true;
                        }
                    }
                }
            }
            if (in_array(false, $declared, true)) throw new DomainException('Every descriptor token must appear in the DOCX.');
        }
        return ['sha256' => hash_file('sha256', $path), 'size' => $archiveSize, 'entries' => count($names), 'expanded_size' => $total];
    } finally {
        $zip->close();
    }
}

function dxf_replace_paragraph_tokens(DOMXPath $xpath, DOMNode $paragraph, array $values): int
{
    $nodes = [];
    $text = '';
    foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
        if (!$node instanceof DOMElement) continue;
        $start = strlen($text);
        $text .= $node->textContent;
        $nodes[] = ['node' => $node, 'start' => $start, 'end' => strlen($text)];
    }
    if ($nodes === [] || preg_match_all('/\{\{[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){0,5}\}\}/', $text, $matches, PREG_OFFSET_CAPTURE) < 1) return 0;
    $replacements = 0;
    foreach (array_reverse($matches[0]) as [$token, $offset]) {
        if (!array_key_exists($token, $values)) continue;
        $endOffset = $offset + strlen($token);
        $startIndex = $endIndex = null;
        foreach ($nodes as $index => $item) {
            if ($startIndex === null && $offset >= $item['start'] && $offset < $item['end']) $startIndex = $index;
            if ($endOffset > $item['start'] && $endOffset <= $item['end']) { $endIndex = $index; break; }
        }
        if ($startIndex === null || $endIndex === null) continue;
        $startNode = $nodes[$startIndex]['node'];
        $endNode = $nodes[$endIndex]['node'];
        $startLocal = $offset - $nodes[$startIndex]['start'];
        $endLocal = $endOffset - $nodes[$endIndex]['start'];
        $prefix = substr($startNode->textContent, 0, $startLocal);
        $suffix = substr($endNode->textContent, $endLocal);
        if ($startIndex === $endIndex) {
            dxf_set_word_text($startNode, $prefix . (string)$values[$token] . $suffix);
        } else {
            dxf_set_word_text($startNode, $prefix . (string)$values[$token]);
            for ($index = $startIndex + 1; $index < $endIndex; $index++) dxf_set_word_text($nodes[$index]['node'], '');
            dxf_set_word_text($endNode, $suffix);
        }
        $replacements++;
    }
    return $replacements;
}

function dxf_set_word_text(DOMElement $element, string $value): void
{
    while ($element->firstChild !== null) $element->removeChild($element->firstChild);
    $element->appendChild($element->ownerDocument->createTextNode($value));
}

function dxf_generate_docx(string $source, array $descriptor, array $fieldValues, string $destination): array
{
    $descriptor = dxf_descriptor_validate($descriptor);
    dxf_validate_docx($source, $descriptor);
    $tokens = [];
    foreach ($descriptor['fields'] as $field) $tokens[$field['token']] = (string)($fieldValues[$field['key']] ?? '');
    if (file_exists($destination) || is_link($destination)) throw new RuntimeException('DOCX output destination already exists.');
    $input = fopen($source, 'rb'); $output = fopen($destination, 'xb');
    if ($input === false || $output === false) throw new RuntimeException('DOCX output stage could not be created.');
    try {
        if (stream_copy_to_stream($input, $output) === false) throw new RuntimeException('DOCX output copy failed.');
    } finally { fclose($input); fclose($output); }
    chmod($destination, 0660);
    $zip = new ZipArchive();
    if ($zip->open($destination) !== true) { @unlink($destination); throw new RuntimeException('DOCX output package could not be opened.'); }
    try {
        $replaced = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name) || preg_match('~\Aword/(?:document|header[0-9]+|footer[0-9]+|footnotes|endnotes|comments)\.xml\z~', $name) !== 1) continue;
            $xml = $zip->getFromIndex($index);
            if (!is_string($xml)) throw new RuntimeException('DOCX output XML could not be read.');
            $document = dxf_xml_document($xml, $name);
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            foreach ($xpath->query('//w:p') ?: [] as $paragraph) $replaced += dxf_replace_paragraph_tokens($xpath, $paragraph, $tokens);
            $serialized = $document->saveXML();
            if (!is_string($serialized) || !$zip->addFromString($name, $serialized)) throw new RuntimeException('DOCX output XML could not be saved.');
        }
        if ($replaced < count($tokens)) throw new RuntimeException('DOCX generation did not replace every required token.');
    } catch (Throwable $error) {
        $zip->close(); @unlink($destination); throw $error;
    }
    if (!$zip->close()) { @unlink($destination); throw new RuntimeException('DOCX output package could not be finalized.'); }
    dxf_validate_docx($destination, null);
    return ['sha256' => hash_file('sha256', $destination), 'size' => filesize($destination) ?: 0, 'replacements' => $replaced];
}
