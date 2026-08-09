<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Support\Violation;

use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\ViolationChannel;

/**
 * A declaration registry a test states outright.
 *
 * The production registry assembles itself from every tagged rule plus the
 * configured computed metrics; a baseline test needs three or four channels
 * and needs to know exactly which shape each one has. Stating them here
 * keeps a failing baseline assertion about the baseline rather than about
 * which rules happened to be registered.
 */
final class StubChannelDeclarationRegistry implements ChannelDeclarationRegistryInterface
{
    /** @param array<string, ChannelDeclaration> $declarations keyed by {@see ViolationChannel::toKey()} */
    public function __construct(
        private array $declarations = [],
    ) {}

    /**
     * A registry covering the channels the baseline tests use: one
     * higher-is-worse magnitude, one lower-is-worse magnitude, and two
     * occurrence channels — one of which carries a dependency edge.
     */
    public static function withDefaults(): self
    {
        return new self([
            'complexity.cyclomatic#complexity.cyclomatic.callable' => ChannelDeclaration::magnitude(WorseDirection::Higher),
            'duplication.code-duplication#duplication.code-duplication' => ChannelDeclaration::magnitude(WorseDirection::Higher),
            'maintainability.index#maintainability.index.class' => ChannelDeclaration::magnitude(WorseDirection::Lower),
            'code-smell.goto#code-smell.goto' => ChannelDeclaration::occurrence(),
            'architecture.layer-violation#architecture.layer-violation' => ChannelDeclaration::occurrence(),
        ]);
    }

    public function declare(string $channelKey, ChannelDeclaration $declaration): void
    {
        $this->declarations[$channelKey] = $declaration;
    }

    public function declarationFor(ViolationChannel $channel): ?ChannelDeclaration
    {
        return $this->declarations[$channel->toKey()] ?? null;
    }

    public function staticDeclarations(): array
    {
        return $this->declarations;
    }
}
