<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

/**
 * Standard option key constants shared across rule Options classes.
 *
 * These keys form the contract between YAML configuration, CLI options,
 * RuleOptionsFactory, ThresholdParser, and individual Options::fromArray() methods.
 */
final class RuleOptionKey
{
    public const string ENABLED = 'enabled';
    public const string WARNING = 'warning';
    public const string ERROR = 'error';
    public const string THRESHOLD = 'threshold';

    private function __construct() {}
}
