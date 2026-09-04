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
 *
 * A breakage can need more than one file. The controls on the declared delta
 * plant a declaration next to the product change it is supposed to account for,
 * and the control on the reference's vocabulary needs a rename plus the case
 * input that addresses it — a single-file mutation could state neither.
 */
final class Mutation
{
    private const EDIT = 'edit';
    private const DELETE = 'delete';
    private const CREATE = 'create';
    private const REPLACE = 'replace';

    /**
     * Adds to a file instead of rewriting it, so a control can perturb a
     * declaration whose contents it must not have to restate.
     *
     * The declared delta changes with every round; a control that replaced it
     * with a typed copy would have to be re-typed with it, and would silently
     * stop testing the current declaration. Appending is also the only shape
     * that survives {@see assertApplied()} here: every fragment-based edit of a
     * header leaves that header in the file, which reads as "the mutation did
     * not land".
     */
    private const APPEND = 'append';

    /** @param list<array{kind: string, path: string, replacements: array<string, string>, contents: string}> $actions */
    private function __construct(
        public readonly string $description,
        private readonly array $actions,
    ) {}

    /**
     * @param array<string, string> $replacements old fragment => new fragment,
     *                                            each required to occur exactly once
     */
    public static function edit(string $relativePath, array $replacements, string $description): self
    {
        return new self($description, [self::action(self::EDIT, $relativePath, $replacements, '')]);
    }

    public static function append(string $relativePath, string $text, string $description): self
    {
        return new self($description, [self::action(self::APPEND, $relativePath, [], $text)]);
    }

    public static function delete(string $relativePath, string $description): self
    {
        return new self($description, [self::action(self::DELETE, $relativePath, [], '')]);
    }

    /** @param array<string, string> $contentsByPath path => the whole file to write */
    public static function create(array $contentsByPath, string $description): self
    {
        $actions = [];

        foreach ($contentsByPath as $path => $contents) {
            $actions[] = self::action(self::CREATE, $path, [], $contents);
        }

        return new self($description, $actions);
    }

    /**
     * The whole file, over one that must already be there.
     *
     * Distinct from {@see create()} because the two guard opposite things:
     * creating a file the repository already has proves nothing about its
     * absence, and replacing one it does not have silently invents the state
     * under test. A replace that writes what was already there is refused for
     * the same reason {@see edit()} demands exactly one occurrence of its old
     * fragment: a mutation that mutates nothing turns its control into a second
     * positive control.
     *
     * @param array<string, string> $contentsByPath path => the whole file to write
     */
    public static function replace(array $contentsByPath, string $description): self
    {
        $actions = [];

        foreach ($contentsByPath as $path => $contents) {
            $actions[] = self::action(self::REPLACE, $path, [], $contents);
        }

        return new self($description, $actions);
    }

    public static function none(): self
    {
        return new self('nothing mutated', []);
    }

    /** Everything both mutations do, as one mutation. */
    public function and(self $other): self
    {
        return new self(
            $this->description . '; ' . $other->description,
            [...$this->actions, ...$other->actions],
        );
    }

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    /** @return list<string> */
    public function relativePaths(): array
    {
        return array_values(array_unique(array_column($this->actions, 'path')));
    }

    public function label(): string
    {
        return $this->isEmpty() ? 'none' : implode(', ', $this->relativePaths()) . ' — ' . $this->description;
    }

    public function apply(Scratch $scratch, string $repository): void
    {
        foreach ($this->actions as $action) {
            $this->applyOne($action, $scratch, $repository);
        }
    }

