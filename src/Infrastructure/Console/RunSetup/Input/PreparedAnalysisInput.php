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
 * `configurationFileReadAs` names the file that document was read from with a
 * given identity — one a command writing a target must not replace.
 */
final readonly class PreparedAnalysisInput
{
    /** @param Closure(?\Qualimetrix\Core\FileTarget\FileIdentity): ?string $configurationFileReadAs */
    public function __construct(
        public RunConfiguration $runConfiguration,
        public ?FindingConfiguration $findingConfiguration,
        public Closure $configurationFileReadAs,
    ) {}
}
