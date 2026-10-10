<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/** Publishes resolved configuration warnings through the CLI and report doors. */
final readonly class ConfigurationDiagnosticsPublisher
{
    public function __construct(private ErrorStream $errorStream) {}

    /** @param list<ConfigurationDiagnostic> $additional */
    public function write(ConfigurationDocument $document, OutputInterface $output, array $additional = []): void
    {
        foreach ([...$document->diagnostics(), ...$additional] as $diagnostic) {
            $this->errorStream->write($output, \sprintf('<comment>Warning: %s</comment>', OutputFormatter::escape($diagnostic->message)));
        }
    }

    /**
     * @param list<ConfigurationDiagnostic> $additional
     *
     * @return list<array{message: string, source: list<array<string, mixed>>}>
     */
    public function report(ConfigurationDocument $document, array $additional = []): array
    {
        $published = [];
        foreach ([...$document->diagnostics(), ...$additional] as $diagnostic) {
            $published[] = [
                'message' => $diagnostic->message,
                'source' => array_map(
                    static fn(Provenance $provenance): array => RefusalPresenter::sourceDocument($provenance->origin),
                    $diagnostic->sources,
                ),
            ];
        }

        return $published;
    }
}
