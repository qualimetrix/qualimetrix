<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow;

use InvalidArgumentException;

use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;

/**
 * Thrown by {@see LayerSelectorParser::parse()} when a raw selector string violates
 * the D4 grammar — e.g. unbalanced braces, unknown capture quantifier, or an
 * invalid capture variable name.
 *
 * Lives in the Core domain so the {@code Configuration} validators can catch
 * and re-wrap into a richer {@code ArchitectureConfigurationException} without forcing the
 * Core dependency direction backwards.
 */
final class InvalidSelectorException extends InvalidArgumentException {}
