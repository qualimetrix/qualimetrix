<?php

declare(strict_types=1);

namespace GoldenMetrics\App\Service\Auth;

/**
 * Token validator — base class for cohesion and inheritance testing.
 *
 * Has properties accessed by methods (for TCC/LCC).
 * SessionManager extends this (DIT testing).
 *
 * Method-level CCN (includes __construct):
 * - __construct(): CCN=1 (no branches)
 * - validate():    CCN=3 (base 1 + 1 if + 1 if)
 * - isExpired():   CCN=2 (base 1 + 1 if)
 *
 * Class-level:
 * - ccn.sum = 6 (1+3+2), ccn.max = 3, ccn.avg = 2.0 (6/3 methods)
 * - wmc = 6 (= ccn.sum)
 * - methodCount = 2 (excludes __construct), methodCountTotal = 3
 * - propertyCount = 2 ($secretKey, $tokenLifetime)
 * - DIT = 0 (no parent), tcc = 0, lcc = 0, lcom = 1
 */
class TokenValidator
{
    protected string $secretKey;

    protected int $tokenLifetime;

    public function __construct(string $secretKey, int $tokenLifetime = 3600)
    {
        $this->secretKey = $secretKey;
        $this->tokenLifetime = $tokenLifetime;
    }

    /**
     * CCN = 3 (base 1 + 1 if + 1 if).
     *
     * Accesses: $secretKey
     */
    public function validate(string $token): bool
    {
        if (\strlen($token) < 10) {
            return false;
        }

        $decoded = base64_decode($token, true);

        if ($decoded === false) {
            return false;
        }

        return str_contains($decoded, $this->secretKey);
    }

    /**
     * CCN = 2 (base 1 + 1 if).
     *
     * Accesses: $tokenLifetime
     */
    public function isExpired(int $issuedAt): bool
    {
        if (time() - $issuedAt > $this->tokenLifetime) {
            return true;
        }

        return false;
    }
}
