<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricConfigurationException;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePreparationException;
use Throwable;

/**
 * Which failures a user fixes in their configuration rather than in the tool,
 * and how they are worded.
 *
 * Named once because it is one judgement made by several commands, and because
 * every command that repeats it is another class naming the exception types of
 * three capabilities it otherwise has no business knowing. A command asks
 * whether the failure was the user's to fix; it does not need the taxonomy to
 * ask.
 *
 * A template-layer expansion failure keeps its own wording — typo'd templates
 * and name collisions are misconfiguration the user fixes, but they surface
 * while the configuration is being *applied*, not while it is being read.
 *
 * `InvalidArgumentException` is deliberately absent. It is a PHP exception, not
 * a capability's statement about configuration: the path and symbol value
 * objects throw it on a violated invariant, which is a bug in the tool. A
 * caller that wants to treat its own malformed input that way says so at the
 * call site, where it knows which arguments it accepted.
 */
final class ConfigurationFailure
{
    /**
     * The message to report, or `null` when the failure is not the user's
     * configuration to fix — in which case the caller owns it, and reporting it
     * as a configuration error would send someone to edit a correct file.
     */
    public static function message(Throwable $failure): ?string
    {
        return match (true) {
            $failure instanceof ArchitecturePreparationException
                => \sprintf('Failed to load configuration: %s', $failure->getMessage()),
            $failure instanceof ConfigLoadException,
            $failure instanceof ArchitectureConfigurationException,
            $failure instanceof ComputedMetricConfigurationException
                => \sprintf('Configuration error: %s', $failure->getMessage()),
            default => null,
        };
    }
}
