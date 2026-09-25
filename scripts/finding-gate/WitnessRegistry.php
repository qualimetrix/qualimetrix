<?php

declare(strict_types=1);

namespace QmxFindingGate;

use PhpToken;

/**
 * Every place the gate raises a failure class has a witness, or the class says
 * which package will give it a producer.
 *
 * The unit is the raise site, not the class. A class raised by several checks
 * is only as witnessed as the check a run actually tripped: counting the class
 * let three of the five `run-failed` checks lose their bodies under a green
 * self-test. So the sites are read off the gate's source, the site of every
 * failure a {@see CheckWitnesses} run raised is taken from the report itself,
 * and a site no expectation matched is a guard whose removal leaves the
 * self-test green.
 *
 * Only what the self-test observes counts. `gate:controls` runs neither in
 * `composer check` nor in CI, so a class a control requires is a declaration
 * nobody executes on the way to a merge.
 *
 * A class whose producer a later package introduces may stand here as
 * `pending: S01b/P<n>` with a reason. A pending class that is raised anywhere
 * is a stale row and fails like a stale map row does.
 */
final class WitnessRegistry
{
    /** @var array<string, array{0: string, 1: string}> class => [marker, reason] */
    public const array PENDING = [
        FailureClass::CORPUS_INVALID => [
            'pending: S01b/P5',
            'Declared and raised nowhere: a corpus defect is a GateError with exit 3 today. S01b/P5 gives case'
            . ' inputs their refusal, and this class its producer.',
        ],
    ];

    private const string MARKER = '~^pending: S01b/P[2-8]$~';

    /**
     * Files that raise failures to test the gate rather than to judge a run:
     * the verdict cases fail a report of their own.
     */
    private const array NOT_CHECKS = ['CheckWitnesses', 'SyntheticTree', 'WitnessRegistry'];

    private const string NOT_CHECKS_PREFIX = 'SelfTest';

    /**
     * Every `->fail(FailureClass::X, ...)` call in the gate's source, named
     * `Class::method`, with `#n` in source order when a method raises more than
     * once.
     *
     * A call whose first argument is not a `FailureClass` constant cannot be
     * attributed to a class, and is a problem rather than a site nobody lists.
     *
     * @return array{sites: array<string, array{class: string, file: string, line: int}>, problems: list<string>}
     */
    public static function sites(string $directory): array
    {
        $found = [];
        $problems = [];
        $files = glob($directory . '/*.php');

        foreach ($files === false ? [] : $files as $file) {
            $name = basename($file, '.php');

            if (\in_array($name, self::NOT_CHECKS, true) || str_starts_with($name, self::NOT_CHECKS_PREFIX)) {
                continue;
            }

            foreach (self::callsIn($file) as $call) {
                if ($call['class'] === null) {
                    $problems[] = \sprintf(
                        'witness registry: %s:%d raises a failure whose class is not a FailureClass constant, so no'
                        . ' witness can be held to it.',
                        $file,
                        $call['line'],
                    );

                    continue;
                }

                $found[$call['owner']][] = ['class' => $call['class'], 'file' => $file, 'line' => $call['line']];
            }
        }

        $sites = [];

        foreach ($found as $owner => $calls) {
            foreach ($calls as $index => $call) {
                $sites[\count($calls) === 1 ? $owner : $owner . '#' . ($index + 1)] = $call;
            }
        }

        ksort($sites);

        return ['sites' => $sites, 'problems' => $problems];
    }

    /**
     * @param list<string> $classes
     * @param array<string, string> $sites site => the class it raises
     * @param list<string> $observed the sites a self-test run was seen raising at
     * @param array<string, array{0: string, 1: string}> $pending
     *
     * @return list<string>
     */
    public static function problems(array $classes, array $sites, array $observed, array $pending): array
    {
        $problems = [];

        foreach (array_keys($pending) as $class) {
            if (!\in_array($class, $classes, true)) {
                $problems[] = \sprintf('witness registry: the pending row names "%s", which is not a failure class.', $class);
            }
        }

        $raisedAt = [];

        foreach ($sites as $site => $class) {
            $raisedAt[$class][] = $site;

            if (!\in_array($site, $observed, true)) {
                $problems[] = \sprintf(
                    'witness registry: %s raised at %s has no witness. No self-test run observed this site raise it,'
                    . ' so removing it would leave the self-test green.',
                    $class,
                    $site,
                );
            }
        }

        foreach ($classes as $class) {
            $row = $pending[$class] ?? null;

            if ($row === null) {
                if (!isset($raisedAt[$class])) {
                    $problems[] = \sprintf(
                        'witness registry: %s is raised nowhere in the gate\'s source and stands in no pending row.',
                        $class,
                    );
                }

                continue;
            }

            if (isset($raisedAt[$class])) {
                $problems[] = \sprintf(
                    'witness registry: %s is raised at %s and still stands as "%s". Remove the pending row.',
                    $class,
                    implode(', ', $raisedAt[$class]),
                    $row[0],
                );

                continue;
            }

            if (preg_match(self::MARKER, $row[0]) !== 1 || trim($row[1]) === '') {
                $problems[] = \sprintf(
                    'witness registry: %s is pending as "%s" with reason "%s". A pending row names the S01b package'
                    . ' that introduces the producer ("pending: S01b/P<n>") and says why it waits.',
                    $class,
                    $row[0],
                    $row[1],
                );
            }
        }

        return $problems;
    }

    /** @return list<array{owner: string, class: string|null, line: int}> */
    private static function callsIn(string $file): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize(Fs::read($file)),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $type = basename($file, '.php');
        $method = '(file)';
        $calls = [];

        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;

            if ($token->is(\T_FUNCTION) && $next !== null && $next->is(\T_STRING)) {
                $method = $next->text;

                continue;
            }

            $previous = $tokens[$index - 1] ?? null;

            if (!$token->is(\T_STRING) || $token->text !== 'fail' || $next === null || $next->text !== '('
                || $previous === null || !$previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR])
            ) {
                continue;
            }

            $argument = \array_slice($tokens, $index + 2, 3);
            $constant = \count($argument) === 3 && $argument[0]->text === 'FailureClass' && $argument[1]->is(\T_DOUBLE_COLON)
                ? FailureClass::class . '::' . $argument[2]->text
                : null;
            $class = $constant !== null && \defined($constant) ? \constant($constant) : null;

            $calls[] = [
                'owner' => $type . '::' . $method,
                'class' => \is_string($class) ? $class : null,
                'line' => $token->line,
            ];
        }

        return $calls;
    }
}
