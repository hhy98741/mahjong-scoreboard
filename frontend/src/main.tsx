import { render } from 'preact';
import { signal } from '@preact/signals';

const health = signal<string>('checking...');

fetch('/api/health')
  .then((res) => res.json())
  .then((body) => {
    health.value = body.ok ? `${body.data.status} (php ${body.data.php})` : 'error';
  })
  .catch(() => {
    health.value = 'unreachable';
  });

function App() {
  return (
    <div>
      <p>Hello</p>
      <p>API health: {health}</p>
    </div>
  );
}

render(<App />, document.getElementById('app')!);
