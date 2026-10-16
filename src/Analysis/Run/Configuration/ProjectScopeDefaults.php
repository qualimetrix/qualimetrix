<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship;

/** Judges whether the selected manifest universe can supply default analysis paths. */
final class ProjectScopeDefaults
{
    public static function selectedComplete(ComposerManifestFacts $facts, AutoloadDevPolicy $autoloadDev): bool
    {
        return $facts->state === ManifestReadState::Read && $facts->production->complete
            && ($autoloadDev === AutoloadDevPolicy::Exclude || $facts->development->complete);
    }

    /**
     * @param list<string> $targets
     * @param list<string> $reachable
     */
    public static function refuseUnavailable(ComposerManifestFacts $facts, array $targets, array $reachable, AutoloadDevPolicy $autoloadDev, PathsAuthorship $authorship): void
    {
        if ($authorship === PathsAuthorship::Authored) {
            return;
        }
        if (($facts->state !== ManifestReadState::Read && $facts->state !== ManifestReadState::Absent)
            || ($targets === [] && !self::selectedComplete($facts, $autoloadDev) && $facts->state !== ManifestReadState::Absent)
            || ($targets !== [] && $reachable === [])) {
            throw ConfigurationRefusal::aboutDocument(ConfigurationOrigin::of(ConfigurationSource::ComposerJson, $facts->source()), 'Cannot infer analysis paths from composer.json: its selected autoload universe is unreadable or has no usable targets. Write explicit paths to analyse.');
        }
    }
}
