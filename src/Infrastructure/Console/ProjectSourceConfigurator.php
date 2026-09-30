<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\ProjectNamespaceSourceControlInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;

/** Bind the analysed source to its two consumers before collection. */
final readonly class ProjectSourceConfigurator
{
    public function __construct(
        private ComposerManifestReaderInterface $manifestReader,
        private ProjectNamespaceSourceControlInterface $namespaceSource,
        private AnalysedInstallAnchorInterface $installAnchor,
    ) {}

    /** @param list<AbsolutePath> $paths */
    public function configure(AbsolutePath $root, array $paths): void
    {
        $this->namespaceSource->bind($this->manifestReader->read($root));
        $this->installAnchor->pointAt($root->value(), array_map(static fn(AbsolutePath $path): string => $path->value(), $paths));
    }
}
