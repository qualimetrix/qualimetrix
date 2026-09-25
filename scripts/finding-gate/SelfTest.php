<?php

declare(strict_types=1);

namespace QmxFindingGate;

use ArrayObject;

/**
 * The gate's own mechanics, checked without running the corpus.
 *
 * The map path is the reason this exists. Empty maps exercise only the identity
 * case, so mapping must be tested before a declaration relies on it. The same
 * applies to normalization: a kind with no tracked row today is still the kind
 * the deriver will emit tomorrow.
 *
 * Each subject's cases live in its own group; this class only fixes the order
 * they run in, which is the order their failures are printed in.
 */
final class SelfTest
{
    public function __construct(private readonly string $candidateRoot) {}

    /** @return list<string> */
    public function run(): array
    {
        /** @var ArrayObject<int, string> $failures */
        $failures = new ArrayObject();

        $maps = new SelfTestMaps($this->candidateRoot, $failures);
        $metricKeys = new SelfTestMetricKeys($this->candidateRoot, $failures);
        $coverage = new SelfTestCoverage($this->candidateRoot, $failures);
        $declaredDelta = new SelfTestDeclaredDelta($this->candidateRoot, $failures);
        $normalization = new SelfTestNormalization($this->candidateRoot, $failures);
        $findingShape = new SelfTestFindingShape($this->candidateRoot, $failures);
        $surfaces = new SelfTestSurfaces($this->candidateRoot, $failures);
        $verdict = new SelfTestVerdict($this->candidateRoot, $failures);
        $resources = new SelfTestResources($this->candidateRoot, $failures);
        $registries = new SelfTestRegistries($this->candidateRoot, $failures);

        $maps->maps();
        $maps->documentKeyInputForm();
        $maps->reportValues();
        $metricKeys->metricKeys();
        $coverage->channelRowShapes();
        $coverage->claims();
        $coverage->coverage();
        $coverage->levelVocabulary();
        $maps->ambiguities();
        $maps->producerMoves();
        $resources->writesNeverFollowHardlinks();
        $declaredDelta->declaredDelta();
        $declaredDelta->declaredFieldMoves();
        $normalization->normalization();
        $normalization->deriver();
        $findingShape->tuple();
        $findingShape->fingerprints();
        $surfaces->publishedOrder();
        $verdict->verdicts();
        $surfaces->surfaces();
        $resources->removal();
        $resources->processDeadline();
        $resources->processGroupTermination();
        $resources->referenceReleasedWhenCreationFails();
        $resources->lockedRegistrationIsStillReleased();
        $resources->interruptedRunReleasesEverything();
        $resources->releasedWhenKilledDuringCheckout();
        $registries->loaderNamesEveryClass();
        $registries->witnessedFailureClasses();

        // Last, and this is not cosmetic: it releases the process-wide registry
        // and suppresses raising, so any case that ran after it would be judged
        // against a registry somebody else had emptied.
        $resources->scratchRegistry();

        return array_values($failures->getArrayCopy());
    }
}
