import { StrictMode, useEffect } from 'react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { HelmetProvider } from 'react-helmet-async';
import { PrerenderContext, type PrerenderData } from './store/prerender';
import App from './App.tsx';
import './index.css';
const root = document.getElementById('root')!;
const serialized = document.getElementById('telvora-prerender');
const snapshot: PrerenderData | undefined = serialized ? JSON.parse(serialized.textContent!) : undefined;
if (snapshot) snapshot.theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
function Ready() {
  useEffect(() => { document.documentElement.dataset.reactReady = 'true'; }, []);
  return null;
}
const app = <StrictMode><HelmetProvider><PrerenderContext.Provider value={snapshot}><BrowserRouter><App /><Ready /></BrowserRouter></PrerenderContext.Provider></HelmetProvider></StrictMode>;
if (snapshot) hydrateRoot(root, app);
else createRoot(root).render(app);
