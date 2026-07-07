<?php
if (!defined('N360_LAYOUT')) {
    exit('Acceso no permitido.');
}

if (!function_exists('n360_base_url')) {
    function n360_base_url(string $path = ''): string {
        $base = defined('N360_BASE_URL') ? N360_BASE_URL : './';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('n360_version')) {
    function n360_version(): string {
        $versionFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'version_n360.txt';
        $version = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : '1.1.10';
        $version = preg_replace('/[^0-9A-Za-z._-]/', '', $version) ?: '1.1.10';

        return $version;
    }
}

if (!function_exists('n360_asset_url')) {
    function n360_asset_url(string $path): string {
        $url = n360_base_url($path);
        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . 'v=' . rawurlencode(n360_version());
    }
}

if (!function_exists('n360_asset')) {
    function n360_asset(string $path): string {
        return htmlspecialchars(n360_asset_url($path), ENT_QUOTES, 'UTF-8');
    }
}