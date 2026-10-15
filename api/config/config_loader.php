<?php
// Loads config.php once and reuses it.
function loadConfig(): array
{
    static $config = null;

    if ($config === null) {
        $file = __DIR__ . '/config.php';
        if (!file_exists($file)) {
            throw new RuntimeException('Missing api/config/config.php. Copy config.example.php to config.php.');
        }
        $config = require $file;
    }

    return $config;
}
