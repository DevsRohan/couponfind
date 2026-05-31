<?php

declare(strict_types=1);

namespace CouponFind\Core;

/**
 * Tiny service container with singleton resolution + autowiring of
 * zero-argument / container-aware constructors. Sufficient for this app's
 * dependency graph without pulling in a full DI framework.
 */
final class Container
{
    private static ?Container $instance = null;

    /** @var array<string, callable> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $instances = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function instanceSet(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        // Autowire: instantiate concrete class with a (Container) or () ctor.
        if (class_exists($id)) {
            $ref = new \ReflectionClass($id);
            $ctor = $ref->getConstructor();
            if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
                return $this->instances[$id] = $ref->newInstance();
            }
        }
        throw new \RuntimeException("Cannot resolve service: {$id}");
    }
}
