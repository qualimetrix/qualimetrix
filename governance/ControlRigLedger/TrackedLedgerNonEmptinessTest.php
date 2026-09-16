<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ControlRigLedger;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\Ledger;

/**
 * The guard {@see \Qualimetrix\Tests\Unit\PromiseEffect\LedgerVocabularyTest}
 * builds is worth nothing if it only passes on a fixture: the set it declares
 * has to be the one the repository's own tracked ledger actually uses.
 *
 * `scripts/promise-effect.php` runs on include and exits, so `Ledger` is
 * required directly, the way `FloorTest` reaches its own subject.
 */
final class TrackedLedgerNonEmptinessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 2) . '/scripts/promise-effect/Ledger.php';
    }

    #[Test]
    public function itLoadsTheRepositorysOwnLedger(): void
    {
        $ledger = Ledger::load(\dirname(__DIR__, 2));

        self::assertNotSame([], $ledger->compositions, 'a ledger carrying no composition row would pass the promise guard by carrying nothing');
        self::assertNotSame([], $ledger->pairs, 'the same, for the coexistence guard');
    }
}
