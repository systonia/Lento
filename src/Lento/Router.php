<?php

namespace Lento;

use ReflectionClass;
use ReflectionProperty;
use Lento\Container;
use Lento\Logger;
use Lento\Http\Request;
use Lento\Http\Response;
use Lento\Http\View;
use Lento\Validator;
use Lento\Exceptions\ValidationException;
use Lento\FileSystem;
use Lento\Attributes\{
    Inject, Body, Param, Query, Controller, FileFormatter, JSONFormatter, SimpleXmlFormatter
};

class Router
{
    public array $staticRoutes = [];
    public array $dynamicRoutes = [];
    private ?Container $container = null;

    // -- Container Setter --

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    // -- Route Management --

    public function addCompiledRoute(array $plan, bool $dynamic): void
    {
        $httpMethod = $plan['httpMethod'];
        if ($dynamic) {
            $this->dynamicRoutes[$httpMethod][] = $plan;
        } else {
            $this->staticRoutes[$httpMethod][$plan['path']] = $plan;
        }
    }

    public function exportCompiledPlans(): array
    {
        return [
            'staticRoutes' => $this->staticRoutes,
            'dynamicRoutes' => $this->dynamicRoutes,
        ];
    }

    public function importCompiledPlans(array $data): void
    {
        $this->staticRoutes = $data['staticRoutes'] ?? [];
        $this->dynamicRoutes = $data['dynamicRoutes'] ?? [];
    }

    // -- Public Entry Point: Boot --

    public static function boot(array $controllers, array $serviceClasses = [], ?Container $container = null): self
    {
        $router = new self();
        if ($container) {
            $router->setContainer($container);
        }

        if (!FileSystem::isAvailable($controllers)) {
            $plans = self::compileRoutePlans($controllers);
            foreach ($plans['staticRoutes'] as $method => $routes) {
                foreach ($routes as $plan) {
                    $router->addCompiledRoute($plan, false);
                }
            }
            foreach ($plans['dynamicRoutes'] as $method => $routes) {
                foreach ($routes as $plan) {
                    $router->addCompiledRoute($plan, true);
                }
            }
            FileSystem::storeFromRouter($router, $controllers, $serviceClasses);
        } else {
            FileSystem::loadToRouter($router);
        }
        return $router;
    }

    // -- Main Dispatch Method --

    public function dispatch(string $uri, string $httpMethod, Request $req, Response $res): void
    {
        $path = $this->normalizePath($uri);

        if ($this->tryServeStaticFile($path, $res)) {
            return;
        }

        [$route, $params] = $this->matchRoute($httpMethod, $path);

        if (!$route) {
            $this->respondNotFound($res);
            return;
        }

        $controller = $this->resolveController($route['controller']);
        $this->injectControllerProperties($controller, $route['propInject'], $req, $res);

        try {
            $args = $this->buildMethodArguments($route['argPlan'], $req, $res, $params);
        } catch (ValidationException $e) {
            $res->status(400)
                ->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Validation failed', 'details' => $e->getErrors()]))
                ->send();
            return;
        }

        $result = $controller->{$route['method']}(...$args);
        $this->renderResult($result, $res, $route['formatter'] ?? ['type' => 'json', 'options' => null]);
    }

    // -- Private Helpers for Dispatch --

    private function normalizePath(string $uri): string
    {
        return '/' . ltrim(rtrim($uri, '/'), '/');
    }

    private function tryServeStaticFile(string $path, Response $res): bool
    {
        if (!FileSystem::$public_enabled) {
            return false;
        }
        $publicPath = FileSystem::getPublicDirectory();
        $filePath = realpath($publicPath . $path);
        if (
            $filePath
            && str_starts_with($filePath, realpath($publicPath))
            && is_file($filePath)
        ) {
            $mime = mime_content_type($filePath) ?: 'application/octet-stream';
            $res->withHeader('Content-Type', $mime)
                ->write(file_get_contents($filePath))
                ->send();
            return true;
        }
        return false;
    }

    private function matchRoute(string $httpMethod, string $path): array
    {
        $m = strtoupper($httpMethod);
        $params = [];
        $route = $this->staticRoutes[$m][$path] ?? null;

        if ($route) {
            return [$route, $params];
        }

        foreach ($this->dynamicRoutes[$m] ?? [] as $entry) {
            if (preg_match($entry['regex'], $path, $matches)) {
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }
                return [$entry, $params];
            }
        }

