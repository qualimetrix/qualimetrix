<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use FilesystemIterator;
use SplFileInfo;
use UnexpectedValueException;

/** Exact byte-segment path lookup within the roots of one analysed Composer install. */
final class ComposerClassPathLookup
{
    /** @var list<string> */
    private array $roots = [];

    /** @var array<string, list<string>> */
    private array $directoryEntries = [];

    /** @param list<string> $roots */
    public function pointAt(array $roots): void
    {
        $this->roots = $roots;
        $this->directoryEntries = [];
    }

    public function isConfigured(): bool
    {
        return $this->roots !== [];
    }

    /** @return list<string> */
    public function roots(): array
    {
        return $this->roots;
    }

    /** Returns an exact existing path only when it stays within an analysed root. */
    public function within(string $path): ?string
    {
        $resolved = $this->existingPath($path);
        if ($resolved === null) {
            return null;
        }

        foreach ($this->roots as $root) {
            if ($resolved === $root || str_starts_with($resolved, $root . '/')) {
                return $resolved;
            }
        }

        return null;
    }

    public function existingPath(string $path): ?string
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            return null;
        }

        $current = '/';
        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $current = \dirname(rtrim($current, '/')) . '/';

                continue;
            }

            $parent = rtrim($current, '/');
            if ($parent === '') {
                $parent = '/';
            }
            if (!\in_array($segment, $this->entriesOf($parent), true)) {
                return null;
            }
            $current = ($parent === '/' ? '' : $parent) . '/' . $segment;
        }

        $resolved = realpath($current);

        return $resolved === false ? null : $resolved;
    }

    /** @return list<string> */
    private function entriesOf(string $directory): array
    {
        $key = realpath($directory);
        if ($key === false) {
            return [];
        }
        if (isset($this->directoryEntries[$key])) {
            return $this->directoryEntries[$key];
        }

        try {
            $entries = [];
            $iterator = new FilesystemIterator(
                $key,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
            );
            foreach ($iterator as $entry) {
                if (!$entry instanceof SplFileInfo) {
                    continue;
                }
                $entries[] = $entry->getFilename();
            }
        } catch (UnexpectedValueException) {
            $entries = [];
        }

        return $this->directoryEntries[$key] = $entries;
    }
}
