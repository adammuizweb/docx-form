<?php
declare(strict_types=1);

function dxf_descriptor_text(mixed $value, int $maximum, bool $singleLine = true): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) throw new DomainException('Descriptor text must be valid UTF-8.');
    $pattern = $singleLine ? '/[\x00-\x1F\x7F]/u' : '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';
    $value = trim($value);
    if (preg_match($pattern, $value) || mb_strlen($value, 'UTF-8') > $maximum) throw new DomainException('Descriptor text exceeds its bounds or contains control characters.');
    return $value;
}

function dxf_descriptor_integer(mixed $value, int $minimum, int $maximum, int $default): int
{
    if ($value === null) return $default;
    if (!is_int($value) || $value < $minimum || $value > $maximum) throw new DomainException('A descriptor integer is outside its allowed range.');
    return $value;
}

function dxf_descriptor_decode(string $json): array
{
    if ($json === '' || strlen($json) > 131072) throw new DomainException('The descriptor must be valid JSON no larger than 128 KiB.');
    try {
        $descriptor = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new DomainException('The descriptor is not valid JSON.', 0, $error);
    }
    if (!is_array($descriptor) || array_is_list($descriptor)) throw new DomainException('The descriptor root must be an object.');
    return dxf_descriptor_validate($descriptor);
}

