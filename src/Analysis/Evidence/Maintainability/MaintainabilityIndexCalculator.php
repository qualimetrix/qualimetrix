<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Maintainability;

/**
 * Calculates Maintainability Index (MI) from component metrics.
 *
 * MI is a composite metric that estimates how maintainable code is.
 *
 * Uses the Visual Studio variant of the Oman-Hagemeister index, without
 * the comment-density term and normalized from the raw 0-171 scale to 0-100.
 *
 * Raw three-term formula:
 * MI = 171 - 5.2×ln(V) - 0.23×CCN - 16.2×ln(LOC)
 *
 * Where:
 * - V = Halstead Volume
 * - CCN = Cyclomatic Complexity
 * - LOC = semantic executable/control-flow statement count, not physical lines
 *
 * The result is normalized to 0-100 scale where:
 * - MI > 50: Good maintainability
 * - MI 40-50: Moderate complexity, some concern
 * - MI < 40: Low maintainability, needs attention
 *
 * Note: Original Oman-Hagemeister thresholds (65/85) are on the raw 0-171 scale.
 * On the normalized 0-100 scale, Visual Studio and Radon use thresholds of 20/10.
 *
 * For the 0-100 normalized version:
 * MI_normalized = max(0, (171 - 5.2×ln(V) - 0.23×CCN - 16.2×ln(LOC)) × 100 / 171)
 */
final class MaintainabilityIndexCalculator
{
    /**
     * Calculates raw Maintainability Index.
     *
     * @param float $halsteadVolume Halstead Volume (V)
     * @param int|float $cyclomaticComplexity Cyclomatic Complexity (CCN)
     * @param int|float $linesOfCode Executable-statement count (the LOC term of the formula), not physical lines
     *
     * @return float Raw MI value (can be negative for very complex code)
     */
    public function calculateRaw(float $halsteadVolume, int|float $cyclomaticComplexity, int|float $linesOfCode): float
    {
        // Handle edge cases
        if ($halsteadVolume <= 0 || $linesOfCode <= 0) {
            // Empty or trivial code gets perfect score
            return 171.0;
        }

        $mi = 171
            - 5.2 * log($halsteadVolume)
            - 0.23 * $cyclomaticComplexity
            - 16.2 * log($linesOfCode);

        return $mi;
    }

    /**
     * Calculates normalized Maintainability Index (0-100 scale).
     *
     * @param float $halsteadVolume Halstead Volume (V)
     * @param int|float $cyclomaticComplexity Cyclomatic Complexity (CCN)
     * @param int|float $linesOfCode Executable-statement count (the LOC term of the formula), not physical lines
     *
     * @return float Normalized MI value (0-100)
     */
    public function calculate(float $halsteadVolume, int|float $cyclomaticComplexity, int|float $linesOfCode): float
    {
        $raw = $this->calculateRaw($halsteadVolume, $cyclomaticComplexity, $linesOfCode);

        // Normalize to 0-100 scale
        $normalized = ($raw * 100) / 171;

        // Clamp to 0-100
        return max(0.0, min(100.0, $normalized));
    }
}
