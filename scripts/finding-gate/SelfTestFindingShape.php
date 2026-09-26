<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The shape of a published finding: the equivalence tuple and the fingerprints composed from it.
 */
final class SelfTestFindingShape extends SelfTestGroup
{
    public function tuple(): void
    {
        $derived = EquivalenceTuple::derive($this->candidateRoot);
        $tracked = EquivalenceTuple::load($this->candidateRoot);
        $this->assert($derived->fields !== [], 'the tuple derivation reads fields out of the publishing code');
        $this->assert($tracked->equals($derived), 'the tracked tuple is what the publishing code publishes');
        $this->tupleProvenanceOnLoad();
    }

    /**
     * The `source` column named a deleted publisher for a whole step, and every
     * run stayed green, because nothing resolved it. Written as a probe on a
     * fabricated tree rather than on the tracked file, so the refusal is proved
     * without a rename having to happen first.
     */
    private function tupleProvenanceOnLoad(): void
    {
        $root = Fs::temporaryDirectory('self-test-tuple-');
        $write = static function (string $source) use ($root): void {
            Fs::write(
                $root . '/' . EquivalenceTuple::TRACKED_PATH,
                Tsv::render(EquivalenceTuple::COLUMNS, [['channel', $source]]),
            );
        };
        Fs::write($root . '/src/Publisher.php', "<?php\n\nprivate function formatFinding(): array\n{\n}\n");

        $write('src/Publisher.php::formatFinding');
        $this->assert(
            !self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a tuple whose source names a file and a method that exist loads',
        );

        $write('src/Gone.php::formatFinding');
        $this->assert(
            self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a tuple whose source names a file the tree no longer has is refused',
        );

        $write('src/Publisher.php::formatViolation');
        $this->assert(
            self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a tuple whose source names a method the publisher no longer declares is refused',
        );

        $write('src/Publisher.php');
        $this->assert(
            self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a source cell that is not "<file>::<method>" is refused rather than read as a caption',
        );

        Fs::removeRecursively($root);
    }

    public function fingerprints(): void
    {
        $this->same(
            ['channel:subject'],
            Fingerprints::expected([['channel' => 'channel', 'subject' => 'subject', 'occurrence' => null, 'edge' => null]]),
            'a plain finding fingerprints as channel:subject',
        );
        $this->same(
            ['channel:subject:occ'],
            Fingerprints::expected([['channel' => 'channel', 'subject' => 'subject', 'occurrence' => 'occ', 'edge' => null]]),
            'an occurrence key joins the fingerprint',
        );
        $this->same(
            ['channel:subject:extends:Target'],
            Fingerprints::expected([['channel' => 'channel', 'subject' => 'subject', 'edge' => ['type' => 'extends', 'target' => 'Target']]]),
            'a typed edge joins as type:target',
        );
        $this->same(
            ['channel:subject:untyped-edge:6:Target'],
            Fingerprints::expected([['channel' => 'channel', 'subject' => 'subject', 'edge' => ['target' => 'Target']]]),
            'an untyped edge carries its target length, exactly as Finding::getFingerprint() composes it',
        );
        $this->same(
            [md5('channel:subject')],
            Fingerprints::md5Of(['channel:subject']),
            'the GitLab fingerprint is the md5 of the same string',
        );

        $this->same(
            [md5('channel:subject') => 'channel:subject'],
            Fingerprints::preimagesByHash([['channel' => 'channel', 'subject' => 'subject']]),
            'each published hash is paired with the identity it hashes',
        );

        $this->fingerprintSubstitution();
        $this->collapsedIdentityNeedsNoDelta();

        $this->assert(
            array_diff(Fingerprints::INPUT_FIELDS, EquivalenceTuple::load($this->candidateRoot)->fields) === [],
            'every field the fingerprint is composed from is one the tracked tuple compares',
        );
    }

