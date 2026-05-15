<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Allow;

use InvalidArgumentException;

/**
 * Thrown by {@see LayerSelectorParser::parse()} when a raw selector string violates
 * the D4 grammar — e.g. unbalanced braces, unknown capture quantifier, or an
 * invalid capture variable name.
 *
 * Lives in the Core domain so the {@code Configuration} validators can catch
 * and re-wrap into a richer {@code ConfigLoadException} without forcing the
 * Core dependency direction backwards.
 */
final class InvalidSelectorException extends InvalidArgumentException {}
