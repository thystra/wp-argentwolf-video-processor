<?php
/**
 * File: includes/Backend_Adapter_Factory.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

final class Backend_Adapter_Factory
{
    /** @var array<string, Backend_Adapter> */
    private array $adapters = array();

    public function __construct(Backend_Adapter ...$adapters)
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(Backend_Adapter $adapter): void
    {
        $type = Backend_Identity::sanitize($adapter->type());
        if ('' === $type || $type !== $adapter->type()) {
            throw new RuntimeException('Backend adapter type must be an exact canonical backend identifier.');
        }

        if (isset($this->adapters[$type])) {
            throw new RuntimeException('Backend adapter type is already registered: ' . $type);
        }

        $this->adapters[$type] = $adapter;
    }

    public function resolve(string $type): ?Backend_Adapter
    {
        $type = Backend_Identity::sanitize($type);
        return '' !== $type ? ($this->adapters[$type] ?? null) : null;
    }

    public function has(string $type): bool
    {
        return null !== $this->resolve($type);
    }
}

// EOF: includes/Backend_Adapter_Factory.php
