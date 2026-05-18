<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Path;

use InvalidArgumentException;
use Stringable;

/**
 * Project-relative path value object.
 *
 * Companion to {@see AbsolutePath}; see ADR 0015 for the migration rationale.
 *
 * Invariants enforced at construction:
 * - non-empty, not pure "."
 * - never starts with "/" (absolute paths use {@see AbsolutePath})
 * - Windows-style separators normalized to "/"
 * - ".." segments resolved lexically; paths that escape the base via leading ".."
 *   after normalization are rejected (out-of-base concerns are handled by
 *   {@see PathFactory::gitRelative()} / {@see PathFactory::tryProjectRelative()},
 *   which return `null`)
 *
 * {@see startsWith()} is segment-aware: "foobar" does not start with "foo".
 * Wire format is pinned to `['value' => string]` for IPC/cache stability.
 *
 * Note on directionality: this VO has no static reference to {@see AbsolutePath}.
 * The conversion to absolute uses {@see AbsolutePath::joinRelative()}, mirroring
 * Java NIO / Python pathlib where the base owns the resolution operation.
 *
 * @qmx-threshold coupling.cbo warning=50 error=80 ADR 0015 Phase 1a — high
 *                 afferent coupling is by design: every rule / formatter / value
 *                 carrier consumes this VO. Inbound CBO will shrink in Phase 1c
 *                 when the transient `RelativePath::fromString` bridges at rule
 *                 construction sites are deleted.
 */
final readonly class RelativePath implements Stringable
{
    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidArgumentException if $value is empty, pure ".", absolute, or escapes the base via leading ".."
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('RelativePath cannot be empty');
        }

        if (str_starts_with($value, '/')) {
            throw new InvalidArgumentException(
                \sprintf('RelativePath must not be absolute, got "%s"', $value),
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

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function startsWith(self $prefix): bool
    {
        if ($this->value === $prefix->value) {
            return true;
        }

        return str_starts_with($this->value, $prefix->value . '/');
    }

    /**
     * @throws InvalidArgumentException if $this does not start with $prefix
     */
    public function withoutPrefix(self $prefix): self
    {
        $result = $this->tryWithoutPrefix($prefix);

        if ($result === null) {
            throw new InvalidArgumentException(
                \sprintf('RelativePath "%s" does not start with prefix "%s"', $this->value, $prefix->value),
            );
        }

        return $result;
    }

    public function tryWithoutPrefix(self $prefix): ?self
    {
        if (!$this->startsWith($prefix)) {
            return null;
        }

        if ($this->value === $prefix->value) {
            return null;
        }

        return self::fromString(substr($this->value, \strlen($prefix->value) + 1));
    }

    public function join(self $tail): self
    {
        return self::fromString($this->value . '/' . $tail->value);
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        return explode('/', $this->value);
    }

    public function parent(): ?self
    {
        $segments = $this->segments();

        if (\count($segments) < 2) {
            return null;
        }

        array_pop($segments);

        return self::fromString(implode('/', $segments));
    }

    public function basename(): string
    {
        $segments = $this->segments();

        return $segments[\count($segments) - 1];
    }

    public function extension(): ?string
    {
        $basename = $this->basename();
        $dot = strrpos($basename, '.');

        if ($dot === false || $dot === 0) {
            return null;
        }

        return substr($basename, $dot + 1);
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
        // Fast path: a single PCRE pass detects every shape the slow path would
        // need to handle — Windows separators, leading/trailing/embedded "." or
        // ".." segments, double slashes, and bare ".". A miss means the value is
        // already normalized.
        if (preg_match('#\\\\|(?:\A|/)\.{0,2}(?:/|\z)#', $value) !== 1) {
            return $value;
        }

        if (str_contains($value, '\\')) {
            $value = str_replace('\\', '/', $value);
        }

        if (str_starts_with($value, './')) {
            $value = substr($value, 2);
        }

        if ($value === '' || $value === '.') {
            throw new InvalidArgumentException('RelativePath cannot be empty or "."');
        }

        $segments = explode('/', $value);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($resolved === []) {
                    throw new InvalidArgumentException(
                        \sprintf('RelativePath "%s" escapes the base via leading ".."', $value),
                    );
                }
                array_pop($resolved);

                continue;
            }

            $resolved[] = $segment;
        }

        if ($resolved === []) {
            throw new InvalidArgumentException(
                \sprintf('RelativePath "%s" normalizes to empty', $value),
            );
        }

        return implode('/', $resolved);
    }
}
