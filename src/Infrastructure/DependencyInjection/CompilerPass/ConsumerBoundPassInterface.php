<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

/**
 * A compiler pass that writes into services it names by id.
 *
 * Such a pass skips its step when a named service is not registered, which is
 * what lets it run over a partial fixture container. In the product container
 * the same skip would be a silent loss — a renamed service, a mistyped
 * literal — so {@see ConsumerRegistrationCompilerPass} refuses that build
 * before any of these passes runs, reading the ids from here.
 */
interface ConsumerBoundPassInterface
{
    /**
     * Every service id the pass writes into, spelled by the same constants the
     * pass itself reads — a second spelling would let the two drift apart.
     *
     * @return non-empty-list<string>
     */
    public static function consumerServiceIds(): array;
}
