// Bar chart, inline SVG (docs/04-frontend.md § Setup — no charting library).
// docs/06-history-reports.md #4: "A player whose wins cluster at the
// minimum faan is playing a completely different game from one with a fat
// tail." Table fallback is the caller's job.

import type { FaanHistogramBucket } from '../types.ts';

interface FaanHistogramProps {
  buckets: FaanHistogramBucket[];
  color: string;
}

export function FaanHistogram({ buckets, color }: FaanHistogramProps) {
  if (buckets.length === 0) {
    return <p class="text-dim">No wins yet in range.</p>;
  }

  const maxCount = Math.max(...buckets.map((b) => b.count));
  const width = 800;
  const height = 160;
  const barGap = 4;
  const barWidth = buckets.length > 0 ? (width - barGap * (buckets.length - 1)) / buckets.length : width;

  return (
    <svg viewBox={`0 0 ${width} ${height + 20}`} class="faan-histogram" role="img" aria-label="Faan histogram">
      {buckets.map((b, i) => {
        const barHeight = maxCount > 0 ? (b.count / maxCount) * height : 0;
        const x = i * (barWidth + barGap);
        return (
          <g key={b.faan}>
            <rect x={x} y={height - barHeight} width={barWidth} height={barHeight} fill={color} rx="2" />
            <text x={x + barWidth / 2} y={height + 16} text-anchor="middle" class="faan-histogram-label">
              {b.faan}
            </text>
          </g>
        );
      })}
    </svg>
  );
}
