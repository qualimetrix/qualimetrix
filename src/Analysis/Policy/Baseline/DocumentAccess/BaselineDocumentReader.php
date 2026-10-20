<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\DocumentAccess;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;
use Qualimetrix\Analysis\Policy\Baseline\Contract\Entry\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\DocumentReading\BaselineFileShape;

use Qualimetrix\Analysis\Policy\Baseline\DocumentReading\BaselineFormatVersion;
use Qualimetrix\Analysis\Policy\Baseline\DocumentReading\CanonicalBaselineReader;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\NativeCall;
use Qualimetrix\Core\FileTarget\Resolution\TargetPath;

/** Reads and judges one held baseline document before analysis starts. */
final readonly class BaselineDocumentReader
{
    /** @throws ConfigurationRefusal if the input file or its document grammar is invalid */
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

    private function readHeld(string $path, \Qualimetrix\Core\FileTarget\Resolution\ResolvedTarget $target): string
    {
        $resolvedPath = $target->path?->value();
        if ($resolvedPath === null || $target->identity === null) {
            throw new BaselineReadFailure($path, 'the resolved target has no readable file identity');
        }

        [$handle, $warning] = NativeCall::attempt(static fn() => fopen($resolvedPath, 'rb'));
        if ($handle === false) {
            throw new BaselineReadFailure($path, $warning ?? 'the filesystem refused opening the file');
        }
        try {
            $this->assertHeldIdentity($path, $target->identity, $handle);
            [$content, $warning] = NativeCall::attempt(static fn() => stream_get_contents($handle));
        } finally {
            fclose($handle);
        }

        if ($content === false) {
            throw new BaselineReadFailure($path, $warning ?? 'the filesystem refused reading the held file');
        }

        return $content;
    }

    /** @param resource $handle */
    private function assertHeldIdentity(string $path, FileIdentity $identity, mixed $handle): void
    {
        [$stat, $warning] = NativeCall::attempt(static fn() => fstat($handle));
        if ($stat === false) {
            throw new BaselineReadFailure($path, $warning ?? 'the filesystem refused inspecting the held file');
        }
        if (!$identity->sameAs(FileIdentity::fromStat($stat))) {
            throw new BaselineConflictException("Baseline file {$path} changed before reading. Re-run the command to pick up the current file.");
        }
    }

    /** @throws ConfigurationRefusal if the file is missing or not a regular file */
    public function assertReadable(string $path): void
    {
        if (!file_exists($path)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Baseline file not found: {$path}");
        }
        if (!is_file($path)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Baseline path is not a regular file: {$path}");
        }
        if (!is_readable($path)) {
            throw new BaselineReadFailure($path, 'read access is unavailable');
        }
    }
}
