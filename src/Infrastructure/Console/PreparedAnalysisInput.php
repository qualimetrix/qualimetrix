<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;

/**
 * One resolved invocation: what to analyse, under which rule configuration,
 * over which file set.
 *
 * The three travel together because they were derived from one configuration
 * document and disagree the moment they are not. A caller that keeps the run
 * configuration but builds its own discovery has two answers to "which files"
 * and no way to notice.
 */
final readonly class PreparedAnalysisInput
{
    public function __construct(
        public RunConfiguration $runConfiguration,
        public FindingConfiguration $findingConfiguration,
        public FileDiscoveryInterface $fileDiscovery,
    ) {}
}
