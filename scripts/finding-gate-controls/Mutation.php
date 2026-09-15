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

    /**
     * A rename carried into the step's DERIVED declarations: the exact diffs
     * under `finding-gate/declared-delta/`, whose contents are *measured* by
     * `--derive-declared-delta` rather than typed by anyone.
     *
     * Its own kind because two of {@see edit()}'s guarantees are wrong for this
     * subject, and both were measured on this rig rather than foreseen.
     *
     * How many times such a file names a channel is nobody's decision. When a
     * step first declared a delta for `tree|rules`, the measured diff named
     * `security.sensitive-parameter` twice and `code-smell.unused-private` not
     * at all — so of two controls built on the same shape, one failed on a
     * stale declaration and the other stayed green, decided by nothing but
     * which rule's block fell inside a hunk. `edit()`'s exactly-once rule
     * cannot express either side of that, and pinning a count would re-break on
     * the next derivation.
     *
     * Whether the directory exists at all is likewise a property of the step
     * under test, not of the control: a step that declares no delta leaves none
     * on disk, and a control must not care.
     *
     * So this kind rewrites every occurrence, asserts nothing about the count,
     * and passes over a directory that is not there. The guarantee {@see edit()}
     * buys with its count — a control cannot quietly stop mutating — is kept by
     * construction instead: {@see apply()} refuses a mutation made only of
     * these, because carrying a rename into a declaration is never a control's
     * subject, only the bookkeeping its subject drags along.
     */
    private const RENAME_DERIVED = 'rename-derived';

    /** Where the derived declarations live, relative to the repository root. */
    private const DERIVED_DECLARATIONS = 'finding-gate/declared-delta';

    /**
     * A root configuration key renamed in every corpus document that writes it
     * there.
     *
     * The corpus is the input, so a control renaming a published root key has
     * to rename it everywhere the input spells it, or the mutated product meets
     * a document written in a vocabulary it no longer has and refuses before
     * the gate can measure anything.
     *
     * Its own kind rather than a list of {@see edit()}s because the list went
     * stale and said so only years later: `root-key-renamed` carried the rename
     * into "the one case addressing the root key" while two cases addressed it,
     * and the control had been failing on the second — a refusal during the
     * channel probe, which reports as "the gate wrote no report" and names
     * neither the case nor the key.
     *
     * **Root indent only, and that is the subject rather than an optimisation.**
     * A key of the same spelling nested under `rules:` is a per-rule option, a
     * different key that this rename must leave alone; the control that owns
     * this mutation exists partly to show the two are told apart.
     */
    private const RENAME_ROOT_KEY_IN_CORPUS = 'rename-root-key-in-corpus';

    /** Where the corpus documents live, relative to the repository root. */
    private const CORPUS_CASES = 'finding-gate/cases';

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

    /**
     * @param array<string, string> $replacements old fragment => new fragment, applied to
     *                                            every occurrence in every derived declaration
     */
    public static function renameInDerivedDeclarations(array $replacements, string $description): self
    {
        return new self($description, [self::action(self::RENAME_DERIVED, self::DERIVED_DECLARATIONS, $replacements, '')]);
    }

    /**
     * @param string $oldKey the key as a document writes it, without its colon
     */
    public static function renameRootKeyInCorpus(string $oldKey, string $newKey, string $description): self
    {
        return new self($description, [self::action(
            self::RENAME_ROOT_KEY_IN_CORPUS,
            self::CORPUS_CASES,
            [$oldKey => $newKey],
            '',
        )]);
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
        $carried = array_filter(
            $this->actions,
            static fn(array $action): bool => $action['kind'] !== self::RENAME_DERIVED,
        );

        if ($this->actions !== [] && $carried === []) {
            throw new RuntimeException(
                'This mutation only carries a rename into the derived declarations, which asserts nothing:'
                . ' that kind rewrites what it finds and is allowed to find nothing. It is bookkeeping for a'
                . ' mutation that moves something, never a mutation of its own.',
            );
        }

        foreach ($this->actions as $action) {
            $this->applyOne($action, $scratch, $repository);
        }
    }

    /** @param array{kind: string, path: string, replacements: array<string, string>, contents: string} $action */
    private function applyOne(array $action, Scratch $scratch, string $repository): void
    {
        if ($action['kind'] === self::RENAME_DERIVED) {
            self::renameThroughDerivedDeclarations($action, $scratch, $repository);

            return;
        }

        if ($action['kind'] === self::RENAME_ROOT_KEY_IN_CORPUS) {
            self::renameRootKeyThroughCorpus($action, $scratch, $repository);

            return;
        }

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

    /**
     * The root key renamed in every corpus document that writes it at root, and
     * the count asserted to be non-zero.
     *
     * Unlike {@see renameThroughDerivedDeclarations()}, finding nothing here is
     * a defect rather than a possibility: a control renaming a root key that no
     * corpus document writes is testing a rename nothing exercises, which is
     * exactly the silent no-op {@see edit()}'s count guard exists against.
     *
     * @param array{kind: string, path: string, replacements: array<string, string>, contents: string} $action
     */
    private static function renameRootKeyThroughCorpus(array $action, Scratch $scratch, string $repository): void
    {
        $documents = glob($scratch->path($action['path']) . '/*/qmx.yaml');
        $renamed = 0;

        foreach ($documents === false ? [] : $documents as $document) {
            $original = $repository . '/' . $action['path'] . '/'
                . basename(\dirname($document)) . '/' . basename($document);
            $before = is_file($original) ? hash_file('sha256', $original) : false;

            $contents = Shell::read($document);
            $rewritten = $contents;

            foreach ($action['replacements'] as $old => $new) {
                $rewritten = (string) preg_replace(
                    '/^' . preg_quote($old, '/') . ':/m',
                    $new . ':',
                    $rewritten,
                );
            }

            if ($rewritten !== $contents) {
                Shell::replace($document, $rewritten);
                ++$renamed;
            }

            if (\is_string($before)) {
                self::assertRepositoryUntouched($original, $before);
            }
        }

        if ($renamed === 0) {
            throw new RuntimeException(\sprintf(
                'No corpus document writes %s at root, so renaming it exercises nothing. Re-point the control'
                . ' instead of letting it quietly stop mutating.',
                implode(', ', array_keys($action['replacements'])),
            ));
        }
    }

    /**
     * Every derived declaration, rewritten in place, with the repository's own
     * copies left untouched and checked to be untouched — the hardlink hazard
     * is the same one every other kind guards against.
     *
     * @param array{kind: string, path: string, replacements: array<string, string>, contents: string} $action
     */
    private static function renameThroughDerivedDeclarations(array $action, Scratch $scratch, string $repository): void
    {
        $directory = $scratch->path($action['path']);

        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '/*.diff');

        foreach ($files === false ? [] : $files as $file) {
            $original = $repository . '/' . $action['path'] . '/' . basename($file);
            $before = is_file($original) ? hash_file('sha256', $original) : false;

            if ($before === false) {
                // The scratch tree holds a derived declaration the repository
                // does not, so there is no hardlink to write through and
                // nothing to compare against. Rewriting it is still correct.
                $before = null;
            }

            $contents = Shell::read($file);
            $rewritten = strtr($contents, $action['replacements']);

            if ($rewritten !== $contents) {
                Shell::replace($file, $rewritten);
            }

            if (\is_string($before)) {
                self::assertRepositoryUntouched($original, $before);
            }
        }
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
