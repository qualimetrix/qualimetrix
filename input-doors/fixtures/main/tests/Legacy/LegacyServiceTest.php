<?php

declare(strict_types=1);

namespace Fixture\Tests\Legacy;

/**
 * Test-side code the stand's `check src` run never analyses.
 *
 * It is here so a probe can spell a value that names a real place outside the
 * run: `suppress_paths: [tests/Legacy]` and `--exclude=tests` are correct
 * configuration this run cannot judge, and the oracle needs a legitimately
 * empty hit to tell a cure that reports a miss from one that also reports
 * these.
 */
final class LegacyServiceTest {}
