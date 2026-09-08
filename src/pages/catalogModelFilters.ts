import type { Product, SortKey } from '@/types';

export type CatalogFilters = { modelKey: string; brands: string[]; sizes: string[]; resolutions: string[]; technologies: string[]; minPrice: string; maxPrice: string; sort: SortKey };
export const normalizeCatalogValue = (value: unknown) => String(value ?? '').trim().replace(/\s+/g, ' ');
const comparable = (value: unknown) => normalizeCatalogValue(value).toLocaleLowerCase('ru-RU');

export function getModelGroup(product: Product) {
  const brand = normalizeCatalogValue(product.brand), series = normalizeCatalogValue(product.series);
  return brand && series ? { key: `${comparable(brand)}::${comparable(series)}`, label: `${brand} ${series}`, brand, series } : null;
}
export function getModelGroups(products: Product[]) {
  const groups = new Map<string, NonNullable<ReturnType<typeof getModelGroup>> & { count: number }>();
  for (const product of products) { const group=getModelGroup(product); if(group){ const current=groups.get(group.key); groups.set(group.key,current?{...current,count:current.count+1}:{...group,count:1}); } }
  return [...groups.values()].sort((a,b)=>a.label.localeCompare(b.label,'ru'));
}
export const getCatalogPrice = (product: Product) => { const prices=(product.variants??[]).filter(v=>v.isActive!==false&&Number(v.price)>0).map(v=>Number(v.price)); return prices.length?Math.min(...prices):Number(product.price||0); };
export const getTechnology = (product: Product) => { const value=normalizeCatalogValue(product.category); return ['OLED','QLED','LED','Mini LED','QNED'].find(item=>comparable(item)===comparable(value))??''; };
export const getFilterOptions = (products: Product[], selector: (product: Product)=>string) => [...new Set(products.map(selector).map(normalizeCatalogValue).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'ru',{numeric:true}));
export function filterCatalogProducts(products: Product[], filters: CatalogFilters) {
  const minimum=filters.minPrice===''?null:Number(filters.minPrice), maximum=filters.maxPrice===''?null:Number(filters.maxPrice);
  const filtered=products.filter(product=>{ const group=getModelGroup(product),price=getCatalogPrice(product); return (!filters.modelKey||group?.key===filters.modelKey)&&(!filters.brands.length||filters.brands.some(v=>comparable(v)===comparable(product.brand)))&&(!filters.sizes.length||filters.sizes.some(v=>comparable(v)===comparable(product.screenSize)))&&(!filters.resolutions.length||filters.resolutions.some(v=>comparable(v)===comparable(product.resolution)))&&(!filters.technologies.length||filters.technologies.some(v=>comparable(v)===comparable(getTechnology(product))))&&(minimum===null||(Number.isFinite(minimum)&&price>=minimum))&&(maximum===null||(Number.isFinite(maximum)&&price<=maximum)); });
  if(filters.sort==='price-asc')return filtered.sort((a,b)=>getCatalogPrice(a)-getCatalogPrice(b)); if(filters.sort==='price-desc')return filtered.sort((a,b)=>getCatalogPrice(b)-getCatalogPrice(a)); if(filters.sort==='rating')return filtered.sort((a,b)=>b.rating-a.rating); return filtered;
}
