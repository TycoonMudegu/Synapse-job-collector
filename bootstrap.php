<?php

declare(strict_types=1);

define("BASE_PATH", __DIR__);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, "App\\")) {
        $path = BASE_PATH . "/" . str_replace("\\", "/", $class) . ".php";
        if (is_file($path)) {
            require $path;
        }
    }
});
umask(0077);
date_default_timezone_set("Africa/Nairobi");
ini_set("display_errors", "0");
