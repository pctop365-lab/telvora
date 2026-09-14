import { renderToString } from 'react-dom/server';
import { StaticRouter } from 'react-router-dom/server';
import { HelmetProvider, type HelmetServerState } from 'react-helmet-async';
import App from './App';
import { PrerenderContext, type PrerenderData } from './store/prerender';
export { normalizeProduct, getCategorySlugForProduct } from './services/productService';
export function render(path: string, data: PrerenderData) {
  const context = {} as { helmet: HelmetServerState };
  const body = renderToString(<HelmetProvider context={context}><PrerenderContext.Provider value={data}><StaticRouter location={path}><App /></StaticRouter></PrerenderContext.Provider></HelmetProvider>);
  const head = (['title', 'meta', 'link', 'script'] as const).map(key => context.helmet[key].toString()).join('');
  return { body, head };
}
