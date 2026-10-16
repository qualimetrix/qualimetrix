<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignment;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentJsonPresenter;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(LayerAssignmentJsonPresenter::class)]
final class LayerAssignmentJsonPresenterTest extends TestCase
{
    #[Test]
    #[DataProvider('provideObservedNames')]
    public function itPublishesObservedNamesWithVisibleRepairsAndPreservesValidJson(
        string $observed,
        string $published,
        int $repairs,
    ): void {
        $output = new BufferedOutput();
        $criterion = 'attribute "' . $observed . '"';
        (new LayerAssignmentJsonPresenter($output))->render(new LayerAssignment(
            matches: [new LayerAssignmentMatch('marked', [$criterion])],
            hasLayers: true,
            undecidedLayers: ['parent'],
            chainStopsAt: [$observed],
            contenders: ['parent', 'marked'],
            firstEstablished: 'marked',
            shadowVerdicts: [],
            declaredSpelling: $observed,
            policyDisabled: false,
            edgeEndOnly: false,
        ));
        $json = $output->fetch();
        $payload = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['meta']);
        self::assertIsString($payload['meta']['timestamp']);
        $expected = [
            'meta' => ProductIdentity::meta($payload['meta']['timestamp']),
            'fqn' => $published,
            'assigned' => ['layer' => 'marked', 'criteria' => ['attribute "' . $published . '"']],
            'contendingMatches' => [],
            'shadowed' => [],
            'shadowedBy' => null,
            'undecided' => ['parent'],
            'contenders' => ['parent', 'marked'],
            'chainStopsAt' => [$published],
            'hasLayers' => true,
            'policyDisabled' => false,
            'edgeEndOnly' => false,
        ];
        if ($repairs > 0) {
            $expected['invalidUtf8Replaced'] = $repairs;
        }

        self::assertSame($expected, $payload);
        self::assertSame(
            json_encode($expected, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
            $json,
        );
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function provideObservedNames(): iterable
    {
        yield 'ASCII' => ['App\\Subject', 'App\\Subject', 0];
        yield 'valid UTF-8' => ['App\\Café', 'App\\Café', 0];
        yield 'invalid bytes' => ['App\\' . \chr(128) . \chr(128), "App\\%80%80", 3];
    }
}
