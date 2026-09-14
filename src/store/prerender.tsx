import { createContext, useContext } from 'react';
import type { Product, ServiceCatalogItem } from '@/types';
export type PrerenderData = { path: string; products: Product[]; services: ServiceCatalogItem[]; theme: 'light' | 'dark' };
export const PrerenderContext = createContext<PrerenderData | undefined>(undefined);
export const usePrerender = () => useContext(PrerenderContext);
