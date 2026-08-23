<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Support;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;

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
    /**
     * @param array<string, ChannelDeclaration> $declarations keyed by {@see ViolationChannel::toKey()}
     * @param ?ChannelDeclaration $default answer for a channel absent from $declarations — for a test
     *                                     that needs every channel to resolve to the same shape rather
     *                                     than stating each one, see {@see alwaysHigherMagnitude()}
     */
    public function __construct(
        private array $declarations = [],
        private ?ChannelDeclaration $default = null,
    ) {}

    /**
     * A registry covering the channels the baseline tests use: one
     * higher-is-worse magnitude, one lower-is-worse magnitude, and two
     * occurrence channels — one of which carries a dependency edge.
     */
    public static function withDefaults(): self
    {
        return new self([
            'complexity.cyclomatic#complexity.cyclomatic.callable' => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Callable),
            'duplication.code-duplication#duplication.code-duplication' => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Project),
            'maintainability.index#maintainability.index.class' => ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_),
            'code-smell.goto#code-smell.goto' => ChannelDeclaration::occurrence(SymbolLevel::Callable),
            'architecture.layer-violation#architecture.layer-violation' => ChannelDeclaration::occurrence(SymbolLevel::Class_),
        ]);
    }

    /**
     * Every channel resolves to a higher-is-worse magnitude declaration,
     * regardless of its name. For a test whose subject is not the
     * declaration lookup itself (formatter rendering, debt totals) and which
     * uses rule names that are not necessarily real production rules, this
     * stands in for "whatever the real declaration would say" without
     * stating one per name.
     */
    public static function alwaysHigherMagnitude(): self
    {
        return new self(default: ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_));
    }

    public function declare(string $channelKey, ChannelDeclaration $declaration): void
    {
        $this->declarations[$channelKey] = $declaration;
    }

    public function declarationFor(ViolationChannel $channel): ?ChannelDeclaration
    {
        return $this->declarations[$channel->toKey()] ?? $this->default;
    }

    public function staticDeclarations(): array
    {
        return $this->declarations;
    }
}
