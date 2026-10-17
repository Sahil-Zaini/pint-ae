<?php
// Use this if you are not using Composer (e.g. plain shared hosting):
//   require __DIR__ . '/pint-ae/autoload.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PintAE\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