function dxf_descriptor_validate(array $descriptor): array
{
    $allowedRoot = ['schema', 'document_filename', 'fields', 'print'];
    if (array_diff(array_keys($descriptor), $allowedRoot) !== []) throw new DomainException('The descriptor contains unsupported root properties.');
    if (($descriptor['schema'] ?? null) !== 'docx-form/v1') throw new DomainException('The descriptor schema must be docx-form/v1.');
    $filename = dxf_descriptor_text($descriptor['document_filename'] ?? null, 120);
    if ($filename === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._ -]{0,114}\.docx\z/iD', $filename) !== 1
        || str_contains($filename, '..')) throw new DomainException('The document filename is invalid.');
    $inputFields = $descriptor['fields'] ?? null;
    if (!is_array($inputFields) || !array_is_list($inputFields) || $inputFields === [] || count($inputFields) > 100) {
        throw new DomainException('The descriptor requires between 1 and 100 fields.');
    }

    $types = ['text', 'email', 'tel', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox'];
    $identities = ['name', 'email', 'reference'];
    $keys = $tokens = $bindings = [];
    $fields = [];
    foreach ($inputFields as $index => $field) {
        if (!is_array($field) || array_is_list($field)) throw new DomainException('Every descriptor field must be an object.');
        $allowed = ['key', 'label', 'type', 'required', 'max_length', 'token', 'help', 'options', 'identity'];
        if (array_diff(array_keys($field), $allowed) !== []) throw new DomainException('A descriptor field contains unsupported properties.');
        $key = dxf_scalar($field['key'] ?? '');
        if (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){0,5}\z/D', $key) !== 1 || strlen($key) > 80 || isset($keys[$key])) {
            throw new DomainException('Descriptor field keys must be unique dotted identifiers.');
        }
        $label = dxf_descriptor_text($field['label'] ?? null, 160);
        $type = dxf_scalar($field['type'] ?? '');
        if ($label === '' || !in_array($type, $types, true)) throw new DomainException('Every descriptor field requires a label and supported type.');
        $maximumDefault = $type === 'textarea' ? 5000 : ($type === 'checkbox' ? 1 : 500);
        $maximumLimit = $type === 'textarea' ? 10000 : ($type === 'checkbox' ? 1 : 1000);
        $maximum = dxf_descriptor_integer($field['max_length'] ?? null, 1, $maximumLimit, $maximumDefault);
        $token = dxf_scalar($field['token'] ?? '{{' . $key . '}}');
        if ($token !== '{{' . $key . '}}' || isset($tokens[$token])) throw new DomainException('Template tokens must exactly match their unique field key.');
        $identity = $field['identity'] ?? null;
        if ($identity !== null && (!is_string($identity) || !in_array($identity, $identities, true) || isset($bindings[$identity]))) {
            throw new DomainException('Identity bindings must be unique name, email, or reference bindings.');
        }
        if ($identity === 'email' && $type !== 'email') throw new DomainException('The email identity binding requires an email field.');
        $options = [];
        if (in_array($type, ['select', 'radio'], true)) {
            $rawOptions = $field['options'] ?? null;
            if (!is_array($rawOptions) || !array_is_list($rawOptions) || count($rawOptions) < 1 || count($rawOptions) > 50) {
                throw new DomainException('Select and radio fields require between 1 and 50 options.');
            }
            foreach ($rawOptions as $option) {
                if (!is_array($option) || array_is_list($option) || array_diff(array_keys($option), ['value', 'label']) !== []) {
                    throw new DomainException('Descriptor options must contain only value and label.');
                }
                $value = dxf_descriptor_text($option['value'] ?? null, 160);
                $optionLabel = dxf_descriptor_text($option['label'] ?? null, 160);
                if ($value === '' || $optionLabel === '' || isset($options[$value])) throw new DomainException('Descriptor option values must be unique bounded text.');
                $options[$value] = $optionLabel;
            }
        } elseif (array_key_exists('options', $field)) {
            throw new DomainException('Only select and radio fields may define options.');
        }
        if (array_key_exists('required', $field) && !is_bool($field['required'])) throw new DomainException('Descriptor required flags must be booleans.');
        $normalized = [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => ($field['required'] ?? false) === true,
            'max_length' => $maximum,
            'token' => $token,
            'help' => dxf_descriptor_text($field['help'] ?? '', 500, false),
        ];
        if ($options !== []) $normalized['options'] = array_map(static fn(string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($options), $options);
        if ($identity !== null) { $normalized['identity'] = $identity; $bindings[$identity] = $key; }
        $fields[] = $normalized;
        $keys[$key] = $index;
        $tokens[$token] = true;
    }

    $print = $descriptor['print'] ?? [];
    if (!is_array($print) || ($print !== [] && array_is_list($print)) || array_diff(array_keys($print), ['title', 'columns', 'show_description']) !== []) {
        throw new DomainException('Print layout hints are invalid.');
    }
    if (array_key_exists('show_description', $print) && !is_bool($print['show_description'])) throw new DomainException('The print description flag must be boolean.');
    $print = [
        'title' => dxf_descriptor_text($print['title'] ?? '', 160),
        'columns' => dxf_descriptor_integer($print['columns'] ?? null, 1, 2, 1),
        'show_description' => $print['show_description'] ?? true,
    ];
    return ['schema' => 'docx-form/v1', 'document_filename' => $filename, 'fields' => $fields, 'print' => $print];
}

