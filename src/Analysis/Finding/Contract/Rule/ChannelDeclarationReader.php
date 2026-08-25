<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Reads a rule class's declared channel metadata, without instantiating it.
 *
 * A rule declares its channels via an optional static
 * `channelDeclarations(): array<string, ChannelDeclaration>` method, keyed by
 * the **channel's own name** — the same string the baseline file stores and a
 * finding publishes as its `code`. A rule may declare a channel whose name is
 * nothing like its own (`LayerViolationRule` does this for all but one of its
 * channels — see {@see FindingChannel}'s docblock), so the key is the name
 * outright and never derived from the declaring class.
 *
 * This is deliberately not part of `RuleInterface` and not an attribute: an
 * interface method would force every rule — including the majority that
 * declares nothing — to implement a no-op override, and reflection on a
 * plain static method is exactly the idiom {@see RuleNameReader} and
 * {@see CliAliasReader} already establish for rule metadata that must be
 * readable without building the rule (a rule may take constructor
 * dependencies beyond its Options object, so only the DI container may
 * instantiate one).
 *
 * A rule with no `channelDeclarations()` method simply has no entry here —
 * {@see read()} returns an empty array, and the rule class is untouched by
 * this mechanism.
 */
final class ChannelDeclarationReader
{
    private const string METHOD = 'channelDeclarations';

    /**
     * @param class-string $ruleClass
     *
     * @throws LogicException when the class cannot be loaded, when it
     *                        declares the method with a shape this reader
     *                        cannot trust (non-static, non-public, a key
     *                        that is not a valid channel name, or a value
     *                        that is not a {@see ChannelDeclaration}), or
     *                        when invoking the method itself throws — e.g.
     *                        an abstract base's `channelDeclarations()`
     *                        binding an empty `static::NAME` into a
     *                        {@see FindingChannel} that rejects it. Every
     *                        exit from this method is a `LogicException`;
     *                        no other exception type escapes it.
     *
     * @return array<string, ChannelDeclaration> keyed by channel name
     */
    public static function read(string $ruleClass): array
    {
        if (!class_exists($ruleClass)) {
            throw new LogicException(\sprintf(
                'Rule class %s does not exist or cannot be autoloaded.',
                $ruleClass,
            ));
        }

        $reflection = new ReflectionClass($ruleClass);

        if (!$reflection->hasMethod(self::METHOD)) {
            return [];
        }

        $method = $reflection->getMethod(self::METHOD);

        if (!$method->isStatic() || !$method->isPublic()) {
            throw new LogicException(\sprintf(
                '%s::%s() must be public and static — it is read via reflection without instantiating the rule.',
                $ruleClass,
                self::METHOD,
            ));
        }

        $returnType = $method->getReturnType();
        if (!$returnType instanceof ReflectionNamedType || $returnType->getName() !== 'array') {
            throw new LogicException(\sprintf(
                '%s::%s() must be declared to return array.',
                $ruleClass,
                self::METHOD,
            ));
        }

        $declared = self::invoke($method, $ruleClass);

        return self::validate($declared, $ruleClass);
    }

    /**
     * Isolated from {@see read()} and {@see validate()} so this method's own
     * NPath complexity stays low: three small methods sum their branching
     * instead of one large method multiplying it.
     *
     * @param class-string $ruleClass
     *
     * @return array<mixed, mixed>
     */
    private static function invoke(ReflectionMethod $method, string $ruleClass): array
    {
        try {
            /** @var array<mixed, mixed> $declared */
            $declared = $method->invoke(null);

            return $declared;
        } catch (Throwable $exception) {
            // Wrapped unconditionally, even though InvalidArgumentException
            // (the case an abstract base's empty static::NAME triggers, via
            // FindingChannel's own validation) already extends
            // LogicException by PHP's own hierarchy: the type contract
            // technically holds either way, but the raw message names
            // neither the rule class nor channelDeclarations() — exactly
            // the diagnostic gap the docblock's promise exists to close.
            throw new LogicException(\sprintf(
                '%s::%s() threw while being invoked: %s',
                $ruleClass,
                self::METHOD,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    /**
     * @param array<mixed, mixed> $declared
     * @param class-string $ruleClass
     *
     * @return array<string, ChannelDeclaration>
     */
    private static function validate(array $declared, string $ruleClass): array
    {
        $declarations = [];

        foreach ($declared as $key => $declaration) {
            if (!\is_string($key) || $key === '') {
                throw new LogicException(\sprintf(
                    '%s::%s() must be keyed by a non-empty channel name.',
                    $ruleClass,
                    self::METHOD,
                ));
            }

            try {
                new FindingChannel($key);
            } catch (InvalidArgumentException $exception) {
                throw new LogicException(\sprintf(
                    '%s::%s() key "%s" is not a valid channel name: %s',
                    $ruleClass,
                    self::METHOD,
                    $key,
                    $exception->getMessage(),
                ), previous: $exception);
            }

            if (!$declaration instanceof ChannelDeclaration) {
                throw new LogicException(\sprintf(
                    '%s::%s()["%s"] must be a %s instance.',
                    $ruleClass,
                    self::METHOD,
                    $key,
                    ChannelDeclaration::class,
                ));
            }

            $declarations[$key] = $declaration;
        }

        return $declarations;
    }
}
