<?php

namespace Lento;

final class Env
{
    /**
     * All env keys/values managed by this class.
     *
     * @var array
     */
    private static array $data = [];

    /**
     * Clears the Env to initial empty state.
     */
    public static function clear(): void
    {
        self::$data = [];
    }

    /**
     * Loads environment variables from system, then extends with server, .env files.
     * Does not mutate any global state.
     */
    public static function load(string $dir = __DIR__): void
    {
        self::$data = [];

        // 1. Load from system env vars
        foreach (self::readSystemEnv() as $key => $val) {
            self::$data[$key] = $val;
        }

        // 2. Overlay $_ENV
        foreach ($_ENV as $key => $val) {
            self::$data[$key] = $val;
        }

        // 3. Overlay $_SERVER
        foreach ($_SERVER as $key => $val) {
            if (is_string($val)) { // Prevents object/array pollution
                self::$data[$key] = $val;
            }
        }

        // 4. Overlay base .env file
        $basePath = rtrim($dir, '/\\');
        self::extendWithEnvFile("$basePath/.env");

        // 5. Detect environment (from merged so far, or detect)
        $env = self::$data['APP_ENV'] ?? self::detectEnvironment();
        self::$data['APP_ENV'] = $env;

        // 6. Overlay .env.$env file
        self::extendWithEnvFile("$basePath/.env.$env");
    }

    /**
     * Adds/overrides a variable in Env storage only.
     */
    public static function set(string $key, string $value): void
    {
        self::$data[$key] = $value;
    }

    /**
     * Get variable from Env storage, or default if not present.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (count(self::$data) === 0) {
            self::load();
        }

        return self::$data[$key] ?? $default;
    }

    /**
     * Checks if the current environment is "development".
     */
    public static function isDev(): bool
    {
        return (self::$data['APP_ENV'] ?? 'production') === 'development';
    }

    // --- Private helpers ---

    /**
     * Returns getenv() as an array (if available).
     */
    private static function readSystemEnv(): array
    {
        $env = getenv();
        return is_array($env) ? $env : [];
    }

    /**
     * Parse and extend Env with a given .env file (does not overwrite existing keys unless force).
     */
    private static function extendWithEnvFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $handle = @fopen($file, 'r');
        if (!$handle) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $equals = strpos($line, '=');
            if ($equals === false) {
                continue;
            }

            $key = trim(substr($line, 0, $equals));
            $val = trim(substr($line, $equals + 1), " \t\n\r\0\x0B\"'");

            self::$data[$key] = $val;
        }

        fclose($handle);
    }

    /**
     * Determines current environment as a fallback.
     *
     * @return string
     */
    private static function detectEnvironment(): string
    {
        static $result;
        if ($result !== null) {
            return $result;
        }
        if (php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server') {
            return $result = 'development';
        }
        // @codeCoverageIgnoreStart
        if (!empty($_SERVER['SERVER_NAME']) && str_contains($_SERVER['SERVER_NAME'], 'localhost')) {
            return $result = 'development';
        }
        if (extension_loaded('xdebug')) {
            return $result = 'development';
        }
        return $result = 'production';
        // @codeCoverageIgnoreEnd
    }
}
