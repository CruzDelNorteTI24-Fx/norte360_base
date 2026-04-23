<?php
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        $vars = parse_ini_file($path, false, INI_SCANNER_RAW);
        foreach ($vars as $key => $value) {
            $_ENV[$key] = $value;
        }
    }
}
?>
