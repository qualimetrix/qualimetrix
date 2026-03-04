<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Violation;

use AiMessDetector\Core\Symbol\SymbolType;

final readonly class SymbolPath
{
    private function __construct(
        public ?string $namespace,
        public ?string $type,
        public ?string $member,
        public ?string $filePath = null,
    ) {}

    public static function forMethod(string $namespace, string $class, string $method): self
    {
        return new self(
            namespace: $namespace !== '' ? $namespace : null,
            type: $class,
            member: $method,
        );
    }

    public static function forClass(string $namespace, string $class): self
    {
        return new self(
            namespace: $namespace !== '' ? $namespace : null,
            type: $class,
            member: null,
        );
    }

    public static function forNamespace(string $namespace): self
    {
        return new self(
            namespace: $namespace !== '' ? $namespace : null,
            type: null,
            member: null,
        );
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
            namespace: $namespace !== '' ? $namespace : null,
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
     * - func:App\Utils::helper — namespaced function
     * - func::globalFunction — global function (no namespace)
     */
    public function toCanonical(): string
    {
        $type = $this->getType();

        return match ($type) {
            SymbolType::File => 'file:' . $this->filePath,
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
     * - App\Utils::helper — namespaced function
     * - helper — global function (no namespace)
     */
    public function toString(): string
    {
        $type = $this->getType();

        return match ($type) {
            SymbolType::File => $this->filePath ?? '',
            SymbolType::Function_ => $this->buildFunctionString(),
            SymbolType::Method => $this->buildMethodString(),
            SymbolType::Class_ => $this->buildClassString(),
            SymbolType::Namespace_ => $this->namespace ?? '',
        };
    }

    /**
     * Returns short symbol name without namespace.
     *
     * Examples:
     * - UserService::calculateTotal — for method
     * - UserService — for class
     * - helper — for function
     * - null — for file or namespace level symbols
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
            SymbolType::File, SymbolType::Namespace_ => null,
        };
    }

    private function buildFunctionCanonical(): string
    {
        if ($this->namespace !== null) {
            return 'func:' . $this->namespace . '::' . $this->member;
        }

        return 'func::' . $this->member;
    }

    private function buildMethodCanonical(): string
    {
        $parts = ['method:'];

        if ($this->namespace !== null) {
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

        if ($this->namespace !== null) {
            $parts[] = $this->namespace;
            $parts[] = '\\';
        }

        $parts[] = $this->type;

        return implode('', $parts);
    }

    private function buildFunctionString(): string
    {
        if ($this->namespace !== null) {
            return $this->namespace . '\\' . $this->member;
        }

        return '::' . ($this->member ?? '');
    }

    private function buildMethodString(): string
    {
        $parts = [];

        if ($this->namespace !== null) {
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

        if ($this->namespace !== null) {
            $parts[] = $this->namespace;
            $parts[] = '\\';
        }

        $parts[] = $this->type;

        return implode('', $parts);
    }
}
