<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * What a run knows about the symbol `bin/qmx baseline:explain` was asked
 * about: which identities are worth reporting on, and which typed subject
 * and location the run measured for it.
 *
 * This is the question asked *before* an explanation can be built, and it is
 * answered out of the baseline, the measured findings and the repository
 * alone — no boundary, no annotation and no configured threshold enters
 * here. {@see BoundaryExplanationService} is the consumer and owns the other
 * half.
 *
 * The methods are static because the answer is a pure function of the run
 * data handed in; the class holds no state of its own.
 *
 * @phpstan-type SubjectRecord array{subject: ?MetricSubject, location: ?array{0: RelativePath, 1: int}}
 */
final readonly class ExplainedSubject
{
    /**
     * Every identity worth reporting on: every baseline entry for this
     * symbol, plus every channel currently firing for it, narrowed to one
     * channel when `$channelFilter` is given. When neither source has
     * anything and a channel was explicitly asked for, a bare identity with
     * no edge is still returned — `qmx.yaml` and the annotation may have
     * something to say about a channel that simply is not breaching right
     * now.
     *
     * @param list<Finding> $measuredFindings
     *
     * @return list<BaselineIdentity>
     */
    public static function identities(
        string $symbolKey,
        ?FindingChannel $channelFilter,
        ?Baseline $baseline,
        array $measuredFindings,
    ): array {
        /** @var array<string, BaselineIdentity> $byKey */
        $byKey = [];

        if ($baseline !== null) {
            foreach ($baseline->entries as $entry) {
                $identity = $entry->identity;

                if ($identity->subjectKey !== $symbolKey) {
                    continue;
                }

                if ($channelFilter !== null && !$identity->channel->equals($channelFilter)) {
                    continue;
                }

                $byKey[$identity->key()] = $identity;
            }
        }

        foreach ($measuredFindings as $finding) {
            if ($finding->subject->toCanonical() !== $symbolKey) {
                continue;
            }

            $identity = BaselineIdentity::forFinding($finding);

            if ($channelFilter !== null && !$identity->channel->equals($channelFilter)) {
                continue;
            }

            $byKey[$identity->key()] ??= $identity;
        }

        if ($byKey === [] && $channelFilter !== null) {
            $bare = new BaselineIdentity($symbolKey, $channelFilter);
            $byKey[$bare->key()] = $bare;
        }

        return array_values($byKey);
    }

    /**
     * @return array<string, SubjectRecord>
     */
    public static function index(?MetricRepositoryInterface $repository): array
    {
        if ($repository === null) {
            return [];
        }

        $index = [];
        foreach ([$repository->allDeclarations(), $repository->allCallables()] as $symbols) {
            $exactRows = iterator_to_array($symbols);
            array_walk($exactRows, static function ($info) use (&$index): void {
                $subject = $info->subject ?? throw new InvalidArgumentException(
                    'Exact repository rows must retain their typed subject.',
                );
                $index[$subject->toCanonical()] ??= self::record($subject, $info->file, $info->line);
            });
        }

        $projectedSources = [$repository->allLogicalClasses()];
        foreach (SymbolLevel::cases() as $level) {
            $projectedSources[] = $repository->all($level);
        }

        foreach ($projectedSources as $symbols) {
            foreach ($symbols as $info) {
                $key = $info->subject?->toCanonical() ?? $info->symbolPath->toCanonical();
                $index[$key] ??= self::record($info->subject, $info->file, $info->line);
            }
        }

        return $index;
    }

    /**
     * @param array<string, SubjectRecord> $index
     *
     * @return ?SubjectRecord
     */
    public static function recordFor(string $subjectKey, array $index): ?array
    {
        return $index[$subjectKey] ?? null;
    }

    /**
     * The exact typed subject an identity is about: a current finding first,
     * repository evidence second. A logical projection never invents a
     * declaration subject.
     *
     * @param list<Finding> $measuredFindings
     * @param ?SubjectRecord $repositoryRecord
     */
    public static function subjectFor(
        BaselineIdentity $identity,
        array $measuredFindings,
        ?array $repositoryRecord,
    ): ?MetricSubject {
        foreach ($measuredFindings as $finding) {
            if ($finding->subject->toCanonical() === $identity->subjectKey) {
                return $finding->subject;
            }
        }

        return $repositoryRecord['subject'] ?? null;
    }

    /** @return SubjectRecord */
    private static function record(?MetricSubject $subject, ?RelativePath $file, ?int $line): array
    {
        return [
            'subject' => $subject,
            'location' => $file !== null && $line !== null ? [$file, $line] : null,
        ];
    }
}
