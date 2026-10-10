<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Refusal;

/**
 * The kind of source a configuration value or a refusal came from. The backing
 * value is the name the JSON refusal envelope publishes.
 *
 * A file imported by another file is a {@see self::ConfigFile} whose
 * {@see ConfigurationOrigin::importer()} names the importing source, not a kind
 * of its own: the chain lives on the origin, so a new kind is needed only for a
 * source that is not a file at all.
 */
enum ConfigurationSource: string
{
    case Defaults = 'defaults';
    case ComposerJson = 'composer';
    case Preset = 'preset';
    case ConfigFile = 'file';
    case CommandLine = 'cli';
    case Environment = 'environment';
    case BaselineFile = 'baseline';

    /**
     * The merged document, used by Finding while its temporary raw readers fold
     * `rules`, `only_rules`, and `disabled_rules`; resolved readers name their
     * authored layers instead.
     */
    case Resolved = 'resolved';
}