function dxf_descriptor_json(array $descriptor): string
{
    return json_encode(dxf_descriptor_validate($descriptor), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dxf_values_json(array $descriptor, array $values): string
{
    $ordered = [];
    foreach (dxf_descriptor_validate($descriptor)['fields'] as $field) {
        $key = (string)$field['key'];
        if (!array_key_exists($key, $values) || !is_string($values[$key])) throw new DomainException('Submitted values are not canonical.');
        $ordered[$key] = $values[$key];
    }
    if (count($ordered) !== count($values)) throw new DomainException('Submitted values contain unsupported keys.');
    return json_encode($ordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dxf_identity_json(array $identity): string
{
    $allowed = ['name', 'email', 'reference', 'email_verified'];
    if (array_diff(array_keys($identity), $allowed) !== []) throw new DomainException('Submission identity contains unsupported keys.');
    $ordered = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $identity)) continue;
        $value = $identity[$key];
        if ($key === 'email_verified') {
            if (!is_bool($value)) throw new DomainException('Submission identity verification state is invalid.');
        } elseif (!is_string($value)) {
            throw new DomainException('Submission identity values must be text.');
        }
        $ordered[$key] = $value;
    }
    return json_encode($ordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function dxf_validate_values(array $descriptor, mixed $input, string $identityMode, ?string $verifiedEmail = null, bool $allowIncomplete = false): array
{
    $descriptor = dxf_descriptor_validate($descriptor);
    if (!is_array($input) || array_is_list($input) || count($input) > 100) throw new DomainException('Submitted values must be a bounded object.');
    $allowedKeys = array_column($descriptor['fields'], 'key');
    if (array_diff(array_keys($input), $allowedKeys) !== []) throw new DomainException('The submission contains unknown fields.');
    $values = $identity = [];
    foreach ($descriptor['fields'] as $field) {
        $raw = $input[$field['key']] ?? null;
        if ($field['type'] === 'checkbox') {
            if (!in_array($raw, [true, false, 1, 0, '1', '0', 'on', null], true)) throw new DomainException($field['label'] . ' is invalid.');
            $value = in_array($raw, [true, 1, '1', 'on'], true) ? 'Yes' : 'No';
            if (!$allowIncomplete && $field['required'] && $value !== 'Yes') throw new DomainException($field['label'] . ' is required.');
        } else {
            if (!is_string($raw) && !is_int($raw) && !is_float($raw) && $raw !== null) throw new DomainException($field['label'] . ' must be plain text.');
            $rawText = dxf_scalar($raw ?? '');
            $pattern = $field['type'] === 'textarea' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
            if (preg_match('//u', $rawText) !== 1 || preg_match($pattern, $rawText)
                || mb_strlen(trim($rawText), 'UTF-8') > (int)$field['max_length']) throw new DomainException($field['label'] . ' is invalid or too long.');
            $value = dxf_text($raw ?? '', (int)$field['max_length'], $field['type'] !== 'textarea');
            if (!$allowIncomplete && $field['required'] && $value === '') throw new DomainException($field['label'] . ' is required.');
            if ($field['type'] === 'email' && ($value !== '' && (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $value)))) throw new DomainException($field['label'] . ' is not a valid email address.');
            if ($field['type'] === 'tel' && $value !== '' && preg_match('/\A[0-9+().\- ]{3,40}\z/D', $value) !== 1) throw new DomainException($field['label'] . ' is not a valid telephone number.');
            if ($field['type'] === 'number' && $value !== '' && (!is_numeric($value) || !is_finite((float)$value))) throw new DomainException($field['label'] . ' is not a valid number.');
            if ($field['type'] === 'date' && $value !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
                $errors = DateTimeImmutable::getLastErrors();
                if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) throw new DomainException($field['label'] . ' is not a valid date.');
            }
            if (in_array($field['type'], ['select', 'radio'], true) && $value !== '' && !in_array($value, array_column($field['options'], 'value'), true)) throw new DomainException($field['label'] . ' has an invalid option.');
        }
        $values[$field['key']] = $value;
        if (isset($field['identity'])) $identity[$field['identity']] = $value;
    }
    if ($allowIncomplete) {
        if (!in_array($identityMode, ['email_otp','self_declared'], true)) throw new DomainException('The identity mode is invalid.');
        if ($identityMode === 'email_otp' && (!is_string($verifiedEmail) || (isset($identity['email']) && $identity['email'] !== '' && !hash_equals(strtolower($verifiedEmail), strtolower($identity['email']))))) throw new DomainException('A verified email identity is required.');
        return ['values' => $values, 'identity' => $identity];
    }
    if ($identityMode === 'email_otp') {
        if (!isset($identity['email']) || $identity['email'] === '' || $verifiedEmail === null || !hash_equals(strtolower($verifiedEmail), strtolower($identity['email']))) {
            throw new DomainException('A verified email identity is required.');
        }
        $identity['email_verified'] = true;
    } elseif ($identityMode === 'self_declared') {
        $identity['email_verified'] = false;
    } else {
        throw new DomainException('The identity mode is invalid.');
    }
    return ['values' => $values, 'identity' => $identity];
}
