<?php

declare(strict_types=1);

/**
 * The stand's own run parameters, declared in ONE file rather than repeated
 * as literals inside `promise-effect.php` (02-stand.md S11).
 *
 * Before this, the "before" commit was a string literal inside
 * `--freeze-before`, and the axis order `A,B,D` was written TWICE more — once
 * as the default for `--axis=`, once again as the fixed iteration order of
 * the summary loops. A round that changed one without the other would
 * silently stop measuring an axis and nothing would say so.
 */

namespace Qualimetrix\PromiseEffect;

final readonly class RunDeclaration
{
    private const string PATH = 'promise-effect/run-declaration.tsv';

    /**
     * @param list<string> $axes
     * @param list<string> $blockingAxes the subset whose defects move the exit code
     */
    private function __construct(
        public string $beforeCommit,
        public array $axes,
        public array $blockingAxes,
    ) {}

    /**
     * Both keys are required and non-blank: a declaration that cannot be
     * read fully is not a guess this reader fills in, because a silent
     * fallback here is exactly the defect S11 removes.
     */
    public static function load(string $root): self
    {
        $lines = file($root . '/' . self::PATH, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . self::PATH);
        }

        $declared = [];
        $header = false;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$header) {
                $header = true;

                continue;
            }

            $cells = array_pad(explode("\t", $line, 2), 2, '');
            $declared[$cells[0]] = $cells[1];
        }

        foreach (['before-commit', 'axes', 'blocking-axes'] as $required) {
            if (trim($declared[$required] ?? '') === '') {
                throw new LedgerError(self::PATH . ' does not declare a non-blank "' . $required . '"');
            }
        }

        $axes = explode(',', $declared['axes']);
        $blocking = explode(',', $declared['blocking-axes']);

        foreach ($blocking as $axis) {
            if (!\in_array($axis, $axes, true)) {
                throw new LedgerError(self::PATH . ' calls "' . $axis . '" blocking, and it is not one of the declared axes');
            }
        }

        return new self($declared['before-commit'], $axes, $blocking);
    }

    /**
     * A declared commit is not a guess this stand fills in when it looks
     * wrong. An objectively malformed value is refused before a single git
     * process runs, so a typo cannot silently defeat this check by making
     * every later comparison agree with it vacuously. A guard whose own
     * constants name nonexistent paths can never redden, so those paths are
     * never redden.
     *
     * The declared commit must then exist as a real object in THIS
     * repository. `2>/dev/null` keeps git's own error text off this stand's
     * output; only the exit code is read.
     *
     * @throws LedgerError when the declared commit is malformed or absent
     */
    public function assertBeforeCommitExists(string $root): void
    {
        if (preg_match('/^[0-9a-f]{7,40}$/', $this->beforeCommit) !== 1) {
            throw new LedgerError(self::PATH . ' names a malformed before-commit "' . $this->beforeCommit . '"');
        }

        exec(
            'git -C ' . escapeshellarg($root) . ' cat-file -e ' . escapeshellarg($this->beforeCommit . '^{commit}') . ' 2>/dev/null',
            result_code: $exists,
        );

        if ($exists !== 0) {
            throw new LedgerError(self::PATH . ' names a before-commit that does not exist in this repository: ' . $this->beforeCommit);
        }
    }
}
