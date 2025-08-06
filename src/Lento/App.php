<?php

namespace Lento;

use Throwable;
use ReflectionClass;

use Lento\Router;
use Lento\Attributes\Inject;
use Lento\Http\{Request, Response};
use Lento\OpenAPI\OpenAPIController;

class App
{
    private static Router $router;
    private static array $middlewares = [];
    private static Container $container;

    /**
     * @param array<class-string> $controllers
     */
    public static function create(array $controllers): void
    {
        if (OpenAPI::isEnabled()) {
            $controllers[] = OpenAPIController::class;
        }

        $allClasses = self::discoverAllClasses($controllers);
        self::initDependencyInjection($allClasses);

        self::$router = Router::boot($controllers, $allClasses, self::$container);

        self::useLentoAcceptHeader();
    }

    public static function use(callable $middleware): void
    {
        self::$middlewares[] = $middleware;
    }

    public static function useCors(array $options): void
    {
        self::use(function (Request $req, Response $res, $next) use ($options): mixed {
            foreach (['allowOrigin', 'allowMethods', 'allowHeaders', 'allowCredentials'] as $opt) {
                if (isset($options[$opt])) {
                    $header = str_replace('allow', 'Access-Control-', $opt);
                    $res = $res->withHeader($header, (string) $options[$opt]);
                }
            }
            if ($req->method === 'OPTIONS') {
                http_response_code(204);
                return $res;
            }
            return $next($req, $res);
        });
    }

    private static function useLentoAcceptHeader(): void
    {
        self::use(function (Request $req, Response $res, $next): mixed {
            $headersLower = array_change_key_case($req->headers, CASE_LOWER);
            $req->acceptPartial = (
                isset($headersLower['x-lento-accept']) &&
                strtolower($headersLower['x-lento-accept']) === 'partial'
            );
            return $next($req, $res);
        });
    }

    public static function useJwt(): void
    {
        self::use(function (Request $req, Response $res, $next) {
            // Ensure "Authorization" fallback is always applied
            if (isset($_SERVER['AUTHORIZATION']) && !isset($req->headers['Authorization'])) {
                $req->headers['Authorization'] = $_SERVER['AUTHORIZATION'];
            }

            // Attempt to parse JWT
            $payload = JWT::fromRequestHeaders($req->headers);
            if ($payload !== null) {
                $req->jwt = $payload;
            }

            return $next($req, $res);
        });
    }

    public static function run(): void
    {
        $req = Request::capture();
        $res = new Response();

        $handler = array_reduce(
            array_reverse(self::$middlewares),
            fn(callable $next, callable $mw): callable =>
            fn(Request $req, Response $res) => $mw($req, $res, $next),
            fn(Request $req, Response $res) => self::handle($req, $res)
        );

        $handler($req, $res);
    }

    private static function handle(Request $req, Response $res): Response
    {
        self::$router->dispatch($req->path, $req->method, $req, $res);
        return $res;
    }

    private static function initDependencyInjection(array $classes): void
    {
        self::$container = new Container();
        foreach ($classes as $cls) {
            if (!class_exists($cls))
                continue;
            self::$container->set(new $cls());
        }
    }

    private static function discoverAllClasses(array $controllers): array
    {
        $all = $controllers;
        $queue = $controllers;
        $found = [];

        while ($queue) {
            $class = array_shift($queue);
            if (!class_exists($class) || isset($found[$class]))
                continue;
            $found[$class] = true;

            $rc = new ReflectionClass($class);

            foreach ($rc->getProperties() as $prop) {
                foreach ($prop->getAttributes(Inject::class) as $attr) {
                    $type = $prop->getType()?->getName();
                    if ($type && !in_array($type, $all, true)) {
                        $all[] = $type;
                        $queue[] = $type;
                    }
                }
            }

            $constructor = $rc->getConstructor();
            if ($constructor) {
                foreach ($constructor->getParameters() as $param) {
                    foreach ($param->getAttributes(Inject::class) as $attr) {
                        $type = $param->getType()?->getName();
                        if ($type && !in_array($type, $all, true)) {
                            $all[] = $type;
                            $queue[] = $type;
                        }
                    }
                }
            }
        }

        return $all;
    }

    public static function get(string $class): T|null
    {
        try {
            return self::$container->get($class);
        } catch (Throwable) {
            return null;
        }
    }

    public static function getRouter(): Router
    {
        return self::$router;
    }
}
