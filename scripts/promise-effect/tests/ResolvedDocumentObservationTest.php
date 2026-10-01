<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\InProcess;
use Qualimetrix\PromiseEffect\Observation;
use Symfony\Component\Filesystem\Filesystem;

final class ResolvedDocumentObservationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/scripts/promise-effect/InProcess.php';
    }

    #[Test]
    public function itObservesResolvedValuesAndAllRuleRoots(): void
    {
        $scratch = sys_get_temp_dir() . '/qmx-resolved-observation-' . bin2hex(random_bytes(6));

        try {
            $observations = (new InProcess($scratch))->take([
                'format' => 'json',
                'cache' => ['enabled' => false],
                'rules' => ['complexity.ccn' => ['callable' => ['warning' => 123]]],
                'only_rules' => ['complexity.ccn'],
                'disabled_rules' => ['security'],
            ], [], [], '');
            $merged = $observations['merged'];
            self::assertSame(Observation::ACCEPTED, $merged->outcome, $merged->text);
            $values = json_decode($merged->text, true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame('json', $values['format']);
            self::assertFalse($values['cache']['enabled']);
            self::assertSame(['complexity.ccn' => ['callable' => ['warning' => 123]]], $values['rules']);
            self::assertSame(['complexity.ccn'], $values['only_rules']);
            self::assertSame(['security'], $values['disabled_rules']);
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }
}
