<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

/**
 * The namespaces that code removed from the run (an `exclude` entry or a
 * generated file) declares, or the fact that they could not be established.
 *
 * Names are compared with ASCII case folded, as the namespace index spells
 * them. A declared namespace also declares each of its ancestors, because the
 * namespace tree materialises them. Code outside any `namespace` statement
 * declares the empty namespace, which is not an ancestor of anything: an
 * exact selector on it binds only global code, while a subtree selector on it
 * covers every namespace.
 */
final readonly class RemovedCodeNamespaces
{
    /** @param array<string, true>|null $declared folded declared names with their ancestors; null when unknown */
    private function __construct(private ?array $declared) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $declared namespaces as written; the empty string is global code
     */
    public static function known(array $declared): self
    {
        $names = [];
        foreach ($declared as $namespace) {
            $folded = self::fold($namespace);
            $names[$folded] = true;
            while (($cut = strrpos($folded, '\\')) !== false) {
                $folded = substr($folded, 0, $cut);
                $names[$folded] = true;
            }
        }

        return new self($names);
    }

    public static function unknown(): self
    {
        return new self(null);
    }

    /** Unknown absorbs; none is the identity; known sets unite. */
    public function merge(self $other): self
    {
        if ($this->declared === null || $other->declared === null) {
            return self::unknown();
        }

        return new self($this->declared + $other->declared);
    }

    public function isKnown(): bool
    {
        return $this->declared !== null;
    }

    /**
     * Whether removed code could have declared the namespace, or, for a
     * subtree, any namespace within it. Unknown removed code could.
     */
    public function mayDeclareWithin(string $namespace, bool $subtree): bool
    {
        if ($this->declared === null) {
            return true;
        }

        $folded = self::fold($namespace);
        if (!$subtree) {
            return isset($this->declared[$folded]);
        }
        if ($folded === '') {
            return $this->declared !== [];
        }

        $prefix = $folded . '\\';
        foreach ($this->declared as $name => $_) {
            if ($name === $folded || str_starts_with((string) $name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function fold(string $namespace): string
    {
        return strtolower(trim($namespace, '\\'));
    }
}
