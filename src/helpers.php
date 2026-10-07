<?php
declare(strict_types=1);

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['__config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** HTML çıktısı için kaçış. Ekrana basılan HER kullanıcı/Excel verisi bundan geçer. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Uygulama içi mutlak yol: url('admin/users.php') -> /secim/public/admin/users.php */
function url(string $path = ''): string
{
    return rtrim((string) config('base_url', ''), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_api_request(): bool
{
    return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Her POST'un başında çağrılır. Form alanı (_csrf) ya da X-CSRF-Token başlığı kabul edilir. */
function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $sent)) {
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Oturum doğrulaması başarısız, sayfayı yenileyin.'], 419);
        }
        http_response_code(419);
        exit('Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
    }
}

/* ---------- Flash mesaj ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function take_flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

/* ---------- Zaman ---------- */

function local_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone((string) config('timezone', 'Europe/Istanbul'));
}

/** UTC 'Y-m-d H:i:s' -> yerel biçim. NULL -> '' */
function fmt_local(?string $utc, string $format): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(local_tz())->format($format);
}

function fmt_time(?string $utc): string
{
    return fmt_local($utc, 'H:i');
}

function fmt_datetime(?string $utc): string
{
    return fmt_local($utc, 'd.m.Y H:i');
}

/* ---------- İstek ---------- */

/** İstemci IP'si (binary, VARBINARY(16) için). X-Forwarded-For'a bilerek güvenilmez. */
function client_ip_bin(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $bin = $ip !== '' ? @inet_pton($ip) : false;
    return $bin === false ? null : $bin;
}

function ip_to_text(?string $bin): string
{
    if ($bin === null || $bin === '') {
        return '';
    }
    $txt = @inet_ntop($bin);
    return $txt === false ? '' : $txt;
}

/** Türkçe büyük harfe çevirme (i -> İ, ı -> I). Sadece gösterim/karşılaştırma için. */
function tr_upper(string $s): string
{
    return mb_strtoupper(strtr($s, ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
}

/** Boşlukları sadeleştirir; boş string -> null. Excel'den gelen hücreler için. */
function clean_text(mixed $value, int $maxLen = 160): ?string
{
    if ($value === null) {
        return null;
    }
    $s = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    if ($s === '') {
        return null;
    }
    return mb_substr($s, 0, $maxLen, 'UTF-8');
}
