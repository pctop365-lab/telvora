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
  const [resolutions,setResolutions]=useState<string[]>([]);
  const [resolutionInitialized,setResolutionInitialized]=useState(!initialResolutionToken);
  const [technologies,setTechnologies]=useState<string[]>(initialTechnology?[initialTechnology]:[]);
  const [minPrice,setMinPrice]=useState(''); const [maxPrice,setMaxPrice]=useState('');
  const [sort,setSort]=useState<SortKey>('default');

  const modelGroups=useMemo(()=>getModelGroups(products),[products]);
  const brandOptions=useMemo(()=>getFilterOptions(products,p=>p.brand??''),[products]);
  const sizeOptions=useMemo(()=>getFilterOptions(products,p=>p.screenSize),[products]);
  const resolutionOptions=useMemo(()=>getFilterOptions(products,p=>p.resolution),[products]);
  const technologyOptions=useMemo(()=>getFilterOptions(products,getTechnology),[products]);
  useEffect(()=>{
    if (!initialResolutionToken || resolutionInitialized || !resolutionOptions.length) return;
    const match = resolutionOptions.find(value=>value.toLowerCase().includes(initialResolutionToken));
    if (match) setResolutions([match]);
    setResolutionInitialized(true);
  },[initialResolutionToken,resolutionInitialized,resolutionOptions]);

  const filtered=useMemo(()=>filterCatalogProducts(products,{modelKey,brands,sizes,resolutions,technologies,minPrice,maxPrice,sort}),[products,modelKey,brands,sizes,resolutions,technologies,minPrice,maxPrice,sort]);
  const activeCount=(modelKey?1:0)+brands.length+sizes.length+resolutions.length+technologies.length+(minPrice?1:0)+(maxPrice?1:0);
  const resetFilters=()=>{setModelKey('');setBrands([]);setSizes([]);setResolutions([]);setTechnologies([]);setMinPrice('');setMaxPrice('');setSort('default');};

  const choices=(title:string,options:string[],selected:string[],setter:(next:string[])=>void)=><div><h3 className="mb-3 text-sm font-semibold text-graphite-900 dark:text-white">{title}</h3><div className="flex flex-wrap gap-2">{options.map(value=><button key={value} type="button" onClick={()=>toggleValue(value,selected,setter)} className={`rounded-xl border px-3 py-2 text-sm transition-colors ${selected.includes(value)?'border-accent-500 bg-accent-500 text-white':'border-graphite-200 bg-graphite-50 text-graphite-700 hover:border-accent-500 hover:text-accent-700 dark:border-white/10 dark:bg-white/5 dark:text-graphite-200 dark:hover:bg-white/10 dark:hover:text-white'}`}>{value}</button>)}</div></div>;

  const categoryLabel = initialTechnology || (initialResolutionToken ? '8K' : '');
  const seoTitle = categoryLabel ? `Телевизоры ${categoryLabel} — каталог TELVORA` : 'Телевизоры — каталог TELVORA';
  const seoDescription = categoryLabel
    ? `Телевизоры ${categoryLabel} в каталоге TELVORA: актуальные модели, характеристики, цены, доставка и профессиональная установка.`
    : 'Каталог телевизоров TELVORA: актуальные модели, характеристики, цены, доставка и профессиональная установка.';
  const seoPath = categorySlug ? `/catalog/${categorySlug}` : '/catalog';

  return <><SeoMetadata title={seoTitle} description={seoDescription} path={seoPath} /><section className="min-h-screen bg-graphite-50 pb-20 pt-24 dark:bg-graphite-900"><div className="mx-auto max-w-8xl px-4 sm:px-6 lg:px-8">
    <div className="mb-8 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between"><div><span className="text-sm font-semibold uppercase tracking-widest text-accent-600 dark:text-accent-400">Телевизоры</span><h1 className="mt-2 font-display text-4xl font-extrabold tracking-tight text-graphite-950 dark:text-white sm:text-5xl">Каталог по модельным рядам</h1><p className="mt-3 max-w-2xl text-graphite-600 dark:text-graphite-300">Выберите модельный ряд, затем уточните технологию экрана, разрешение, бренд, диагональ и цену.</p></div><div className="flex flex-wrap gap-3"><button type="button" onClick={()=>setFiltersOpen(true)} className="flex items-center gap-2 rounded-xl border border-graphite-200 bg-white px-4 py-2.5 text-sm font-medium text-graphite-800 shadow-sm transition-colors hover:border-accent-500 hover:text-accent-700 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10"><SlidersHorizontal className="h-4 w-4"/>Фильтры{activeCount>0&&<span className="flex h-5 w-5 items-center justify-center rounded-full bg-accent-500 text-xs font-bold text-white">{activeCount}</span>}</button><select value={sort} onChange={e=>setSort(e.target.value as SortKey)} className="rounded-xl border border-graphite-200 bg-white px-4 py-2.5 text-sm font-medium text-graphite-800 shadow-sm outline-none transition-colors focus:border-accent-500 dark:border-white/10 dark:bg-graphite-800 dark:text-white"><option value="default">По умолчанию</option><option value="price-asc">Сначала дешевле</option><option value="price-desc">Сначала дороже</option><option value="rating">По рейтингу</option></select></div></div>

    {categorySlug && <div className="mb-8 rounded-2xl border border-graphite-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><div className="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-graphite-500 dark:text-graphite-300">Модельные ряды</div><div className="flex flex-wrap gap-2"><button type="button" onClick={()=>setModelKey('')} className={`rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors ${!modelKey?'bg-accent-500 text-white':'border border-graphite-200 bg-graphite-50 text-graphite-700 hover:border-accent-500 hover:text-accent-700 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10'}`}>Все модели <span className="opacity-60">{products.length}</span></button>{modelGroups.map(group=><button type="button" key={group.key} onClick={()=>setModelKey(group.key)} className={`rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors ${modelKey===group.key?'bg-accent-500 text-white':'border border-graphite-200 bg-graphite-50 text-graphite-700 hover:border-accent-500 hover:text-accent-700 dark:border-white/10 dark:bg-white/5 dark:text-graphite-200 dark:hover:bg-white/10'}`}>{group.label} <span className="opacity-60">{group.count}</span></button>)}</div></div>}

    <div className="mb-6 flex flex-wrap items-center justify-between gap-3"><span className="text-sm text-graphite-600 dark:text-graphite-300">Найдено товаров: {filtered.length}</span>{activeCount>0&&<button type="button" onClick={resetFilters} className="text-sm font-semibold text-accent-700 hover:text-accent-600 dark:text-accent-400 dark:hover:text-accent-300">Сбросить все фильтры</button>}</div>
    {!loading&&!error&&filtered.length===0?<div className="rounded-2xl border border-graphite-200 bg-white py-20 text-center dark:border-white/10 dark:bg-white/[0.03]"><p className="text-lg text-graphite-600 dark:text-graphite-300">По выбранным условиям ничего не найдено.</p><button type="button" onClick={resetFilters} className="mt-4 rounded-xl bg-accent-500 px-5 py-2.5 font-semibold text-white">Сбросить фильтры</button></div>:<ProductGrid products={filtered} loading={loading} error={error}/>}

    {filtersOpen&&<div className="fixed inset-0 z-[70]"><button type="button" aria-label="Закрыть фильтры" className="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-sm dark:bg-black/70" onClick={()=>setFiltersOpen(false)}/><aside role="dialog" aria-modal="true" aria-label="Фильтры каталога" className="absolute bottom-0 right-0 top-0 w-full overflow-y-auto border-l border-graphite-200 bg-white text-graphite-900 shadow-2xl dark:border-white/10 dark:bg-graphite-800 dark:text-white sm:w-[430px]"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-graphite-200 bg-white p-5 dark:border-white/10 dark:bg-graphite-800"><div><h2 className="text-xl font-bold text-graphite-950 dark:text-white">Фильтры</h2><p className="mt-1 text-sm text-graphite-600 dark:text-graphite-300">Найдено: {filtered.length}</p></div><button type="button" aria-label="Закрыть" onClick={()=>setFiltersOpen(false)} className="rounded-lg p-2 text-graphite-600 transition-colors hover:bg-graphite-100 hover:text-graphite-950 dark:text-graphite-200 dark:hover:bg-white/10 dark:hover:text-white"><X className="h-5 w-5"/></button></div><div className="space-y-8 p-5">
      {choices('Бренд',brandOptions,brands,setBrands)}
      {choices('Диагональ',sizeOptions,sizes,setSizes)}
      {choices('Разрешение',resolutionOptions,resolutions,setResolutions)}
      {choices('Технология экрана',technologyOptions,technologies,setTechnologies)}
      <div><h3 className="mb-3 text-sm font-semibold text-graphite-900 dark:text-white">Цена, ₽</h3><div className="grid grid-cols-2 gap-3"><input type="number" min="0" placeholder="От" value={minPrice} onChange={e=>setMinPrice(e.target.value)} className="w-full rounded-xl border border-graphite-200 bg-white px-3 py-2.5 text-graphite-900 outline-none placeholder:text-graphite-400 focus:border-accent-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-graphite-400"/><input type="number" min="0" placeholder="До" value={maxPrice} onChange={e=>setMaxPrice(e.target.value)} className="w-full rounded-xl border border-graphite-200 bg-white px-3 py-2.5 text-graphite-900 outline-none placeholder:text-graphite-400 focus:border-accent-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-graphite-400"/></div></div>
      <div className="flex gap-3 pb-6"><button type="button" onClick={resetFilters} className="flex-1 rounded-xl border border-graphite-200 px-4 py-3 font-medium text-graphite-800 transition-colors hover:border-accent-500 hover:text-accent-700 dark:border-white/10 dark:text-white dark:hover:bg-white/10">Сбросить</button><button type="button" onClick={()=>setFiltersOpen(false)} className="flex-1 rounded-xl bg-accent-500 px-4 py-3 font-semibold text-white transition-colors hover:bg-accent-600">Показать {filtered.length}</button></div>
    </div></aside></div>}
  </div></section></>;
}
