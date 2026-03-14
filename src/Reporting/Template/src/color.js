/**
 * Color scale for health score visualization.
 *
 * Blue (healthy, 100) → White/neutral (50) → Red (unhealthy, 0)
 * Color-blind accessible (blue-white-red vs problematic green-yellow-red).
 */

/** Red endpoint for unhealthy scores */
const COLOR_RED = [220, 60, 60];
/** Blue endpoint for healthy scores */
const COLOR_BLUE = [60, 100, 220];

/**
 * Creates a diverging color scale for health scores.
 *
 * @param {string} neutralColor - CSS color for the neutral midpoint (adapts to dark/light mode)
 * @returns {function} Score (0-100) → CSS color string
 */
export function createColorScale(neutralColor = '#ffffff') {
  const neutral = parseColor(neutralColor);

  return function scoreToColor(score) {
    if (score == null || isNaN(score)) return '#888888';

    const clamped = Math.max(0, Math.min(100, score));

    if (clamped <= 30) {
      // Red zone: 0-30, interpolate red → neutral
      const t = clamped / 30;
      return toRgbString(lerpRgb(COLOR_RED, neutral, t));
    } else if (clamped >= 60) {
      // Blue zone: 60-100, interpolate neutral → blue
      const t = (clamped - 60) / 40;
      return toRgbString(lerpRgb(neutral, COLOR_BLUE, t));
    } else {
      // Neutral zone: 30-60, interpolate red-side → neutral → blue-side
      const t = (clamped - 30) / 30;
      // Slight tint toward the closer endpoint
      if (t < 0.5) {
        return toRgbString(lerpRgb(
          lerpRgb(COLOR_RED, neutral, 0.85),
          neutral,
          t * 2,
        ));
      } else {
        return toRgbString(lerpRgb(
          neutral,
          lerpRgb(neutral, COLOR_BLUE, 0.15),
          (t - 0.5) * 2,
        ));
      }
    }
  };
}

/**
 * Gets the health color for a node.
 *
 * @param {object} node - Tree node with metrics
 * @param {string} metric - Metric key (e.g., 'health.overall')
 * @param {function} colorScale - Color scale function from createColorScale
 * @returns {string} CSS color string
 */
export function getHealthColor(node, metric, colorScale) {
  let score = node.metrics?.[metric];

  // Fallback: use clamp(mi.avg, 0, 100) if health.overall is missing
  if (score == null && metric === 'health.overall') {
    score = node.metrics?.['mi.avg'];
    if (score != null) {
      score = Math.max(0, Math.min(100, score));
    }
  }

  return colorScale(score);
}

/**
 * Interpolates between two RGB colors, returning an [r, g, b] array.
 *
 * @param {number[]} from - [r, g, b] start color
 * @param {number[]} to - [r, g, b] end color
 * @param {number} t - Interpolation factor (0-1)
 * @returns {number[]} [r, g, b] interpolated color
 */
function lerpRgb(from, to, t) {
  return [
    Math.round(from[0] + (to[0] - from[0]) * t),
    Math.round(from[1] + (to[1] - from[1]) * t),
    Math.round(from[2] + (to[2] - from[2]) * t),
  ];
}

/**
 * Converts an [r, g, b] array to a CSS rgb() string.
 *
 * @param {number[]} rgb - [r, g, b] color
 * @returns {string} CSS rgb() color string
 */
function toRgbString(rgb) {
  return `rgb(${rgb[0]}, ${rgb[1]}, ${rgb[2]})`;
}

/**
 * Interpolates between two RGB colors.
 *
 * @param {number[]} from - [r, g, b] start color
 * @param {number[]} to - [r, g, b] end color
 * @param {number} t - Interpolation factor (0-1)
 * @returns {string} CSS rgb() color string
 */
function interpolate(from, to, t) {
  return toRgbString(lerpRgb(from, to, t));
}

/**
 * Parses a hex color string to [r, g, b] array.
 *
 * @param {string} hex - CSS hex color (e.g., '#ffffff')
 * @returns {number[]} [r, g, b]
 */
function parseColor(hex) {
  if (hex.startsWith('rgb')) {
    const match = hex.match(/\d+/g);
    if (match && match.length >= 3) {
      return [parseInt(match[0]), parseInt(match[1]), parseInt(match[2])];
    }
  }
  const clean = hex.replace('#', '');
  return [
    parseInt(clean.substring(0, 2), 16),
    parseInt(clean.substring(2, 4), 16),
    parseInt(clean.substring(4, 6), 16),
  ];
}

// Export for testing
export { interpolate as _interpolate, parseColor as _parseColor };
