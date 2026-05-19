<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Path;

use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;
use Stringable;

/**
 * POSIX absolute path value object.
 *
 * Companion to {@see RelativePath}; together they replace untyped `string` file paths
 * across the analysis pipeline. See ADR 0015.
 *
 * Construction is lexical: `..` segments are resolved without touching the filesystem,
 * so `/a/b/../c` becomes `/a/c`. Use {@see canonicalize()} when symlink-aware resolution
 * is required. Equality is structural after normalization.
 *
 * Wire format is pinned to `['value' => string]` via {@see __serialize()} /
 * {@see __unserialize()}, so cache files and amphp/parallel IPC payloads survive
 * private-property renames.
 */
final readonly class AbsolutePath implements JsonSerializable, Stringable
{
    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidArgumentException if $value is empty, not POSIX-absolute, or escapes the root via `..`
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('AbsolutePath cannot be empty');
        }

        if (!str_starts_with($value, '/')) {
            throw new InvalidArgumentException(
                \sprintf('AbsolutePath must start with "/", got "%s"', $value),
            );
        }

        return new self(self::normalize($value));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * @throws InvalidArgumentException if $this is not under $base
     */
    public function relativizeTo(self $base): RelativePath
    {
        $result = $this->tryRelativizeTo($base);

        if ($result === null) {
            throw new InvalidArgumentException(
                \sprintf('AbsolutePath "%s" is not under base "%s"', $this->value, $base->value),
            );
        }

        return $result;
    }

    public function tryRelativizeTo(self $base): ?RelativePath
    {
        if ($this->value === $base->value) {
            return null;
        }

        $prefix = $base->value === '/' ? '/' : $base->value . '/';

        if (!str_starts_with($this->value, $prefix)) {
            return null;
        }

        return RelativePath::fromString(substr($this->value, \strlen($prefix)));
    }

    public function joinRelative(RelativePath $tail): self
    {
        $head = $this->value === '/' ? '' : $this->value;

        return self::fromString($head . '/' . $tail->value());
    }

    /**
     * @throws RuntimeException if the path does not exist
     */
    public function canonicalize(): self
    {
        $resolved = realpath($this->value);

        if ($resolved === false) {
            throw new RuntimeException(
                \sprintf('Cannot canonicalize "%s": path does not exist', $this->value),
            );
        }

        return self::fromString($resolved);
    }

    public function exists(): bool
    {
        return file_exists($this->value);
    }

    public function isFile(): bool
    {
        return is_file($this->value);
    }

    public function isDirectory(): bool
    {
        return is_dir($this->value);
    }

    /**
     * @return array{value: string}
     */
    public function __serialize(): array
    {
        return ['value' => $this->value];
    }

    /**
     * @param array{value: string} $data
     */
    public function __unserialize(array $data): void
    {
        $this->value = $data['value'];
    }

    private static function normalize(string $value): string
    {
        // Fast path: a single PCRE pass detects everything the slow path handles
        // (trailing/embedded "." or ".." segments, double slashes, trailing slash,
        // bare "/" needing a no-op). If none match, the input is already canonical.
        if ($value !== '/' && preg_match('#(?:/|\A/)(?:\.{1,2})?(?:/|\z)#', $value) !== 1) {
            return $value;
        }

        $segments = explode('/', $value);
        $resolved = [];

        foreach ($segments as $index => $segment) {
            if ($index === 0) {
                continue; // leading "/" produces an empty first segment
            }

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($resolved === []) {
                    throw new InvalidArgumentException(
                        \sprintf('AbsolutePath "%s" escapes the root via ".."', $value),
                    );
                }
                array_pop($resolved);

                continue;
            }

            $resolved[] = $segment;
        }

        return $resolved === [] ? '/' : '/' . implode('/', $resolved);
    }
}
