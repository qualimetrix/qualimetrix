<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use LogicException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;

/** Immutable execution input; the coverage verdict is derived from the measured paths. */
final readonly class RunConfiguration
{
    /** @var list<AbsolutePath> */
    public array $paths;

    public bool $coversProjectScope;

    /**
     * @param list<PathPattern> $pathExcludes
     * @param list<PathPattern> $authoredPathExcludes
     */
    public function __construct(
        public array $pathExcludes,
        public AbsolutePath $projectRoot,
        public GeneratedFilePolicy $generatedFilePolicy,
        public ProjectScopeMeasurement $projectScope,
        public array $authoredPathExcludes,
        public AutoloadDevPolicy $autoloadDevPolicy = AutoloadDevPolicy::Exclude,
    ) {
        $sameRoot = $projectRoot->equals($projectScope->projectRoot);
        foreach ($projectScope->pathResolutions as $resolution) {
            $sameRoot = $sameRoot || ($projectRoot->equals($resolution['written']) && $projectScope->projectRoot->equals($resolution['path']));
        }
        if (!$sameRoot) {
            throw new LogicException('Run configuration requires the measured project root or a captured alias of it');
        }

        $this->paths = $projectScope->paths;
        $this->coversProjectScope = $projectScope->state()->coversProjectScope();
    }

    public function withProjectScope(ProjectScopeMeasurement $measurement): self
    {
        return new self(
            pathExcludes: $this->pathExcludes,
            projectRoot: $this->projectRoot,
            generatedFilePolicy: $this->generatedFilePolicy,
            projectScope: $measurement,
            authoredPathExcludes: $this->authoredPathExcludes,
            autoloadDevPolicy: $this->autoloadDevPolicy,
        );
    }
}
