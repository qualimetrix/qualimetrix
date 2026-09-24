<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

use LogicException;

/**
 * What the run's paths were, measured against the project's `composer.json`
 * autoload, as every format publishes it.
 *
 * Some channels speak only on a run that covers the whole project — a layer
 * that matched nothing, an `exclude:` that removed nothing — and fall silent on
 * a run over a slice of it. The console says so on stderr, which neither `-q`
 * nor a machine format keeps; without this a CI pipeline could not tell "no
 * stale configuration" from "not judged on this run".
 *
 * Three states: `covered` (the paths reach every declared autoload target),
 * `narrowed` (they miss some; the channels judged only on a whole-project run
 * were not judged) and `unknown` (the manifest declares no readable production
 * autoload, so the analysed paths were taken as the whole project and those
 * channels judged them).
 *
 * **A run that judges is not a run that judged every value.** On `covered` and
 * `unknown` the suppression channels still ask each configured value whether
 * this run reaches the place it names, and skip one it does not:
 * `suppress_paths: [tests/Legacy]` on `qmx check src/`, with `tests/` declared
 * only for development, or any namespace value on an `unknown` project, which
 * has no declared autoload to place it. `unjudgedValues` names every such
 * value and `unjudgedChannels` the channels they belong to — derived from the
 * values, so the channel list cannot claim a silence no value suffered. On
 * `narrowed` the channel list is the whole family and says it alone: no value
 * of those channels was judged.
 *
 * A structured format publishes it where it says something about the report
 * itself: a document under a key of its own, in every state; `sarif` as a
 * notification, `github` as a notice, `html` as a banner and a human format as
 * a line, whenever {@see describe()} has something to say — every state but a
 * `covered` run that skipped no value. `gitlab` and `checkstyle` omit it:
 * their consumers count every entry as a finding, and a narrowed run is a
 * choice of the caller's, not a defect of the run to be counted.
 */
final readonly class ReportProjectScope
{
    /** Check name / descriptor suffix the list formats publish the entry under. */
    public const string CHECK = 'run.project-scope';

    public const string COVERED = 'covered';
    public const string NARROWED = 'narrowed';
    public const string UNKNOWN = 'unknown';

    /**
     * @param list<string> $uncoveredAutoloadTargets
     * @param list<string> $unjudgedChannels
     * @param list<array{option: string, pattern: string}> $unjudgedValues
     */
    private function __construct(
        public string $state,
        public array $uncoveredAutoloadTargets,
        public array $unjudgedChannels,
        public array $unjudgedValues = [],
    ) {}

    public static function covered(): self
    {
        return new self(self::COVERED, [], []);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN, [], []);
    }

    /**
     * @param list<string> $uncoveredAutoloadTargets the declared targets no analysed path contains
     * @param list<string> $unjudgedChannels the channels that speak only on a whole-project run
     */
    public static function narrowed(array $uncoveredAutoloadTargets, array $unjudgedChannels): self
    {
        return new self(self::NARROWED, $uncoveredAutoloadTargets, $unjudgedChannels);
    }

    /**
     * This scope with the values a judging run skipped, each under the
     * channel that would have reported it; the channel list becomes the
     * distinct channels of those values.
     *
     * Refused on `narrowed`: that run judges no value, its channel list
     * already says so, and a value list beside it could only contradict it.
     *
     * @param list<array{channel: string, option: string, pattern: string}> $values
     */
    public function withUnjudgedValues(array $values): self
    {
        if ($this->state === self::NARROWED) {
            throw new LogicException('A narrowed run judges no configured value, so it has none to skip.');
        }

        $channels = array_values(array_unique(array_column($values, 'channel')));
        sort($channels);

        return new self(
            $this->state,
            $this->uncoveredAutoloadTargets,
            $channels,
            array_map(static fn(array $value): array => ['option' => $value['option'], 'pattern' => $value['pattern']], $values),
        );
    }

    /**
     * One shape in every state, so a consumer reads the same keys whether the
     * run was narrowed or not.
     *
     * @return array{state: string, uncoveredAutoloadTargets: list<string>, unjudgedChannels: list<string>, unjudgedValues: list<array{option: string, pattern: string}>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'uncoveredAutoloadTargets' => $this->uncoveredAutoloadTargets,
            'unjudgedChannels' => $this->unjudgedChannels,
            'unjudgedValues' => $this->unjudgedValues,
        ];
    }

    /**
     * The sentence every non-document format uses; `null` only for a
     * `covered` run that skipped no value, the one state that needs no
     * explaining.
     */
    public function describe(): ?string
    {
        return match ($this->state) {
            self::NARROWED => \sprintf(
                'Project scope narrowed: the analysed paths do not cover autoload target(s) %s, so these channels,'
                . ' judged only on a whole-project run, were not judged: %s.',
                implode(', ', $this->uncoveredAutoloadTargets),
                implode(', ', $this->unjudgedChannels),
            ),
            self::UNKNOWN => 'Project scope unknown: composer.json declares no production autoload a walk of the project reaches,'
                . ' so the analysed paths were taken as the whole project and whole-project channels judged them.'
                . ($this->unjudgedValues === [] ? '' : ' Without a declared autoload a configured namespace has no location.'
                    . $this->describeUnjudgedValues()),
            default => $this->unjudgedValues === []
                ? null
                : 'Project scope covered: the analysed paths cover every autoload target.' . $this->describeUnjudgedValues(),
        };
    }

    private function describeUnjudgedValues(): string
    {
        return \sprintf(
            ' These configured suppression values name a place this run did not analyse or cannot locate, and were'
            . ' not judged: %s.',
            implode(', ', array_map(
                static fn(array $value): string => \sprintf('%s "%s"', $value['option'], $value['pattern']),
                $this->unjudgedValues,
            )),
        );
    }
}
