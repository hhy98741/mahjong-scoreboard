import { render } from 'preact';
import { useEffect } from 'preact/hooks';
import './styles/tokens.css';
import './styles/base.css';
import './styles/scoreboard.css';
import './styles/setup.css';
import './styles/history.css';
import './styles/profile.css';
import { api } from './api.ts';
import { route } from './router.ts';
import type { Route } from './router.ts';
import { AppNav } from './components/AppNav.tsx';
import { GameDetail } from './routes/GameDetail.tsx';
import { History } from './routes/History.tsx';
import { Home } from './routes/Home.tsx';
import { Login } from './routes/Login.tsx';
import { NewGame } from './routes/NewGame.tsx';
import { PlayerDetail } from './routes/PlayerDetail.tsx';
import { Profile } from './routes/Profile.tsx';
import { Scoreboard } from './routes/Scoreboard.tsx';
import { Setup } from './routes/Setup.tsx';
import { session } from './store.ts';

function App() {
  // Session bootstrap: the SPA calls /api/auth/me once on boot.
  useEffect(() => {
    api.me().then(
      (user) => {
        session.value = user;
      },
      () => {
        session.value = null;
      }
    );
  }, []);

  // A 401 anywhere (including this bootstrap call) clears session.value to
  // null; this effect is what turns that into an actual redirect.
  useEffect(() => {
    if (session.value === null && route.value.name !== 'login') {
      location.hash = '#/login';
    } else if (session.value != null && route.value.name === 'login') {
      location.hash = '#/';
    }
  }, [session.value, route.value]);

  if (session.value === undefined) {
    return <div class="centered-page">Loading…</div>;
  }

  const r = route.value;

  if (r.name === 'login') {
    return <Login />;
  }
  if (session.value === null) {
    // Redirect effect above is about to fire.
    return <div class="centered-page">Redirecting…</div>;
  }

  return (
    <>
      <AppNav />
      {renderRoute(r)}
    </>
  );
}

function renderRoute(r: Exclude<Route, { name: 'login' }>) {
  if (r.name === 'home') {
    return <Home />;
  }
  if (r.name === 'new') {
    return <NewGame />;
  }
  if (r.name === 'setup') {
    return <Setup />;
  }
  if (r.name === 'profile') {
    return <Profile />;
  }
  if (r.name === 'game') {
    return <Scoreboard id={r.id} />;
  }
  if (r.name === 'history') {
    return <History />;
  }
  if (r.name === 'historyGame') {
    return <GameDetail id={r.id} />;
  }
  if (r.name === 'historyPlayer') {
    return <PlayerDetail id={r.id} />;
  }

  return <div class="centered-page">Not built yet: {r.path}</div>;
}

render(<App />, document.getElementById('app')!);
