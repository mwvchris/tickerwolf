/**
 * --------------------------------------------------------------------------
 * _core/transform.js
 * --------------------------------------------------------------------------
 * Tiny utilities for down-sampling & slicing series to keep large timeframes
 * fast without losing overall shape. All functions are pure & easily testable.
 * --------------------------------------------------------------------------
 */

/**
 * Down-sample a series of {x:'YYYY-MM-DD', y:number} by keeping points
 * at least `stepDays` apart. Always keeps first & last.
 * @param {Array<{x:string, y:number|null}>} series
 * @param {number} stepDays
 */
export function downsampleEveryNDays(series, stepDays) {
  if (!Array.isArray(series) || series.length <= 2 || stepDays <= 1) return series;

  const out = [];
  let lastKept = null;

  series.forEach((pt, idx) => {
    const d = new Date(pt.x + "T00:00:00");
    if (idx === 0) {
      out.push(pt);
      lastKept = d;
      return;
    }
    const diffDays = Math.floor((d - lastKept) / 86400000);
    if (diffDays >= stepDays) {
      out.push(pt);
      lastKept = d;
    }
  });

  // ensure last is included
  if (out[out.length - 1]?.x !== series[series.length - 1]?.x) {
    out.push(series[series.length - 1]);
  }
  return out;
}

/**
 * Choose a “nice” maximum number of x-axis labels for readability.
 * We target between 4 and 6 labels depending on range and density.
 * @param {number} pointCount
 */
export function idealLabelCount(pointCount) {
  if (pointCount <= 12) return Math.min(pointCount, 4); // short ranges
  if (pointCount <= 60) return 5;                       // 1M–6M
  return 6;                                             // 1Y+
}