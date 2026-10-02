<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Core\Path\AbsolutePath;

/** Measures the selected code universe once; subsequent Git narrowing uses its captured denominator. */
final readonly class ProjectScopeCoverage
{
    public function __construct(private ComposerManifestReaderInterface $composerReader) {}

    /** @param list<AbsolutePath> $analyzedPaths */
    public function measure(AbsolutePath $projectRoot, array $analyzedPaths, AutoloadDevPolicy $autoloadDev, PathsAuthorship $authorship): ProjectScopeMeasurement
    {
        $evidence = new ManifestScopeEvidence($this->composerReader->read($projectRoot), $autoloadDev);
        $targets = $evidence->targets();
        [$reachable, $pruned] = ProjectScopePaths::partition($projectRoot, $targets);
        $reasons = $evidence->sourceReasons($pruned);
        $denominator = ProjectScopePaths::resolveDenominator($reachable, $projectRoot, $reasons);
        $evidence->refuseUnavailable($targets, $reachable, $authorship);
        $root = ProjectScopePaths::canonicalRoot($projectRoot);
        $evidence->addUniverseReason($reachable, $reasons);
        $universe = new ProjectScopeUniverse(
            $root,
            $authorship === PathsAuthorship::Authored,
            $denominator,
            $pruned,
            $reasons,
            $evidence->hasDeclaredNamespaces(),
            ProjectScopePaths::captureResolutions($projectRoot, $root, $analyzedPaths),
        );
        $uncovered = $universe->uncoveredBy($analyzedPaths);

        return new ProjectScopeMeasurement($universe, $analyzedPaths, $evidence->initialState($universe, $analyzedPaths, $reachable), $uncovered);
    }
}
