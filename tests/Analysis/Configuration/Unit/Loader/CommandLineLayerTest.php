<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Loader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\CommandLinePathWrite;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Loader\CommandLineLayer;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(CommandLineLayer::class)]
final class CommandLineLayerTest extends TestCase
{
    #[Test]
    public function itPlacesIndependentRuleWritesAtTheirCanonicalPaths(): void
    {
        $layer = CommandLineLayer::of($this->request([
            new CommandLinePathWrite(['rules', 'complexity.ccn', 'warning'], '10', '--ccn-warning', '--ccn-warning=10', NodeSchema::scalar(ScalarForm::Integer)),
            new CommandLinePathWrite(['rules', 'complexity.ccn', 'class', 'error'], '20', '--rule-opt', '--rule-opt=complexity.ccn:class.error=20', NodeSchema::scalar(ScalarForm::Integer)),
        ]));

        self::assertSame(['rules' => ['complexity.ccn' => ['warning' => 10, 'class' => ['error' => 20]]]], $layer->root->plain());
        self::assertSame('--ccn-warning', $layer->root->children['rules']->children['complexity.ccn']->children['warning']->locator);
        self::assertSame('--ccn-warning=10', $layer->root->children['rules']->children['complexity.ccn']->children['warning']->authoredExpression);
    }

    #[Test]
    public function itRefusesRepeatedAndPrefixOverlapsBeforeMerge(): void
    {
        foreach ([['warning'], ['class'], ['class', 'warning']] as $second) {
            $first = $second === ['warning'] ? ['warning'] : ['class', 'warning'];
            try {
                CommandLineLayer::of($this->request([
                    new CommandLinePathWrite(['rules', 'complexity.ccn', ...$first], '10', '--first', '--first=10', NodeSchema::scalar(ScalarForm::Integer)),
                    new CommandLinePathWrite(['rules', 'complexity.ccn', ...$second], '20', '--second', '--second=20', NodeSchema::scalar(ScalarForm::Integer)),
                ]));
                self::fail('The overlapping writes should be refused.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertStringContainsString('--first=10', $refusal->getMessage());
                self::assertStringContainsString('--second=20', $refusal->getMessage());
            }
        }
    }

    /** @param list<CommandLinePathWrite> $writes */
    private function request(array $writes): ConfigurationResolutionRequest
    {
        return new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliPathWrites: $writes);
    }
}
