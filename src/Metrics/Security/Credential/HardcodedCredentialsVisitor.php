<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security\Credential;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;
use Qualimetrix\Metrics\Security\SensitiveNameMatcher;

/**
 * Traverses nodes and delegates credential-literal semantics.
 *
 * @qmx-ignore design.data-class -- Traversal adapter intentionally delegates credential policy and retains only lifecycle state.
 */
final class HardcodedCredentialsVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var list<CredentialLocation> */
    private array $locations = [];

    private readonly CredentialLiterals $credentialLiterals;

    public function __construct(SensitiveNameMatcher $matcher, int $minValueLength = 4)
    {
        $this->credentialLiterals = new CredentialLiterals($matcher, $minValueLength);
    }

    public function reset(): void
    {
        $this->locations = [];
        $this->resetVisitorMethodContext();
    }

    public function enterNode(Node $node): ?int
    {
        $this->enterVisitorMethodContext($node);
        array_push($this->locations, ...$this->credentialLiterals->locations($node, $this->currentFileEntrySubjectId()));

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $this->leaveVisitorMethodContext($node);

        return null;
    }

    /** @return list<CredentialLocation> */
    public function getLocations(): array
    {
        return $this->locations;
    }

    /** @return array<string, int|string> */
    public function getSubjectComponents(CredentialLocation $location): array
    {
        return $this->fileEntrySubjectComponents($location->subjectId);
    }
}
