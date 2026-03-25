<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use SplFileInfo;

/**
 * Interface for processing a single file.
 *
 * Responsible for parsing, collecting metrics, and dependencies from a single PHP file
 * in a single AST traversal. Implementations should handle memory cleanup after processing.
 */
interface FileProcessorInterface
{
    /**
     * Processes a single file: parse -> collect metrics + dependencies -> cleanup.
     *
     * Both metrics and dependencies are collected in a single AST traversal.
     *
     * @param SplFileInfo $file The file to process
     *
     * @return FileProcessingResult Result containing metrics, dependencies, or error
     */
    public function process(SplFileInfo $file): FileProcessingResult;
}
