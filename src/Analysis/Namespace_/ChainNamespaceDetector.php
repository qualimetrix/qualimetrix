<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Namespace_;

use AiMessDetector\Core\Namespace_\NamespaceDetectorInterface;
use SplFileInfo;
use Throwable;
use Traversable;

final class ChainNamespaceDetector implements NamespaceDetectorInterface
{
    /** @var list<NamespaceDetectorInterface> */
    private readonly array $detectors;

    /**
     * @param iterable<NamespaceDetectorInterface> $detectors
     */
    public function __construct(iterable $detectors)
    {
        $this->detectors = $detectors instanceof Traversable
            ? iterator_to_array($detectors, false)
            : array_values($detectors);
    }

    public function detect(SplFileInfo $file): string
    {
        foreach ($this->detectors as $detector) {
            try {
                $namespace = $detector->detect($file);

                if ($namespace !== '') {
                    return $namespace;
                }
            } catch (Throwable) {
                // Ignore exceptions from individual detectors, try next one
                continue;
            }
        }

        return '';
    }
}
