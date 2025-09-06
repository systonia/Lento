<?php

namespace Lento;

use Throwable;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use InvalidArgumentException;

use Lento\{Router};
use Lento\Attributes\Inject;
use Lento\Http\{Request, Response};

class App
{
    private Router $router;
    private array $middlewares = [];
    private Container $container;

    public function __construct() {
        $this->container = new Container();
    }

    public function attach(array $controllers): void
    {
        $allClasses = $this->discoverAllClasses($controllers);
        $this->initDependencyInjection($allClasses);

        $this->router = new Router($controllers, $allClasses, $this->container);

        $this->useLentoAcceptHeader();
    }

    public function use(callable $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function useCors(array $options): void
    {
        $this->use(function (Request $req, Response $res, $next) use ($options): mixed {
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

    private function useLentoAcceptHeader(): void
    {
        $this->use(function (Request $req, Response $res, $next): mixed {
            $headersLower = array_change_key_case($req->headers, CASE_LOWER);
            $req->acceptPartial = (
                isset($headersLower['x-lento-accept']) &&
                strtolower($headersLower['x-lento-accept']) === 'partial'
            );
            return $next($req, $res);
        });
    }

    public function useJwt(): void
    {
        $this->use(function (Request $req, Response $res, $next) {
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

    public function run(): void
    {
        // Detect CLI
        if (php_sapi_name() === 'cli') {
            global $argv, $argc;

            if ($argc < 2) {
                echo "No command given\n";
                exit(1);
            }

            echo "Hello World\n" . json_encode($argv) . json_encode($argc);
            #ToDo: resolve Task by name and execute!
            exit;
        }

        $req = Request::capture();
        $res = new Response();

        $handler = array_reduce(
            array_reverse($this->middlewares),
            fn(callable $next, callable $mw): callable =>
            fn(Request $req, Response $res) => $mw($req, $res, $next),
            fn(Request $req, Response $res) => $this->handle($req, $res)
        );

        $handler($req, $res);
    }

    private function handle(Request $req, Response $res): Response
    {
        $this->router->dispatch($req->path, $req->method, $req, $res);
        return $res;
    }

    private function initDependencyInjection(array $classes): void
    {
        foreach ($classes as $cls) {
            if (!class_exists($cls))
                continue;
            $this->container->set(new $cls());
        }
    }

    private function discoverAllClasses(array $controllers): array
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

    public function get(string $class): mixed
    {
        try {
            return $this->container->get($class);
        } catch (Throwable) {
            return null;
        }
    }

    public function getRouter(): Router
    {
        return $this->router;
    }
    public function getContainer(): Container
    {
        return $this->container;
    }

    public function configure(callable $config): void
    {
        $reflection = new ReflectionFunction($config);
        $args = [];

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new InvalidArgumentException(
                    "Closure parameters must be type-hinted with a non-builtin Options class"
                );
            }

            $args[] = $this->container->get($type->getName());
        }

        $config(...$args);
    }
}
