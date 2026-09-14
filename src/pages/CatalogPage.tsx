import { useEffect, useMemo, useState } from 'react';
import { SlidersHorizontal, X } from 'lucide-react';
import type { SortKey } from '@/types';
import { useProducts } from '@/hooks/useProducts';
import { useUI } from '@/store/ui';
import ProductGrid from '@/components/ProductGrid';
import { filterCatalogProducts, getFilterOptions, getModelGroups, getTechnology } from './catalogModelFilters';
import SeoMetadata from '@/components/SeoMetadata';

type Props = { initialTechnology?: string; initialResolutionToken?: string; categorySlug?: string };
const toggleValue = (value: string, values: string[], setValues: (next: string[])=>void) => setValues(values.includes(value)?values.filter(item=>item!==value):[...values,value]);

export default function CatalogPage({ initialTechnology, initialResolutionToken, categorySlug }: Props) {
  const { searchQuery } = useUI();
  const { products, loading, error } = useProducts({ search: searchQuery || undefined });
  const [filtersOpen,setFiltersOpen]=useState(false);
  const [modelKey,setModelKey]=useState('');
  const [brands,setBrands]=useState<string[]>([]);
  const [sizes,setSizes]=useState<string[]>([]);
  const [resolutions,setResolutions]=useState<string[]>(() => {
    if (!initialResolutionToken) return [];
    const matches = getFilterOptions(products,p=>p.resolution).filter(value=>value.toLowerCase().includes(initialResolutionToken));
    return matches.length ? matches : [initialResolutionToken.toUpperCase()];
  });
  const [technologies,setTechnologies]=useState<string[]>(initialTechnology?[initialTechnology]:[]);
  const [minPrice,setMinPrice]=useState(''); const [maxPrice,setMaxPrice]=useState('');
  const [sort,setSort]=useState<SortKey>('default');

  const modelGroups=useMemo(()=>getModelGroups(products),[products]);
  const brandOptions=useMemo(()=>getFilterOptions(products,p=>p.brand??''),[products]);
  const sizeOptions=useMemo(()=>getFilterOptions(products,p=>p.screenSize),[products]);
  const resolutionOptions=useMemo(()=>getFilterOptions(products,p=>p.resolution),[products]);
  const technologyOptions=useMemo(()=>getFilterOptions(products,getTechnology),[products]);
  useEffect(()=>{ if(initialResolutionToken&&!resolutions.length){const match=resolutionOptions.find(value=>value.toLowerCase().includes(initialResolutionToken));if(match)setResolutions([match]);} },[initialResolutionToken,resolutionOptions,resolutions.length]);

  const filtered=useMemo(()=>filterCatalogProducts(products,{modelKey,brands,sizes,resolutions,technologies,minPrice,maxPrice,sort}),[products,modelKey,brands,sizes,resolutions,technologies,minPrice,maxPrice,sort]);
  const activeCount=(modelKey?1:0)+brands.length+sizes.length+resolutions.length+technologies.length+(minPrice?1:0)+(maxPrice?1:0);
  const resetFilters=()=>{setModelKey('');setBrands([]);setSizes([]);setResolutions([]);setTechnologies([]);setMinPrice('');setMaxPrice('');setSort('default');};

  const choices=(title:string,options:string[],selected:string[],setter:(next:string[])=>void)=><div><h3 className="mb-3 text-sm font-semibold text-white">{title}</h3><div className="flex flex-wrap gap-2">{options.map(value=><button key={value} type="button" onClick={()=>toggleValue(value,selected,setter)} className={`rounded-xl border px-3 py-2 text-sm ${selected.includes(value)?'border-white bg-white text-graphite-900':'border-white/10 bg-white/5 text-graphite-300'}`}>{value}</button>)}</div></div>;

  const categoryLabel = initialTechnology || (initialResolutionToken ? '8K' : '');
  const seoTitle = categoryLabel ? `Телевизоры ${categoryLabel} — каталог TELVORA` : 'Телевизоры — каталог TELVORA';
  const seoDescription = categoryLabel
    ? `Телевизоры ${categoryLabel} в каталоге TELVORA: актуальные модели, характеристики, цены, доставка и профессиональная установка.`
    : 'Каталог телевизоров TELVORA: актуальные модели, характеристики, цены, доставка и профессиональная установка.';
  const seoPath = categorySlug ? `/catalog/${categorySlug}` : '/catalog';

  return <><SeoMetadata title={seoTitle} description={seoDescription} path={seoPath} /><section className="min-h-screen bg-white pb-20 pt-24 dark:bg-graphite-900"><div className="mx-auto max-w-8xl px-4 sm:px-6 lg:px-8">
    <div className="mb-8 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between"><div><span className="text-sm font-semibold uppercase tracking-widest text-accent-500">Телевизоры</span><h1 className="mt-2 font-display text-4xl font-extrabold tracking-tight text-white sm:text-5xl">Каталог по модельным рядам</h1><p className="mt-3 max-w-2xl text-graphite-400">Выберите модельный ряд, затем уточните технологию экрана, разрешение, бренд, диагональ и цену.</p></div><div className="flex flex-wrap gap-3"><button type="button" onClick={()=>setFiltersOpen(true)} className="flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-white"><SlidersHorizontal className="h-4 w-4"/>Фильтры{activeCount>0&&<span className="flex h-5 w-5 items-center justify-center rounded-full bg-accent-500 text-xs font-bold">{activeCount}</span>}</button><select value={sort} onChange={e=>setSort(e.target.value as SortKey)} className="rounded-xl border border-white/10 bg-graphite-800 px-4 py-2.5 text-sm text-white"><option value="default">По умолчанию</option><option value="price-asc">Сначала дешевле</option><option value="price-desc">Сначала дороже</option><option value="rating">По рейтингу</option></select></div></div>

    <div className="mb-8 rounded-2xl border border-white/10 bg-white/[0.03] p-4"><div className="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-graphite-400">Модельные ряды</div><div className="flex flex-wrap gap-2"><button type="button" onClick={()=>setModelKey('')} className={`rounded-xl px-4 py-2.5 text-sm font-semibold ${!modelKey?'bg-white text-graphite-900':'border border-white/10 bg-white/5 text-white'}`}>Все модели <span className="opacity-60">{products.length}</span></button>{modelGroups.map(group=><button type="button" key={group.key} onClick={()=>setModelKey(group.key)} className={`rounded-xl px-4 py-2.5 text-sm font-semibold ${modelKey===group.key?'bg-accent-500 text-white':'border border-white/10 bg-white/5 text-graphite-200 hover:bg-white/10'}`}>{group.label} <span className="opacity-60">{group.count}</span></button>)}</div></div>

    <div className="mb-6 flex flex-wrap items-center justify-between gap-3"><span className="text-sm text-graphite-400">Найдено товаров: {filtered.length}</span>{activeCount>0&&<button type="button" onClick={resetFilters} className="text-sm font-semibold text-accent-500 hover:text-accent-400">Сбросить все фильтры</button>}</div>
    {!loading&&!error&&filtered.length===0?<div className="rounded-2xl border border-white/10 py-20 text-center"><p className="text-lg text-graphite-300">По выбранным условиям ничего не найдено.</p><button type="button" onClick={resetFilters} className="mt-4 rounded-xl bg-accent-500 px-5 py-2.5 font-semibold text-white">Сбросить фильтры</button></div>:<ProductGrid products={filtered} loading={loading} error={error}/>}

    {filtersOpen&&<div className="fixed inset-0 z-[70]"><button type="button" aria-label="Закрыть фильтры" className="absolute inset-0 h-full w-full bg-black/70 backdrop-blur-sm" onClick={()=>setFiltersOpen(false)}/><aside role="dialog" aria-modal="true" aria-label="Фильтры каталога" className="absolute bottom-0 right-0 top-0 w-full overflow-y-auto border-l border-white/10 bg-graphite-800 sm:w-[430px]"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-white/10 bg-graphite-800 p-5"><div><h2 className="text-xl font-bold text-white">Фильтры</h2><p className="mt-1 text-sm text-graphite-400">Найдено: {filtered.length}</p></div><button type="button" aria-label="Закрыть" onClick={()=>setFiltersOpen(false)} className="rounded-lg p-2 text-white hover:bg-white/10"><X className="h-5 w-5"/></button></div><div className="space-y-8 p-5">
      {choices('Бренд',brandOptions,brands,setBrands)}
      {choices('Диагональ',sizeOptions,sizes,setSizes)}
      {choices('Разрешение',resolutionOptions,resolutions,setResolutions)}
      {choices('Технология экрана',technologyOptions,technologies,setTechnologies)}
      <div><h3 className="mb-3 text-sm font-semibold text-white">Цена, ₽</h3><div className="grid grid-cols-2 gap-3"><input type="number" min="0" placeholder="От" value={minPrice} onChange={e=>setMinPrice(e.target.value)} className="rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-white"/><input type="number" min="0" placeholder="До" value={maxPrice} onChange={e=>setMaxPrice(e.target.value)} className="rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-white"/></div></div>
      <div className="flex gap-3 pb-6"><button type="button" onClick={resetFilters} className="flex-1 rounded-xl border border-white/10 px-4 py-3 text-white">Сбросить</button><button type="button" onClick={()=>setFiltersOpen(false)} className="flex-1 rounded-xl bg-accent-500 px-4 py-3 font-semibold text-white">Показать {filtered.length}</button></div>
    </div></aside></div>}
  </div></section></>;
}
