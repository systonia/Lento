<?php

namespace Lento;

use ReflectionClass;

use Psr\Container\ContainerInterface;

use Lento\Exceptions\{NotFoundException};

/**
 * Undocumented class
 */
class Container implements ContainerInterface
{
    private array $services = [];

    public function set(object $service): void
    {
        $this->services[get_class($service)] = $service;
    }

    public function get(string $id)
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }
        if (class_exists($id)) {
            $reflect = new ReflectionClass($id);
            if (!$reflect->getConstructor() || $reflect->getConstructor()->getNumberOfRequiredParameters() === 0) {
                $instance = $reflect->newInstance();
                $this->set($instance);
                return $instance;
            }
        }
        throw new NotFoundException("Service '$id' not found");
    }

    public function has(string $class): bool
    {
        return isset($this->services[$class]);
    }

    public function all(): array
    {
        return $this->services;
    }
}