    /**
     * The substitution, and the two ways it is allowed to be incomplete.
     */
    private function fingerprintSubstitution(): void
    {
        $identity = 'rule#code:declaration:class:App\\Thing@src/Thing.php';
        $pairs = [md5($identity) => $identity];
        $document = \sprintf('[{"check_name":"code","fingerprint":"%s"}]', md5($identity));
        $substituted = Fingerprints::substitute($document, $pairs, 1);

        $this->assert($substituted->isComplete(), 'a published hash is replaced by the identity it hashes');
        $this->same(1, $substituted->replaced, 'the replacement is counted');
        $this->same(
            '[{"check_name":"code","fingerprint":"rule#code:declaration:class:App\\\\Thing@src\\/Thing.php"}]',
            $substituted->text,
            'the identity goes in JSON-escaped, so the artifact stays a JSON document',
        );

        // The analysis-failure issues GitLab also carries hash a path and a
        // failure kind. Nothing verified them, so nothing substitutes them, and
        // they must survive untouched rather than be swept along.
        $withAnalysis = \sprintf(
            '[{"check_name":"analysis.parse-error","fingerprint":"%s"},{"check_name":"code","fingerprint":"%s"}]',
            md5('some/path:parse-error'),
            md5($identity),
        );
        $result = Fingerprints::substitute($withAnalysis, $pairs, 1);
        $this->assert($result->isComplete(), 'a hash nothing verified does not make the substitution incomplete');
        $this->assert(
            str_contains($result->text, md5('some/path:parse-error')),
            'an unverified hash is left exactly as it is',
        );

        $missing = Fingerprints::substitute('[{"fingerprint":"deadbeef"}]', $pairs, 1);
        $this->assert(!$missing->isComplete(), 'a verified hash the surface does not carry is a shortfall');
        $this->same([md5($identity)], $missing->missing, 'the shortfall names the hash it could not find');

        $short = Fingerprints::substitute($document, $pairs, 2);
        $this->assert(
            !$short->isComplete(),
            'replacing fewer values than the surface published is a shortfall even when every pair was found',
        );
    }

    /**
     * The step's own reason for existing, on a synthetic pair.
     *
     * The collapse of `rule#code` into `code` moves every fingerprint of every
     * finding. Compared as published bytes, the GitLab surface differs — and a
     * declared delta of nothing but hashes is exactly the blob `delta-too-large`
     * exists to refuse. Substituted, the same surface carries the identity as a
     * name, the declared row translates it, and the surfaces agree.
     *
     * The third assertion is the guarantee that must not be lost: with no row
     * declaring the collapse, the substituted surfaces still differ. A
     * fingerprint that moved is only ever absorbed by a declaration, never by
     * the substitution.
     */
    private function collapsedIdentityNeedsNoDelta(): void
    {
        $old = 'cohesion.lcom#cohesion.lcom:declaration:class:App\\Thing@src/Thing.php';
        $new = 'cohesion.lcom:declaration:class:App\\Thing@src/Thing.php';
        $document = static fn(string $identity): string => \sprintf(
            '[{"check_name":"cohesion.lcom","fingerprint":"%s"}]',
            md5($identity),
        );
        $reference = $document($old);
        $candidate = $document($new);
        $maps = RenameMaps::fromPairs([[
            'old' => 'cohesion.lcom#cohesion.lcom',
            'new' => 'cohesion.lcom',
            'source' => RenameMaps::CHANNELS,
        ]]);

        $this->assert(
            $maps->forward($reference, 'format:json') !== $candidate,
            'before substituting, the collapse moves the published GitLab bytes and would need a declared delta',
        );

        $substitute = static fn(string $text, string $identity): string => Fingerprints::substitute(
            $text,
            [md5($identity) => $identity],
            1,
        )->text;

        $this->same(
            $substitute($candidate, $new),
            $maps->forward($substitute($reference, $old), 'format:gitlab'),
            'substituted and then translated, the collapsed identity needs no declared delta',
        );

        $this->assert(
            $substitute($candidate, $new) !== RenameMaps::fromPairs([])->forward($substitute($reference, $old), 'format:gitlab'),
            'with no row declaring the collapse, the substituted surfaces still differ',
        );
    }
}
