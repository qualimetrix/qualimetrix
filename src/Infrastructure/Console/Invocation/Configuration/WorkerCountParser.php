<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Invocation\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

use Qualimetrix\Infrastructure\Console\Invocation\CommandLineSpelling;
use Symfony\Component\Console\Input\InputInterface;

final class WorkerCountParser
{
    public function parse(InputInterface $input): ?int
    {
        $workers = CommandLineSpelling::option($input, 'workers');
        if ($workers === null) {
            return null;
        }
        $parsed = filter_var($workers, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($parsed === false) {
            throw ConfigurationRefusal::aboutCommandLineInput('--workers', \sprintf('Invalid value "%s" for --workers. Expected a non-negative integer.', $workers));
        }
        return $parsed;
    }
}
