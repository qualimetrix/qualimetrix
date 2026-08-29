<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

/**
 * Prepares circular-dependency evidence for one analysis run.
 *
 * The named consumer is Analysis.Run, which selects this preparation by the
 * producer rule name but never receives the prepared cycle result.
 */
interface CircularDependencyPreparationInterface
{
    public const string PRODUCER_RULE_NAME = 'architecture.circular-dependency';

    /**
     * This capability's channels that are **not** file-scoped: a cycle is a
     * property of the dependency graph, not of the file a member of it happens
     * to sit in, so `exclude_paths` and `exclude_namespaces` do not apply to
     * its findings. Declared here rather than inferred from the
     * `architecture.` spelling — see
     * {@see \Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope}.
     *
     * @var list<string> channel names
     */
    public const array PROJECT_SCOPED_CHANNELS = [
        self::PRODUCER_RULE_NAME,
    ];

    public function prepare(DependencyGraphInterface $graph): void;

    public function reset(): void;
}
