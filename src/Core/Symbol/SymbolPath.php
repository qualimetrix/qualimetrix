<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Symbol;

final readonly class SymbolPath
{
    /**
     * Sentinel value used internally to represent project-level SymbolPath.
     * Must never appear as an actual PHP namespace.
     */
    private const string PROJECT_SENTINEL = '__PROJECT__';

    private function __construct(
        public ?string $namespace,
        public ?string $type,
        public ?string $member,
        public ?string $filePath = null,
    ) {}

    public static function forMethod(string $namespace, string $class, string $method): self
    {
        return new self(
            namespace: $namespace,
            type: $class,
            member: $method,
        );
    }

    public static function forClass(string $namespace, string $class): self
    {
        return new self(
            namespace: $namespace,
            type: $class,
            member: null,
        );
    }

    public static function forNamespace(string $namespace): self
    {
        return new self(
            namespace: $namespace,
            type: null,
            member: null,
        );
    }

    /**
     * Creates a SymbolPath representing the entire project (all namespaces aggregated).
     */
    public static function forProject(): self
    {
        return new self(
            namespace: self::PROJECT_SENTINEL,
            type: null,
            member: null,
        );
    }

    /**
     * Creates a class-level SymbolPath from a fully qualified class name.
     *
     * Examples:
     * - 'App\Service\UserService' → forClass('App\Service', 'UserService')
     * - 'GlobalClass' → forClass('', 'GlobalClass')
     */
    public static function fromClassFqn(string $fqn): self
    {
        $fqn = ltrim($fqn, '\\');
        $pos = strrpos($fqn, '\\');
        if ($pos !== false) {
            return self::forClass(substr($fqn, 0, $pos), substr($fqn, $pos + 1));
        }

        return self::forClass('', $fqn);
    }

    /**
     * Creates a namespace-level SymbolPath from a namespace string.
     */
    public static function fromNamespaceFqn(string $fqn): self
    {
        return self::forNamespace($fqn);
    }

    public static function forFile(string $path): self
    {
        return new self(
            namespace: null,
            type: null,
            member: null,
            filePath: $path,
        );
    }

    public static function forGlobalFunction(string $namespace, string $function): self
    {
        return new self(
            namespace: $namespace,
            type: null,
            member: $function,
        );
    }

    /**
     * Returns the type of symbol this path represents.
     */
    public function getType(): SymbolType
    {
        if ($this->filePath !== null) {
            return SymbolType::File;
        }

        if ($this->namespace === self::PROJECT_SENTINEL && $this->type === null && $this->member === null) {
            return SymbolType::Project;
        }

        // Function: has member but no type (class)
        if ($this->member !== null && $this->type === null) {
            return SymbolType::Function_;
        }

        // Method: has member and type (class)
        if ($this->member !== null && $this->type !== null) {
            return SymbolType::Method;
        }

        // Class: has type but no member
        if ($this->type !== null) {
            return SymbolType::Class_;
        }

        return SymbolType::Namespace_;
    }

    /**
     * Returns canonical string representation with type prefix.
     *
     * Format: {prefix}:{path}
     *
     * Examples:
     * - method:App\Service\UserService::calculateTotal — method
     * - class:App\Service\UserService — class
     * - file:src/Service/UserService.php — file
     * - ns:App\Service — namespace
     * - ns: — global namespace (empty)
     * - project: — project level
     * - func:App\Utils::helper — namespaced function
     * - func::globalFunction — global function (no namespace)
     */
    public function toCanonical(): string
    {
        $type = $this->getType();

        return match ($type) {
            SymbolType::File => 'file:' . $this->filePath,
            SymbolType::Project => 'project:',
            SymbolType::Function_ => $this->buildFunctionCanonical(),
            SymbolType::Method => $this->buildMethodCanonical(),
            SymbolType::Class_ => $this->buildClassCanonical(),
            SymbolType::Namespace_ => 'ns:' . ($this->namespace ?? ''),
        };
    }

    /**
     * Returns human-readable string representation without type prefix.
     *
     * Examples:
     * - App\Service\UserService::calculateTotal — method
     * - App\Service\UserService — class
     * - src/Service/UserService.php — file
     * - App\Service — namespace
     * - (global) — global namespace
     * - (project) — project level
     * - App\Utils::helper — namespaced function
     * - helper — global function (no namespace)
     */
    public function toString(): string
    {
        $type = $this->getType();

        return match ($type) {
            SymbolType::File => $this->filePath ?? '',
            SymbolType::Project => '(project)',
            SymbolType::Function_ => $this->buildFunctionString(),
            SymbolType::Method => $this->buildMethodString(),
            SymbolType::Class_ => $this->buildClassString(),
            SymbolType::Namespace_ => $this->namespace !== '' ? ($this->namespace ?? '') : '(global)',
        };
    }

    /**
     * Returns short symbol name without namespace.
     *
     * Examples:
     * - UserService::calculateTotal — for method
     * - UserService — for class
     * - helper — for function
     * - null — for file, namespace, or project level symbols
     */
    public function getSymbolName(): ?string
    {
        $type = $this->getType();

        return match ($type) {
            SymbolType::Method => $this->type !== null
                ? $this->type . '::' . $this->member
                : $this->member,
            SymbolType::Class_ => $this->type,
            SymbolType::Function_ => $this->member,
            SymbolType::File, SymbolType::Namespace_, SymbolType::Project => null,
        };
    }

    /**
     * Returns whether this SymbolPath has a non-empty namespace.
     */
    private function hasNamespace(): bool
    {
        return $this->namespace !== null && $this->namespace !== '' && $this->namespace !== self::PROJECT_SENTINEL;
    }

    private function buildFunctionCanonical(): string
    {
        if ($this->hasNamespace()) {
            return 'func:' . $this->namespace . '::' . $this->member;
        }

        return 'func::' . $this->member;
    }

    private function buildMethodCanonical(): string
    {
        $parts = ['method:'];

        if ($this->hasNamespace()) {
            $parts[] = $this->namespace;
            $parts[] = '\\';
        }

        $parts[] = $this->type;
        $parts[] = '::';
        $parts[] = $this->member;

        return implode('', $parts);
    }

    private function buildClassCanonical(): string
    {
        $parts = ['class:'];

        if ($this->hasNamespace()) {
            $parts[] = $this->namespace;
            $parts[] = '\\';
        }

        $parts[] = $this->type;

        return implode('', $parts);
    }

    private function buildFunctionString(): string
    {
        if ($this->hasNamespace()) {
            return $this->namespace . '\\' . $this->member;
        }

        return $this->member ?? '';
    }

    private function buildMethodString(): string
    {
        $parts = [];

        if ($this->hasNamespace()) {
            $parts[] = $this->namespace;
            $parts[] = '\\';
        }

        $parts[] = $this->type;
        $parts[] = '::';
        $parts[] = $this->member;

        return implode('', $parts);
    }

    private function buildClassString(): string
    {
        $parts = [];

        if ($this->hasNamespace()) {
            $parts[] = $this->namespace;
            $parts[] = '\\';
        }

        $parts[] = $this->type;

        return implode('', $parts);
    }
}
