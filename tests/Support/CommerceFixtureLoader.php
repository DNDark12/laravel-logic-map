<?php

namespace DNDark\LogicMap\Tests\Support;

use Closure;
use InvalidArgumentException;

final class CommerceFixtureLoader
{
    private const PREFIX = 'Fixtures\\CommerceApp\\';

    private string $root;

    private bool $registered = false;

    private ?Closure $callback = null;

    public function __construct(string $root)
    {
        $resolved = realpath($root);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException('Commerce fixture root must exist.');
        }

        $this->root = rtrim(str_replace('\\', '/', $resolved), '/');
    }

    public function register(): void
    {
        if (! $this->registered) {
            $this->callback ??= $this->load(...);
            spl_autoload_register($this->callback, true, true);
            $this->registered = true;
        }
    }

    public function unregister(): void
    {
        if ($this->registered) {
            spl_autoload_unregister($this->callback);
            $this->registered = false;
        }
    }

    public function load(string $class): bool
    {
        if (! str_starts_with($class, self::PREFIX)) {
            return false;
        }

        $relative = str_replace('\\', '/', substr($class, strlen(self::PREFIX))).'.php';
        $path = $this->root.'/'.$relative;
        $resolved = realpath($path);

        if ($resolved === false) {
            return false;
        }

        $resolved = str_replace('\\', '/', $resolved);

        if (! str_starts_with($resolved, $this->root.'/')) {
            return false;
        }

        require_once $resolved;

        return true;
    }
}
