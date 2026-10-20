<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Contract;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleSuppression;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[\PHPUnit\Framework\Attributes\CoversClass(\Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions::class)]
final class ResolvedRuleOptionsTest extends TestCase
{
    #[Test]
    public function itReturnsTheOwnedOptionsAndTypedSuppressionObjectsByIdentity(): void
    {
        $options = new CodeSmellOptions(enabled: false);
        $path = new PathPattern(new SelectorDefinition(SelectorKind::Subtree, 'src/Legacy'));
        $namespace = new NamespacePattern(new SelectorDefinition(SelectorKind::Exact, 'App\\Legacy'));
        $suppression = new RuleSuppression([$path], [$namespace], ['code-smell.goto' => [$namespace]]);
        $snapshot = new ResolvedRuleOptions(['code-smell.goto' => $options], ['code-smell.goto' => $suppression]);

        self::assertSame($options, $snapshot->for('code-smell.goto'));
        self::assertSame(['code-smell.goto' => $options], $snapshot->all());
        self::assertSame($suppression, $snapshot->suppressionFor('code-smell.goto'));
        self::assertSame([$path], $suppression->paths);
        self::assertSame([$namespace], $suppression->namespaces);
        self::assertSame(['code-smell.goto' => [$namespace]], $suppression->namespaceChannels);
    }

    #[Test]
    public function itRefusesAnUnknownProducerInsteadOfInventingDefaults(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('No resolved options for producer "missing".');
        (new ResolvedRuleOptions([], []))->for('missing');
    }

    #[Test]
    public function itRefusesSuppressionForAnUnknownProducer(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('No resolved options for producer "missing".');
        (new ResolvedRuleOptions([], []))->suppressionFor('missing');
    }
}
