<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DocumentationCensus;

/**
 * Which tracked text states the current baseline format number, and what
 * counts as stating it.
 *
 * The one implementation of the sweep: BaselineFormatVersionPublicationTest
 * calls it, and so does the measurement that sized that test's declarations.
 * The caller supplies tracked, non-symlink, non-binary paths and their content.
 *
 * A generated artifact is outside the sweep because its generator writes the
 * number from the constant or from its own sources, which are swept instead; a
 * row that names a hand-written file would hide a statement, so every row
 * names the generator that writes it.
 */
final class BaselineVersionSweep
{
    /**
     * Path (a trailing `/` names a directory) => the command that writes it.
     *
     * @var array<string, string>
     */
    public const array GENERATED = [
        'docs/internal/generated/' => 'the composer generators of check:artifacts',
        'docs/internal/benchmark-baselines.json' => 'composer benchmark:update',
        'docs/internal/benchmark-namespace-class-distribution.json' => 'composer benchmark:update',
        'qmx-baseline.json' => 'bin/qmx baseline:generate',
        'qmx.yaml' => 'composer architecture:generate',
        'finding-gate/enumeration-renames.tsv' => 'composer enumeration:renames',
        'finding-gate/enumeration-renames-executed.tsv' => 'composer enumeration:renames',
        'finding-gate/enumeration-static-channels.tsv' => 'composer enumeration:renames',
        'finding-gate/enumeration-runtime-channels.tsv' => 'composer enumeration:runtime-channels',
        'finding-gate/normalization.tsv' => 'composer gate -- --derive-normalization',
        'finding-gate/equivalence-tuple.tsv' => 'composer gate -- --derive-tuple',
        'directive-audit/enumeration-threshold-directives.tsv' => 'composer enumeration:directives',
        'promise-effect/p1-file-set.tsv' => 'php scripts/promise-effect-p1-set.php --write',
        'promise-effect/promise-ledger-frozen-ranges.tsv' => 'composer promise-ledger:freeze',
        'html-report/dist/' => 'composer build:js',
    ];

    /** Records that state versions as history, and the constant itself. */
    private const array HISTORICAL_OR_SOURCE = [
        'docs/internal/plans/',
        'docs/adr/',
        'CHANGELOG.md',
        'src/Analysis/Policy/Baseline/DocumentReading/BaselineFormatVersion.php',
    ];

    private const string VERSION_WORD = '(?<![\w-])(?:baseline|format|version|schema|snapshot|верси\w*|формат\w*)';

    private const int WINDOW_CHARACTERS = 40;

    public static function isSwept(string $path): bool
    {
        $segments = explode('/', $path);
        if ($segments[0] === 'governance' || \in_array('tests', \array_slice($segments, 0, -1), true)) {
            return false;
        }

        foreach ([...self::HISTORICAL_OR_SOURCE, ...array_keys(self::GENERATED)] as $excluded) {
            if ($path === $excluded || (str_ends_with($excluded, '/') && str_starts_with($path, $excluded))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every whole-token `N` or `vN` that is not part of a number, a date, a
     * time or a digit list, and that a version word introduces: within the
     * preceding window, or as the next word. A `vN` token introduces itself.
     * Numeric matching is byte-based: analysed PHP fixtures may contain invalid
     * identifier bytes. Only the surrounding word-recognition text is scrubbed,
     * so every hit retains its exact source byte offset.
     *
     * @return list<array{offset: int, token: string}>
     */
    public static function hits(string $content, int $current): array
    {
        $number = '~(?<![\w.])(?<!\d[-:,])v?' . $current . '(?!\w|\.\d|[-:,]\d)~';
        if (preg_match_all($number, $content, $matches, \PREG_OFFSET_CAPTURE) === false) {
            return [['offset' => 0, 'token' => 'cannot scan version tokens: ' . preg_last_error_msg()]];
        }

        $hits = [];
        foreach ($matches[0] as [$token, $offset]) {
            $before = mb_scrub(substr($content, 0, $offset), 'UTF-8');
            $window = mb_substr($before, max(0, mb_strlen($before) - self::WINDOW_CHARACTERS));
            $after = mb_scrub(substr($content, $offset + \strlen($token), 64), 'UTF-8');
            if ($token[0] === 'v'
                || preg_match('~' . self::VERSION_WORD . '~iu', $window) === 1
                || preg_match('~^[^\w\n]{0,3}' . self::VERSION_WORD . '~iu', $after) === 1
            ) {
                $hits[] = ['offset' => $offset, 'token' => $token];
            }
        }

        return $hits;
    }
}
