// Hand-rolled inline SVG line chart (docs-initial-build/04-frontend.md § Setup: "Charts
// and the diamond are inline SVG" — no charting library). Used for the
// game-detail score curve and the player-detail career chart
// (docs-initial-build/06-history-reports.md #2, #4). Every chart needs a table fallback
// beneath it per that spec — callers render one, this component only draws.

interface Series {
  id: number;
  name: string;
  color: string;
  points: number[]; // y-values, one per x position 0..n-1
}

interface ScoreChartProps {
  series: Series[];
  verticalLines?: number[]; // x-positions (indices) to mark, e.g. round boundaries
  height?: number;
  // Reference line + forced-into-domain value: 0 for a cumulative net-points
  // chart (PlayerDetail), starting_points for a single game's absolute score
  // curve (GameDetail) — see D30.
  baseline?: number;
}

export function ScoreChart({ series, verticalLines = [], height = 240, baseline = 0 }: ScoreChartProps) {
  const width = 800;
  const padding = { top: 12, right: 12, bottom: 12, left: 44 };
  const innerWidth = width - padding.left - padding.right;
  const innerHeight = height - padding.top - padding.bottom;

  const maxLen = Math.max(1, ...series.map((s) => s.points.length));
  const allValues = series.flatMap((s) => s.points);
  const dataMax = allValues.length > 0 ? Math.max(...allValues, baseline) : baseline;
  const dataMin = allValues.length > 0 ? Math.min(...allValues, baseline) : baseline;
  const range = dataMax - dataMin || 1;

  const xAt = (i: number): number => padding.left + (maxLen <= 1 ? 0 : (i / (maxLen - 1)) * innerWidth);
  const yAt = (v: number): number => padding.top + innerHeight - ((v - dataMin) / range) * innerHeight;

  const baselineY = yAt(baseline);

  return (
    <svg viewBox={`0 0 ${width} ${height}`} class="score-chart" role="img" aria-label="Cumulative points chart">
      <line x1={padding.left} y1={baselineY} x2={width - padding.right} y2={baselineY} class="score-chart-zero" />
      {verticalLines.map((x) => (
        <line key={x} x1={xAt(x)} y1={padding.top} x2={xAt(x)} y2={height - padding.bottom} class="score-chart-boundary" />
      ))}
      <text x={4} y={yAt(dataMax) + 4} class="score-chart-axis-label">
        {Math.round(dataMax)}
      </text>
      <text x={4} y={yAt(dataMin) + 4} class="score-chart-axis-label">
        {Math.round(dataMin)}
      </text>
      {series.map((s) => {
        const d = s.points.map((v, i) => `${i === 0 ? 'M' : 'L'} ${xAt(i)} ${yAt(v)}`).join(' ');
        return <path key={s.id} d={d} fill="none" stroke={s.color} stroke-width="2.5" />;
      })}
    </svg>
  );
}
