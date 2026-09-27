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
  const seoTitle = categoryLabel ? `РўРµР»РµРІРёР·РѕСЂС‹ ${categoryLabel} вЂ” РєР°С‚Р°Р»РѕРі TELVORA` : 'РўРµР»РµРІРёР·РѕСЂС‹ вЂ” РєР°С‚Р°Р»РѕРі TELVORA';
  const seoDescription = categoryLabel
    ? `РўРµР»РµРІРёР·РѕСЂС‹ ${categoryLabel} РІ РєР°С‚Р°Р»РѕРіРµ TELVORA: Р°РєС‚СѓР°Р»СЊРЅС‹Рµ РјРѕРґРµР»Рё, С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєРё, С†РµРЅС‹, РґРѕСЃС‚Р°РІРєР° Рё РїСЂРѕС„РµСЃСЃРёРѕРЅР°Р»СЊРЅР°СЏ СѓСЃС‚Р°РЅРѕРІРєР°.`
    : 'РљР°С‚Р°Р»РѕРі С‚РµР»РµРІРёР·РѕСЂРѕРІ TELVORA: Р°РєС‚СѓР°Р»СЊРЅС‹Рµ РјРѕРґРµР»Рё, С…Р°СЂР°РєС‚РµСЂРёСЃС‚РёРєРё, С†РµРЅС‹, РґРѕСЃС‚Р°РІРєР° Рё РїСЂРѕС„РµСЃСЃРёРѕРЅР°Р»СЊРЅР°СЏ СѓСЃС‚Р°РЅРѕРІРєР°.';
  const seoPath = categorySlug ? `/catalog/${categorySlug}` : '/catalog';

  return <><SeoMetadata title={seoTitle} description={seoDescription} path={seoPath} /><section className="min-h-screen bg-graphite-50 pb-20 pt-24 dark:bg-graphite-900"><div className="mx-auto max-w-8xl px-4 sm:px-6 lg:px-8">
    <div className="mb-8 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between"><div><span className="text-sm font-semibold uppercase tracking-widest text-accent-600 dark:text-accent-400">РўРµР»РµРІРёР·РѕСЂС‹</span><h1 className="mt-2 font-display text-4xl font-extrabold tracking-tight text-graphite-950 dark:text-white sm:text-5xl">РљР°С‚Р°Р»РѕРі РїРѕ РјРѕРґРµР»СЊРЅС‹Рј СЂСЏРґР°Рј</h1><p className="mt-3 max-w-2xl text-graphite-600 dark:text-graphite-300">Р’С‹Р±РµСЂРёС‚Рµ РјРѕРґРµР»СЊРЅС‹Р№ СЂСЏРґ, Р·Р°С‚РµРј СѓС‚РѕС‡РЅРёС‚Рµ С‚РµС…РЅРѕР»РѕРіРёСЋ СЌРєСЂР°РЅР°, СЂР°Р·СЂРµС€РµРЅРёРµ, Р±СЂРµРЅРґ, РґРёР°РіРѕРЅР°Р»СЊ Рё С†РµРЅСѓ.</p></div><div className="flex flex-wrap gap-3"><button type="button" onClick={()=>setFiltersOpen(true)} className="flex items-center gap-2 rounded-xl border border-graphite-200 bg-white px-4 py-2.5 text-sm font-medium text-graphite-800 shadow-sm transition-colors hover:border-accent-500 hover:text-accent-700 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10"><SlidersHorizontal className="h-4 w-4"/>Р¤РёР»СЊС‚СЂС‹{activeCount>0&&<span className="flex h-5 w-5 items-center justify-center rounded-full bg-accent-500 text-xs font-bold text-white">{activeCount}</span>}</button><select value={sort} onChange={e=>setSort(e.target.value as SortKey)} className="rounded-xl border border-graphite-200 bg-white px-4 py-2.5 text-sm font-medium text-graphite-800 shadow-sm outline-none transition-colors focus:border-accent-500 dark:border-white/10 dark:bg-graphite-800 dark:text-white"><option value="default">РџРѕ СѓРјРѕР»С‡Р°РЅРёСЋ</option><option value="price-asc">РЎРЅР°С‡Р°Р»Р° РґРµС€РµРІР»Рµ</option><option value="price-desc">РЎРЅР°С‡Р°Р»Р° РґРѕСЂРѕР¶Рµ</option><option value="rating">РџРѕ СЂРµР№С‚РёРЅРіСѓ</option></select></div></div>

    <div className="mb-8 rounded-2xl border border-graphite-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><div className="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-graphite-500 dark:text-graphite-300">РњРѕРґРµР»СЊРЅС‹Рµ СЂСЏРґС‹</div><div className="flex flex-wrap gap-2"><button type="button" onClick={()=>setModelKey('')} className={`rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors ${!modelKey?'bg-accent-500 text-white':'border border-graphite-200 bg-graphite-50 text-graphite-700 hover:border-accent-500 hover:text-accent-700 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10'}`}>Р’СЃРµ РјРѕРґРµР»Рё <span className="opacity-60">{products.length}</span></button>{modelGroups.map(group=><button type="button" key={group.key} onClick={()=>setModelKey(group.key)} className={`rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors ${modelKey===group.key?'bg-accent-500 text-white':'border border-graphite-200 bg-graphite-50 text-graphite-700 hover:border-accent-500 hover:text-accent-700 dark:border-white/10 dark:bg-white/5 dark:text-graphite-200 dark:hover:bg-white/10'}`}>{group.label} <span className="opacity-60">{group.count}</span></button>)}</div></div>

    <div className="mb-6 flex flex-wrap items-center justify-between gap-3"><span className="text-sm text-graphite-600 dark:text-graphite-300">РќР°Р№РґРµРЅРѕ С‚РѕРІР°СЂРѕРІ: {filtered.length}</span>{activeCount>0&&<button type="button" onClick={resetFilters} className="text-sm font-semibold text-accent-700 hover:text-accent-600 dark:text-accent-400 dark:hover:text-accent-300">РЎР±СЂРѕСЃРёС‚СЊ РІСЃРµ С„РёР»СЊС‚СЂС‹</button>}</div>
    {!loading&&!error&&filtered.length===0?<div className="rounded-2xl border border-graphite-200 bg-white py-20 text-center dark:border-white/10 dark:bg-white/[0.03]"><p className="text-lg text-graphite-600 dark:text-graphite-300">РџРѕ РІС‹Р±СЂР°РЅРЅС‹Рј СѓСЃР»РѕРІРёСЏРј РЅРёС‡РµРіРѕ РЅРµ РЅР°Р№РґРµРЅРѕ.</p><button type="button" onClick={resetFilters} className="mt-4 rounded-xl bg-accent-500 px-5 py-2.5 font-semibold text-white">РЎР±СЂРѕСЃРёС‚СЊ С„РёР»СЊС‚СЂС‹</button></div>:<ProductGrid products={filtered} loading={loading} error={error}/>}

    {filtersOpen&&<div className="fixed inset-0 z-[70]"><button type="button" aria-label="Р—Р°РєСЂС‹С‚СЊ С„РёР»СЊС‚СЂС‹" className="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-sm dark:bg-black/70" onClick={()=>setFiltersOpen(false)}/><aside role="dialog" aria-modal="true" aria-label="Р¤РёР»СЊС‚СЂС‹ РєР°С‚Р°Р»РѕРіР°" className="absolute bottom-0 right-0 top-0 w-full overflow-y-auto border-l border-graphite-200 bg-white text-graphite-900 shadow-2xl dark:border-white/10 dark:bg-graphite-800 dark:text-white sm:w-[430px]"><div className="sticky top-0 z-10 flex items-center justify-between border-b border-graphite-200 bg-white p-5 dark:border-white/10 dark:bg-graphite-800"><div><h2 className="text-xl font-bold text-graphite-950 dark:text-white">Р¤РёР»СЊС‚СЂС‹</h2><p className="mt-1 text-sm text-graphite-600 dark:text-graphite-300">РќР°Р№РґРµРЅРѕ: {filtered.length}</p></div><button type="button" aria-label="Р—Р°РєСЂС‹С‚СЊ" onClick={()=>setFiltersOpen(false)} className="rounded-lg p-2 text-graphite-600 transition-colors hover:bg-graphite-100 hover:text-graphite-950 dark:text-graphite-200 dark:hover:bg-white/10 dark:hover:text-white"><X className="h-5 w-5"/></button></div><div className="space-y-8 p-5">
      {choices('Р‘СЂРµРЅРґ',brandOptions,brands,setBrands)}
      {choices('Р”РёР°РіРѕРЅР°Р»СЊ',sizeOptions,sizes,setSizes)}
      {choices('Р Р°Р·СЂРµС€РµРЅРёРµ',resolutionOptions,resolutions,setResolutions)}
      {choices('РўРµС…РЅРѕР»РѕРіРёСЏ СЌРєСЂР°РЅР°',technologyOptions,technologies,setTechnologies)}
      <div><h3 className="mb-3 text-sm font-semibold text-graphite-900 dark:text-white">Р¦РµРЅР°, в‚Ѕ</h3><div className="grid grid-cols-2 gap-3"><input type="number" min="0" placeholder="РћС‚" value={minPrice} onChange={e=>setMinPrice(e.target.value)} className="w-full rounded-xl border border-graphite-200 bg-white px-3 py-2.5 text-graphite-900 outline-none placeholder:text-graphite-400 focus:border-accent-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-graphite-400"/><input type="number" min="0" placeholder="Р”Рѕ" value={maxPrice} onChange={e=>setMaxPrice(e.target.value)} className="w-full rounded-xl border border-graphite-200 bg-white px-3 py-2.5 text-graphite-900 outline-none placeholder:text-graphite-400 focus:border-accent-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-graphite-400"/></div></div>
      <div className="flex gap-3 pb-6"><button type="button" onClick={resetFilters} className="flex-1 rounded-xl border border-graphite-200 px-4 py-3 font-medium text-graphite-800 transition-colors hover:border-accent-500 hover:text-accent-700 dark:border-white/10 dark:text-white dark:hover:bg-white/10">РЎР±СЂРѕСЃРёС‚СЊ</button><button type="button" onClick={()=>setFiltersOpen(false)} className="flex-1 rounded-xl bg-accent-500 px-4 py-3 font-semibold text-white transition-colors hover:bg-accent-600">РџРѕРєР°Р·Р°С‚СЊ {filtered.length}</button></div>
    </div></aside></div>}
  </div></section></>;
}
