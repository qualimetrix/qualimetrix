<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\TargetPath;

/** Reads and judges one held baseline document before analysis starts. */
final readonly class BaselineDocumentReader
{
    /** @throws ConfigurationRefusal if the file is unreadable or its document grammar is invalid */
    public function preflight(string $path): BaselineDocument
    {
        $this->assertReadable($path);
        $target = TargetPath::resolve($path);
        $content = $this->readHeld($path, $target);

        $canonical = CanonicalBaselineReader::grammarEnvelope($content, $path);
        $data = $canonical === null ? BaselineFileShape::decode($content, $path) : [...$canonical, 'entries' => []];
        $envelope = BaselineFileShape::envelope($data, $path);

        return new BaselineDocument(
            path: $path,
            contentHash: hash('sha256', $content),
            version: BaselineFormatVersion::CURRENT,
            generated: $envelope['generated'],
            scope: $envelope['scope'],
            exclusions: $envelope['exclusions'],
            target: $target,
            bytes: $content,
        );
    }

    private function readHeld(string $path, \Qualimetrix\Core\FileTarget\ResolvedTarget $target): string
    {
        $resolvedPath = $target->path?->value();
        if ($resolvedPath === null || $target->identity === null) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Failed to read baseline file: {$path}");
        }

        $handle = @fopen($resolvedPath, 'rb');
        if ($handle === false) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Failed to read baseline file: {$path}");
        }
        try {
            $stat = fstat($handle);
            if ($stat === false || !$target->identity->sameAs(FileIdentity::fromStat($stat))) {
                throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Baseline file changed before reading: {$path}");
            }
            $content = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($content === false) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Failed to read baseline file: {$path}");
        }

        return $content;
    }

    /** @throws ConfigurationRefusal if the file is missing, not a regular file, or unreadable */
    public function assertReadable(string $path): void
    {
        if (!file_exists($path)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Baseline file not found: {$path}");
        }
        if (!is_file($path)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Baseline path is not a regular file: {$path}");
        }
        if (!is_readable($path)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Baseline file is not readable: {$path}");
        }
    }
}