    /** @param array{kind: string, path: string, replacements: array<string, string>, contents: string} $action */
    private function applyOne(array $action, Scratch $scratch, string $repository): void
    {
        $target = $scratch->path($action['path']);
        $original = $repository . '/' . $action['path'];
        $existsAlready = $action['kind'] !== self::CREATE;

        if ($existsAlready && (!is_file($target) || !is_file($original))) {
            throw new RuntimeException(\sprintf('Mutation target %s does not exist.', $action['path']));
        }

        if (!$existsAlready && is_file($original)) {
            throw new RuntimeException(\sprintf(
                '%s already exists in the repository, so creating it proves nothing about a declaration that is not'
                . ' there. Re-point the control.',
                $action['path'],
            ));
        }

        $before = $existsAlready ? hash_file('sha256', $original) : 'absent';

        if ($before === false) {
            throw new RuntimeException(\sprintf(
                'Cannot hash %s before mutating. Without it the hardlink check below compares false against'
                . ' false and passes while the working tree is being written through.',
                $original,
            ));
        }

        // Kept for the no-op assertion below: a REPLACE that writes exactly what
        // was already there mutates nothing, and its control would silently
        // become a second positive control.
        $applied = [
            ...$action,
            'before' => \in_array($action['kind'], [self::REPLACE, self::APPEND], true) ? Shell::read($target) : '',
        ];

        match ($action['kind']) {
            self::DELETE => self::removeFrom($target, $action['path']),
            self::CREATE, self::REPLACE => self::createAt($target, $action['contents']),
            self::APPEND => Shell::replace($target, Shell::read($target) . $action['contents']),
            default => Shell::replace($target, self::rewrite(Shell::read($target), $action)),
        };

        self::assertRepositoryUntouched($original, $before);
        self::assertApplied($target, $applied);
    }

    /**
     * @param array{kind: string, path: string, replacements: array<string, string>, contents: string} $action
     */
    private static function rewrite(string $contents, array $action): string
    {
        foreach ($action['replacements'] as $old => $new) {
            $occurrences = substr_count($contents, $old);

            if ($occurrences !== 1) {
                throw new RuntimeException(\sprintf(
                    'Mutation of %s expects exactly one occurrence of "%s", found %d. The product code moved:'
                    . ' re-point the mutation instead of letting the control quietly stop mutating anything.',
                    $action['path'],
                    $old,
                    $occurrences,
                ));
            }

            $contents = str_replace($old, $new, $contents);
        }

        return $contents;
    }

    /** A created file can be the first thing in its directory — the declared delta's is. */
    private static function createAt(string $target, string $contents): void
    {
        $directory = \dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Cannot create %s in the scratch tree.', $directory));
        }

        Shell::replace($target, $contents);
    }

    private static function removeFrom(string $target, string $relativePath): void
    {
        if (!@unlink($target)) {
            throw new RuntimeException(\sprintf('Cannot remove %s from the scratch tree.', $relativePath));
        }
    }

    private static function assertRepositoryUntouched(string $original, string $before): void
    {
        $after = $before === 'absent'
            ? (is_file($original) ? 'appeared' : 'absent')
            : hash_file('sha256', $original);

        if ($after === false || $after !== $before) {
            throw new RuntimeException(\sprintf(
                'The mutation wrote through the hardlink into %s. Stop: the working tree is corrupted.',
                $original,
            ));
        }
    }

    /** @param array{kind: string, path: string, replacements: array<string, string>, contents: string, before: string} $action */
    private static function assertApplied(string $target, array $action): void
    {
        if ($action['kind'] === self::DELETE) {
            if (is_file($target)) {
                throw new RuntimeException(\sprintf('%s still exists in the scratch tree.', $action['path']));
            }

            return;
        }

        if ($action['kind'] === self::APPEND) {
            if (Shell::read($target) !== $action['before'] . $action['contents']) {
                throw new RuntimeException(\sprintf('%s does not end with what the mutation appended.', $action['path']));
            }

            return;
        }

        if ($action['kind'] === self::REPLACE && Shell::read($target) === $action['before']) {
            throw new RuntimeException(\sprintf(
                '%s already held exactly what the mutation writes, so this control mutates nothing and is a second'
                . ' positive control. Re-point it.',
                $action['path'],
            ));
        }

        if ($action['kind'] === self::CREATE || $action['kind'] === self::REPLACE) {
            if (Shell::read($target) !== $action['contents']) {
                throw new RuntimeException(\sprintf('%s does not hold what the mutation wrote.', $action['path']));
            }

            return;
        }

        foreach (array_keys($action['replacements']) as $old) {
            if (str_contains(Shell::read($target), $old)) {
                throw new RuntimeException(\sprintf('%s still contains "%s" after the mutation.', $action['path'], $old));
            }
        }
    }

    /**
     * @param array<string, string> $replacements
     *
     * @return array{kind: string, path: string, replacements: array<string, string>, contents: string}
     */
    private static function action(string $kind, string $path, array $replacements, string $contents): array
    {
        return ['kind' => $kind, 'path' => $path, 'replacements' => $replacements, 'contents' => $contents];
    }
}
