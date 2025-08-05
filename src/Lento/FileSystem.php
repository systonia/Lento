<?php

namespace Lento;

use ReflectionClass;
use RuntimeException;

use Lento\Router;

/**
 * Undocumented class
 */
final class FileSystem
{
    /**
     *
     */
    private const ROUTES_FILE = 'routes.php';

    /**
     *
     */
    private const META_FILE = 'meta.php';

    /**
     *
     */
    private const ATTRIBUTES_FILE = 'attributes.php';

    /**
     * Undocumented variable
     *
     * @var string|null
     */
    private static ?string $public_directory = null;

    /**
     * Undocumented variable
     *
     * @var boolean|null
     */
    public static ?bool $public_enabled = false;

    /**
     * Undocumented variable
     *
     * @var string|null
     */
    private static ?string $cache_directory = null;

    /**
     * Undocumented variable
     *
     * @var boolean|null
     */
    public static ?bool $cache_enabled = false;

    /**
     * Undocumented function
     *
     * @param string $path
     * @return boolean
     */
    private static function isAbsolutePath(string $path): bool
    {
        // Unix absolute or Windows absolute (C:\ or D:/)
        return (
            isset($path[0]) && ($path[0] === '/' || $path[0] === '\\') ||
            preg_match('/^[a-zA-Z]:[\/\\\\]/', $path)
        );
    }

    /**
     * Undocumented function
     *
     * @param string $directory
     * @return string
     */
    private static function buildPath(string $directory): string {
        $cleanDirectory = rtrim($directory, '/\\');
        if (self::isAbsolutePath($cleanDirectory)) {
            return rtrim($cleanDirectory, '/\\');
        } else {
            return dirname(realpath($_SERVER['SCRIPT_FILENAME'])) . '/' . $cleanDirectory;
        }
    }

    /**
     * Undocumented function
     *
     * @param string $directory
     * @return self
     */
    public static function setCacheDirectory(string $directory): self
    {
        $cleanDirectory = rtrim($directory, '/\\');
        self::$cache_directory = self::buildPath($cleanDirectory);

        self::$cache_enabled = true;

        return new self();
    }

    /**
     * Undocumented function
     *
     * @param string $directory
     * @return self
     */
    public static function setPublicDirectory(string $directory): self
    {
        $cleanDirectory = rtrim($directory, '/\\');
        self::$public_directory = self::buildPath($cleanDirectory);

        self::$public_enabled = true;

        return new self();
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public static function getCacheDirectory(): string
    {
        return self::$cache_directory ?: (sys_get_temp_dir() . '/lentocache');
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public static function getPublicDirectory(): string
    {
        return self::$public_directory ?: throw new RuntimeException("ToDo: error message");
    }

    public static function getRouteFile(): string
    {
        return self::getCacheDirectory() . '/' . self::ROUTES_FILE;
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public static function getMetaFile(): string
    {
        return self::getCacheDirectory() . '/' . self::META_FILE;
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public static function getAttributesFile(): string
    {
        return self::getCacheDirectory() . '/' . self::ATTRIBUTES_FILE;
    }

    /**
     * Undocumented function
     *
     * @param array $controllers
     * @return boolean
     */
    public static function isAvailable(array $controllers): bool
    {
        $routeFile = self::getRouteFile();
        $metaFile = self::getMetaFile();
        $attributesFile = self::getAttributesFile();

        foreach ([$routeFile, $metaFile, $attributesFile] as $file) {
            if (!is_string($file) || !is_file($file)) {
                return false;
            }
        }

        $storedMeta = @require $metaFile;
        if (!is_array($storedMeta)) {
            return false;
        }

        foreach ($controllers as $controller) {
            if (!class_exists($controller)) {
                continue;
            }

            $rc = new ReflectionClass($controller);
            $file = $rc->getFileName();

            if (!$file || !file_exists($file)) {
                return false;
            }

            $mtime = filemtime($file);

            if (!isset($storedMeta[$file]) || $storedMeta[$file] !== $mtime) {
                return false;
            }
        }
        return true;
    }

    /**
     * Undocumented function
     *
     * @param Router $router
     * @param array $controllers
     * @param array $serviceClasses
     * @return void
     */
    public static function storeFromRouter(Router $router, array $controllers, array $serviceClasses): void
    {
        $dir = self::getCacheDirectory();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $data = $router->exportCompiledPlans();
        $data['services'] = $serviceClasses;

        $header = "<?php\n// AUTO-GENERATED FILE - DO NOT EDIT\n\n";
        file_put_contents($dir . '/' . self::ROUTES_FILE, $header . 'return ' . var_export($data, true) . ';');

        $meta = [];
        foreach ($controllers as $controller) {
            if (!class_exists($controller))
                continue;
            $rc = new ReflectionClass($controller);
            $file = $rc->getFileName();
            if ($file && file_exists($file)) {
                $meta[$file] = filemtime($file);
            }
        }
        file_put_contents($dir . '/' . self::META_FILE, $header . 'return ' . var_export($meta, true) . ';');

        self::storeAttributes($controllers);
    }

    /**
     * Undocumented function
     *
     * @param array $controllers
     * @return void
     */
    public static function storeAttributes(array $controllers): void
    {
        $dir = self::getCacheDirectory();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $attributes = Router::exportAllAttributes($controllers);

        $header = "<?php\n// AUTO-GENERATED FILE - DO NOT EDIT\n\n";
        file_put_contents($dir . '/' . self::ATTRIBUTES_FILE, $header . 'return ' . var_export($attributes, true) . ';');
    }

    /**
     * Undocumented function
     *
     * @return array
     */
    public static function loadAttributes(): array
    {
        $file = self::getAttributesFile();
        if (!file_exists($file)) {
            return [];
        }

        return require $file;
    }

    /**
     * Undocumented function
     *
     * @param Router $router
     * @return void
     */
    public static function loadToRouter(Router $router): void
    {
        $routeFile = self::getRouteFile();
        if (!file_exists($routeFile)) {
            return;
        }
        $data = require $routeFile;
        $router->importCompiledPlans($data);
    }
}
