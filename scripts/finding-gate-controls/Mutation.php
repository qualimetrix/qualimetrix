<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use RuntimeException;

/**
 * One planted breakage, plus the proof that it landed in the scratch tree and
 * only there.
 *
 * Both halves are checked on every run. A mutation that silently stopped
 * applying (the product code it edits moved on) would turn its control into a
 * second positive control, and the harness would keep printing a table; a
 * mutation that wrote through the hardlink would corrupt the developer's
 * working tree.
 */
final class Mutation
{
    /** @param array<string, string> $replacements */
    private function __construct(
        public readonly string $description,
        public readonly string $relativePath,
        private readonly array $replacements,
    ) {}

    /**
     * @param array<string, string> $replacements old fragment => new fragment,
     *                                            each required to occur exactly once
     */
    public static function edit(string $relativePath, array $replacements, string $description): self
    {
        return new self($description, $relativePath, $replacements);
    }

    public static function delete(string $relativePath, string $description): self
    {
        return new self($description, $relativePath, []);
    }

    public static function none(): self
    {
        return new self('nothing mutated', '', []);
    }

    public function isEmpty(): bool
    {
        return $this->relativePath === '';
    }

    public function apply(Scratch $scratch, string $repository): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $target = $scratch->path($this->relativePath);
        $original = $repository . '/' . $this->relativePath;

        if (!is_file($target) || !is_file($original)) {
            throw new RuntimeException(\sprintf('Mutation target %s does not exist.', $this->relativePath));
        }

        $before = hash_file('sha256', $original);

        if ($before === false) {
            throw new RuntimeException(\sprintf(
                'Cannot hash %s before mutating. Without it the hardlink check below compares false against'
                . ' false and passes while the working tree is being written through.',
                $original,
            ));
        }

        if ($this->replacements === []) {
            if (!@unlink($target)) {
                throw new RuntimeException(\sprintf('Cannot remove %s from the scratch tree.', $this->relativePath));
            }
        } else {
            Shell::replace($target, $this->rewrite(Shell::read($target)));
        }

        $this->assertRepositoryUntouched($original, $before);
        $this->assertApplied($target);
    }

    private function rewrite(string $contents): string
    {
        foreach ($this->replacements as $old => $new) {
            $occurrences = substr_count($contents, $old);

            if ($occurrences !== 1) {
                throw new RuntimeException(\sprintf(
                    'Mutation of %s expects exactly one occurrence of "%s", found %d. The product code moved:'
                    . ' re-point the mutation instead of letting the control quietly stop mutating anything.',
                    $this->relativePath,
                    $old,
                    $occurrences,
                ));
            }

            $contents = str_replace($old, $new, $contents);
        }

        return $contents;
    }

    private function assertRepositoryUntouched(string $original, string $before): void
    {
        $after = hash_file('sha256', $original);

        if ($after === false || $after !== $before) {
            throw new RuntimeException(\sprintf(
                'The mutation wrote through the hardlink into %s. Stop: the working tree is corrupted.',
                $original,
            ));
        }
    }

    private function assertApplied(string $target): void
    {
        if ($this->replacements === []) {
            if (is_file($target)) {
                throw new RuntimeException(\sprintf('%s still exists in the scratch tree.', $this->relativePath));
            }

            return;
        }

        foreach (array_keys($this->replacements) as $old) {
            if (str_contains(Shell::read($target), $old)) {
                throw new RuntimeException(\sprintf('%s still contains "%s" after the mutation.', $this->relativePath, $old));
            }
        }
    }
}
