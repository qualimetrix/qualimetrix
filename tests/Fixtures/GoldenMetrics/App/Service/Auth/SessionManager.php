<?php

declare(strict_types=1);

namespace GoldenMetrics\App\Service\Auth;

/**
 * Session manager — extends TokenValidator for DIT testing.
 *
 * Method-level CCN:
 * - startSession():   CCN=2 (base 1 + 1 if)
 * - destroySession(): CCN=3 (base 1 + 1 if + 1 if)
 *
 * Class-level:
 * - ccn.sum = 5, ccn.max = 3, ccn.avg = 2.5
 * - methodCount = 2
 * - propertyCount = 1 ($sessions)
 * - DIT = 1 (extends TokenValidator)
 */
class SessionManager extends TokenValidator
{
    /** @var array<string, array<string, mixed>> */
    private array $sessions = [];

    /**
     * CCN = 2 (base 1 + 1 if).
     *
     * Accesses: $sessions, $secretKey (inherited)
     */
    public function startSession(string $token): ?string
    {
        if (!$this->validate($token)) {
            return null;
        }

        $sessionId = md5($token . $this->secretKey);
        $this->sessions[$sessionId] = ['token' => $token, 'started' => time()];

        return $sessionId;
    }

    /**
     * CCN = 3 (base 1 + 1 if + 1 if).
     *
     * Accesses: $sessions
     */
    public function destroySession(string $sessionId): bool
    {
        if (!isset($this->sessions[$sessionId])) {
            return false;
        }

        if ($this->sessions[$sessionId]['started'] < time() - 60) {
            unset($this->sessions[$sessionId]);

            return true;
        }

        return false;
    }
}
