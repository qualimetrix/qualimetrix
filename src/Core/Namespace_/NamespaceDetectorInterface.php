<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Namespace_;

use SplFileInfo;

interface NamespaceDetectorInterface
{
    /**
     * Detects namespace for given file.
     *
     * @return string namespace or empty string for global namespace
     */
    public function detect(SplFileInfo $file): string;
}
