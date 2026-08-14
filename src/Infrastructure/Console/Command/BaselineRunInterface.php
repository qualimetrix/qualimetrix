<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePreparationException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The one way a baseline command obtains the set it measures.
 *
 * All five commands of ADR 0017 resolve configuration, configure the runtime and
 * run the analysis in exactly the same way; only what they then do with the
 * findings differs. Naming that shared half is what keeps ADR 0017 "one
 * measured set" true across commands rather than true five times over by
 * coincidence.
 *
 * It is an interface so a command can be exercised against a known set of
 * findings without a real runtime: the commands' own behaviour — refusals,
 * scope guards, what they write — is what their tests are about, and running
 * a parser over fixtures to reach it would test the pipeline instead.
 */
interface BaselineRunInterface
{
    /**
     * Resolves configuration from the command's input, prepares the runtime,
     * and analyses the configured paths.
     *
     * @throws ConfigLoadException when the configuration cannot be read
     * @throws ArchitectureConfigurationException when Architecture configuration is invalid
     * @throws ArchitecturePreparationException when Architecture preparation cannot complete
     * @throws InvalidArgumentException when a requested path does not exist
     */
    public function measure(InputInterface $input, OutputInterface $output): BaselineRunContext;
}