        return [null, []];
    }

    private function respondNotFound(Response $res): void
    {
        $res->status(404)
            ->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['error' => 'Not found']))
            ->send();
    }

    private function resolveController(string $class)
    {
        return $this->container ? $this->container->get($class) : new $class();
    }

    private function injectControllerProperties(object $controller, array $propInject, Request $req, Response $res): void
    {
        foreach ($propInject as $p) {
            $propName = $p['name'];
            $type = $p['type'];
            $refProp = new ReflectionProperty(get_class($controller), $propName);
            $refProp->setAccessible(true);

            switch ($type) {
                case Request::class:
                    $refProp->setValue($controller, $req);
                    break;
                case Response::class:
                    $refProp->setValue($controller, $res);
                    break;
                case self::class:
                    $refProp->setValue($controller, $this);
                    break;
                default:
                    if ($this->container && class_exists($type)) {
                        $refProp->setValue($controller, $this->container->get($type));
                    }
            }
        }
    }

    private function buildMethodArguments(array $argPlan, Request $req, Response $res, array $params): array
    {
        $args = [];
        foreach ($argPlan as $arg) {
            switch ($arg['inject']) {
                case 'Request':
                    $args[] = $req;
                    break;
                case 'Response':
                    $args[] = $res;
                    break;
                case 'Route':
                    $args[] = $params[$arg['name']] ?? null;
                    break;
                case 'Query':
                    $args[] = $req->query($arg['name']);
                    break;
                case 'Body':
                    $dto = new $arg['class']($req->body());
                    if ($arg['validate'] ?? false) {
                        $validator = new Validator();
                        $errors = $validator->validate($dto);
                        if ($errors) {
                            throw new ValidationException("Validation failed", $errors);
                        }
                    }
                    $args[] = $dto;
                    break;
                case 'Scalar':
                    $val = $params[$arg['name']] ?? $req->query($arg['name']) ?? null;
                    settype($val, $arg['type']);
                    $args[] = $val;
                    break;
                default:
                    $args[] = null;
                    break;
            }
        }
        return $args;
    }

    private function renderResult($result, Response $res, array $formatter): void
    {
        $type = $formatter['type'] ?? 'json';
        $options = $formatter['options'] ?? [];

        // HTML View
        if ($result instanceof View) {
            $res->withHeader('Content-Type', 'text/html')
                ->write($result->render())
                ->send();
            return;
        }

        // File Formatter
        if ($type === FileFormatter::class || $type === 'file') {
            $this->renderFileResult($result, $res, $options);
            return;
        }

        // XML Formatter
        if ($type === SimpleXmlFormatter::class || $type === 'xml') {
            $this->renderXmlResult($result, $res);
            return;
        }

        // JSON (default)
        $res->withHeader('Content-Type', 'application/json')
            ->write(json_encode($result))
            ->send();
    }

    private function renderFileResult($result, Response $res, array $options): void
    {
        $mimetype = $options['mimetype'] ?? 'application/octet-stream';
        $res->withHeader('Content-Type', $mimetype);

        if (!empty($options['download'])) {
            $filename = $options['filename'] ?? (is_string($result) ? basename($result) : 'download.bin');
            $res->withHeader('Content-Disposition', "attachment; filename=\"$filename\"");
        }

        if (is_string($result) && is_file($result)) {
            $res->write(file_get_contents($result))->send();
        } else {
            $res->write(is_scalar($result) ? $result : json_encode($result))->send();
        }
    }

    private function renderXmlResult($result, Response $res): void
    {
        $res->withHeader('Content-Type', 'application/xml');
        $xml = simplexml_load_string('<root/>');
        $arrayResult = is_array($result) ? $result : (array)$result;
        array_walk_recursive($arrayResult, function ($v, $k) use ($xml) {
            $xml->addChild($k, $v);
        });
        $res->write($xml->asXML())->send();
    }

    // -- Compile/Export/Find Helpers --

    public static function compileRoutePlans(array $controllers): array
    {
        $staticRoutes = [];
        $dynamicRoutes = [];

        foreach ($controllers as $controller) {
            $rc = new ReflectionClass($controller);
            $prefix = '';

            foreach ($rc->getAttributes(Controller::class) as $attr) {
                $cp = $attr->newInstance()->getPath();
                $prefix = $cp !== '' ? '/' . trim($cp, '/') : '';
                break;
            }

            foreach ($rc->getMethods() as $method) {
                $routeAttr = null;
                $formatterAttr = null;
                foreach ($method->getAttributes() as $attr) {
                    $instance = $attr->newInstance();
                    if (method_exists($instance, 'getPath') && method_exists($instance, 'getHttpMethod')) {
                        $routeAttr = $instance;
                    }
                    if (
                        $instance instanceof FileFormatter ||
                        $instance instanceof SimpleXmlFormatter ||
                        $instance instanceof JSONFormatter
                    ) {
                        $formatterAttr = $instance;
                    }
                }
                if (!$routeAttr) continue;

                $methodPath = $routeAttr->getPath() ?: '';
                $combined = rtrim($prefix, '/') . '/' . ltrim($methodPath, '/');
                $path = '/' . trim($combined, '/');
                $dynamic = strpos($path, '{') !== false;
                $regex = null;

                $propInject = [];
                foreach ($rc->getProperties() as $prop) {
                    if ($prop->getAttributes(Inject::class)) {
                        $type = $prop->getType()?->getName();
                        $propInject[] = [
                            'name' => $prop->getName(),
                            'type' => $type,
                        ];
                    }
                }

                $argPlan = [];
                foreach ($method->getParameters() as $param) {
                    $type = match (true) {
                        is_null($param->getType()) => null,
                        method_exists($param->getType(), 'getName') => $param->getType()->getName(),
                        default => null,
                    };

                    $bodyAttr = $param->getAttributes(Body::class)[0] ?? null;
                    $queryAttr = $param->getAttributes(Query::class)[0] ?? null;
                    $routeAttrP = $param->getAttributes(Param::class)[0] ?? null;

                    if ($type === Request::class) {
                        $argPlan[] = ['inject' => 'Request'];
                    } elseif ($type === Response::class) {
                        $argPlan[] = ['inject' => 'Response'];
                    } elseif ($bodyAttr && $type && class_exists($type)) {
                        $argPlan[] = ['inject' => 'Body', 'class' => $type, 'validate' => true];
                    } elseif ($queryAttr) {
                        $attrInstance = $queryAttr->newInstance();
                        $key = $attrInstance->name ?? $param->getName();
                        $argPlan[] = ['inject' => 'Query', 'name' => $key];
                    } elseif ($routeAttrP) {
                        $attrInstance = $routeAttrP->newInstance();
                        $key = $attrInstance->name ?? $param->getName();
                        $argPlan[] = ['inject' => 'Route', 'name' => $key];
                    } elseif ($type && in_array($type, ['string', 'int', 'float', 'bool'])) {
                        $key = $param->getName();
                        $argPlan[] = ['inject' => 'Scalar', 'name' => $key, 'type' => $type];
                    } else {
                        $argPlan[] = ['inject' => 'Unknown'];
                    }
                }

                if ($dynamic) {
                    $pattern = preg_replace('#\{(\w+)\}#', '(?P<\1>[^/]+)', $path);
                    $regex = '#^' . $pattern . '$#';
                }

                $formatter = $formatterAttr ? [
                    'type' => get_class($formatterAttr),
                    'options' => get_object_vars($formatterAttr),
                ] : ['type' => 'json', 'options' => null];

                $plan = [
                    'httpMethod' => strtoupper($routeAttr->getHttpMethod()),
                    'path' => $path,
                    'regex' => $regex,
                    'controller' => $controller,
                    'method' => $method->getName(),
                    'argPlan' => $argPlan,
                    'propInject' => $propInject,
                    'formatter' => $formatter,
                    'throws' => [],
                ];

                if ($dynamic) {
                    $dynamicRoutes[$plan['httpMethod']][] = $plan;
                } else {
                    $staticRoutes[$plan['httpMethod']][$path] = $plan;
                }
            }
        }
        return ['staticRoutes' => $staticRoutes, 'dynamicRoutes' => $dynamicRoutes];
    }

    public function getRoutes(): array
    {
        $routes = [];
        foreach ($this->staticRoutes as $method => $byPath) {
            foreach ($byPath as $path => $plan) {
                $routes[] = (object)[
                    'method' => $method,
                    'rawPath' => $path,
                    'handlerSpec' => [$plan['controller'], $plan['method']],
                ];
            }
        }
        foreach ($this->dynamicRoutes as $method => $plans) {
            foreach ($plans as $plan) {
                $routes[] = (object)[
                    'method' => $method,
                    'rawPath' => $plan['path'],
                    'handlerSpec' => [$plan['controller'], $plan['method']],
                ];
            }
        }
        return $routes;
    }

    public static function exportAllAttributes(array $controllers): array
    {
        $result = [];
        foreach ($controllers as $className) {
            if (!class_exists($className)) continue;
            $rc = new ReflectionClass($className);

            // Class-level
            $result[$className]['__class'] = array_map(
                fn($attr) => [
                    'name' => $attr->getName(),
                    'args' => $attr->getArguments(),
                ],
                $rc->getAttributes()
            );

            // Property-level
            foreach ($rc->getProperties() as $prop) {
                $result[$className]['properties'][$prop->getName()] = array_map(
                    fn($attr) => [
                        'name' => $attr->getName(),
                        'args' => $attr->getArguments(),
                    ],
                    $prop->getAttributes()
                );
            }

            // Method/Param-level
            foreach ($rc->getMethods() as $method) {
                $result[$className]['methods'][$method->getName()]['__method'] = array_map(
                    fn($attr) => [
                        'name' => $attr->getName(),
                        'args' => $attr->getArguments(),
                    ],
                    $method->getAttributes()
                );
                foreach ($method->getParameters() as $param) {
                    $result[$className]['methods'][$method->getName()]['parameters'][$param->getName()] = array_map(
                        fn($attr) => [
                            'name' => $attr->getName(),
                            'args' => $attr->getArguments(),
                        ],
                        $param->getAttributes()
                    );
                }
            }
        }
        return $result;
    }

    public function findRoute(array $routes, string $method, string $path): ?object
    {
        foreach ($routes as $route) {
            if ($route->rawPath === $path && $route->method === $method) {
                return $route;
            }
        }
        return null;
    }
}
