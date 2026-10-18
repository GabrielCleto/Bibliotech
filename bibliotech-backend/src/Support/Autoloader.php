<?php
declare(strict_types=1);

/**
 * Autoloader PSR-4 nativo de alta performance para o Bibliotech.
 * Mapeia namespaces e classes sem depender de Composer obrigatório.
 */
final class Autoloader
{
    private static bool $registered = false;
    private static string $baseDir;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$baseDir = dirname(__DIR__, 2);

        spl_autoload_register([self::class, 'loadClass']);
        self::$registered = true;
    }

    public static function loadClass(string $class): bool
    {
        // 1. Tratamento de namespace PSR-4: Bibliotech\...
        $prefix = 'Bibliotech\\';
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = self::$baseDir . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }

        // 2. Fallback para classes legadas de models/
        $modelFile = self::$baseDir . '/models/' . $class . '.php';
        if (file_exists($modelFile)) {
            require_once $modelFile;
            return true;
        }

        // 3. Fallback para classes de config/
        $configFile = self::$baseDir . '/config/' . $class . '.php';
        if (file_exists($configFile)) {
            require_once $configFile;
            return true;
        }

        // 4. Fallback para controllers/services/support diretos
        $locations = ['Controllers', 'Services', 'Support'];
        foreach ($locations as $loc) {
            $srcFile = self::$baseDir . '/src/' . $loc . '/' . $class . '.php';
            if (file_exists($srcFile)) {
                require_once $srcFile;
                return true;
            }
        }

        return false;
    }
}

Autoloader::register();
