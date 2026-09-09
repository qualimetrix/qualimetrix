<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Refusal;

/**
 * Where a configuration contribution came from.
 *
 * Five cases, not seven: `Defaults` and `ComposerJson` have no producer of a
 * {@see ConfigurationRefusal} (see `03-carrier-and-normalization.md` §2 for why).
 */
enum ConfigurationSource
{
    case ConfigFile;
    case Preset;
    case CommandLine;
    case BaselineFile;

    /**
     * The merged document: contributions here can no longer be attributed to a
     * single file or CLI option (`ConfigurationDocument::contributions()` drops
     * the source label on merge).
     */
    case Resolved;
}
