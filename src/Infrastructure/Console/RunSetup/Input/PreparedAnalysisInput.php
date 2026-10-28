<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunSetup\Input;

use Closure;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;

/**
 * One resolved invocation: what to analyse, under which rule configuration,
 * over which file set, and the optional finding configuration it requires.
 *
 * The three travel together because they were derived from one configuration
 * document and disagree the moment they are not. A caller that keeps the run
 * configuration but builds its own discovery has two answers to "which files"
 * and no way to notice.
 *
 * `inputFileReadAs` names the file the run reads with a given identity — a
 * configuration file, the Composer manifest or an analysed source — one a
 * command writing a target must not replace.
 */
final readonly class PreparedAnalysisInput
{
    /** @param Closure(?\Qualimetrix\Core\FileTarget\FileIdentity): ?string $inputFileReadAs */
    public function __construct(
        public RunConfiguration $runConfiguration,
        public ?FindingConfiguration $findingConfiguration,
        public Closure $inputFileReadAs,
    ) {}
}
