<?php
declare(strict_types=1);

function dxf_h(mixed $value): string
{
    return str_replace(['[', ']'], ['&#91;', '&#93;'], htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
}

function dxf_scalar(mixed $value): string
{
    return is_string($value) || is_int($value) || is_float($value) ? (string)$value : '';
}

function dxf_text(mixed $value, int $maximum, bool $singleLine = true): string
{
    $value = trim(dxf_scalar($value));
    if (preg_match('//u', $value) !== 1) return '';
    $pattern = $singleLine ? '/[\x00-\x1F\x7F]+/u' : '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';
    $value = preg_replace($pattern, $singleLine ? ' ' : '', $value) ?? '';
    return mb_substr(trim($value), 0, $maximum, 'UTF-8');
}

function dxf_strict_text(mixed $value, int $maximum, bool $singleLine = true): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) throw new DomainException('Text input is invalid.');
    $value = trim($value);
    $pattern = $singleLine ? '/[\x00-\x1F\x7F]/u' : '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';
    if (preg_match($pattern, $value) || mb_strlen($value, 'UTF-8') > $maximum) throw new DomainException('Text input is invalid or too long.');
    return $value;
}

function dxf_slug(mixed $value): ?string
{
    $value = strtolower(trim(dxf_scalar($value)));
    return strlen($value) <= 100 && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?\z/D', $value) === 1 ? $value : null;
}

function dxf_positive_id(mixed $value): int
{
    if (!is_string($value) && !is_int($value)) return 0;
    $valid = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $valid === false ? 0 : (int)$valid;
}

function dxf_bounded_int(mixed $value, int $minimum, int $maximum, int $default): int
{
    if (!is_string($value) && !is_int($value)) return $default;
    $valid = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
    return $valid === false ? $default : (int)$valid;
}

function dxf_bool(mixed $value): int
{
    return in_array(dxf_scalar($value), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}

function dxf_token(mixed $value, int $bytes = 32): ?string
{
    $value = strtolower(trim(dxf_scalar($value)));
    return strlen($value) === $bytes * 2 && preg_match('/\A[a-f0-9]+\z/D', $value) === 1 ? $value : null;
}

function dxf_safe_download_name(string $value, string $extension): string
{
    $value = dxf_text($value, 120);
    $value = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $value) ?? '';
    $value = trim($value, '. -');
    if ($value === '') $value = 'document';
    $suffix = '.' . strtolower($extension);
    if (!str_ends_with(strtolower($value), $suffix)) $value .= $suffix;
    return substr($value, 0, 140);
}

function dxf_now_utc(): string
{
    return function_exists('app_now_utc_mysql') ? app_now_utc_mysql() : gmdate('Y-m-d H:i:s');
}

function dxf_t(string $source, mixed ...$arguments): string
{
    $translated = function_exists('__') ? __($source) : $source;
    if ($translated === $source) {
        $locale = function_exists('get_locale') ? strtolower((string)get_locale()) : 'en';
        $locale = explode('-', str_replace('_', '-', $locale), 2)[0];
        if (in_array($locale, ['id', 'de'], true)) {
            static $catalogs = [];
            if (!isset($catalogs[$locale])) {
                $file = dirname(__DIR__) . '/languages/' . $locale . '.php';
                $catalogs[$locale] = is_file($file) ? (array)require $file : [];
            }
            $translated = (string)($catalogs[$locale][$source] ?? $source);
        }
    }
    return $arguments === [] ? $translated : sprintf($translated, ...$arguments);
}
