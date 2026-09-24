<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Support\Discovery;

use ArrayIterator;
use RecursiveIterator;
use UnexpectedValueException;

/**
 * A two-level tree whose directories answer `hasChildren()` and then refuse
 * `getChildren()` — the shape `RecursiveDirectoryIterator` takes when a
 * directory stops being listable after it was checked.
 *
 * @implements RecursiveIterator<string, string>
 */
final class RefusingRecursiveIterator implements RecursiveIterator
{
    /** @var ArrayIterator<int, string> */
    private readonly ArrayIterator $entries;

    /**
     * @param list<string> $entries
     * @param array<string, list<string>> $children
     */
    private function __construct(
        array $entries,
        private readonly array $children,
        private readonly ?string $refusedChild,
    ) {
        $this->entries = new ArrayIterator($entries);
    }

    /** @param string|null $refusedChild The directory whose descent fails, if any. */
    public static function tree(?string $refusedChild): self
    {
        return new self(
            ['/tree/blocked', '/tree/open'],
            [
                '/tree/blocked' => ['/tree/blocked/Hidden.php'],
                '/tree/open' => ['/tree/open/Kept.php'],
            ],
            $refusedChild,
        );
    }

    public function hasChildren(): bool
    {
        return isset($this->children[$this->current()]);
    }

    /** @return RecursiveIterator<string, string> */
    public function getChildren(): RecursiveIterator
    {
        $current = $this->current();

        if ($current === $this->refusedChild) {
            throw new UnexpectedValueException('Failed to open directory: Permission denied');
        }

        return new self($this->children[$current] ?? [], [], $this->refusedChild);
    }

    public function current(): string
    {
        $value = $this->entries->current();

        return \is_string($value) ? $value : '';
    }

    public function key(): string
    {
        return $this->current();
    }

    public function next(): void
    {
        $this->entries->next();
    }

    public function rewind(): void
    {
        $this->entries->rewind();
    }

    public function valid(): bool
    {
        return $this->entries->valid();
    }
}
