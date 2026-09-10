import { useCallback, useEffect, useState } from 'react';

type Service = { id:number; service_key:string; category:string; name:string; description:string; min_screen_size:number|null; max_screen_size:number|null; price:number|null; is_active:boolean; sort_order:number };
const API = 'https://telvora.ru/manager.php';

export default function ServiceCatalogAdmin() {
  const [items,setItems]=useState<Service[]>([]);
  const [message,setMessage]=useState('');
  const load=useCallback(async()=>{try{const response=await fetch(`${API}?action=service_catalog_list`,{credentials:'include'});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Не удалось загрузить услуги');setItems(data.services)}catch(error){setMessage(error instanceof Error?error.message:'Не удалось загрузить услуги')}},[]);
  useEffect(()=>{void load()},[load]);
  const update=(id:number,patch:Partial<Service>)=>setItems(current=>current.map(service=>service.id===id?{...service,...patch}:service));
  const save=async(service:Service)=>{setMessage('');const response=await fetch(`${API}?action=service_catalog_update`,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({...service,action:'service_catalog_update'})});const data=await response.json();setMessage(data.success?'Сохранено':data.message||'Не удалось сохранить');if(data.success)await load()};
  return <section><div className="mb-6"><h2 className="text-xl font-display font-semibold">Сервисные услуги</h2><p className="mt-1 text-sm text-gray-500">Каталог отделён от товаров и остатков. Изменения цены применяются только к будущим заказам.</p></div>{message&&<p className="mb-4 text-sm text-accent-600">{message}</p>}<div className="space-y-4">{items.map(service=><article key={service.id} className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"><div className="grid gap-3 md:grid-cols-6">
    <label className="md:col-span-2 text-xs">Название<input className="admin-input mt-1" value={service.name} onChange={event=>update(service.id,{name:event.target.value})}/></label>
    <label className="md:col-span-4 text-xs">Описание<input className="admin-input mt-1" value={service.description} onChange={event=>update(service.id,{description:event.target.value})}/></label>
    <label className="text-xs">От<input className="admin-input mt-1" type="number" value={service.min_screen_size??''} onChange={event=>update(service.id,{min_screen_size:event.target.value===''?null:Number(event.target.value)})}/></label>
    <label className="text-xs">До<input className="admin-input mt-1" type="number" value={service.max_screen_size??''} onChange={event=>update(service.id,{max_screen_size:event.target.value===''?null:Number(event.target.value)})}/></label>
    <label className="text-xs">Цена<input className="admin-input mt-1" type="number" value={service.price??''} onChange={event=>update(service.id,{price:event.target.value===''?null:Number(event.target.value)})}/></label>
    <label className="text-xs">Порядок<input className="admin-input mt-1" type="number" value={service.sort_order} onChange={event=>update(service.id,{sort_order:Number(event.target.value)})}/></label>
    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={service.is_active} onChange={event=>update(service.id,{is_active:event.target.checked})}/>Активна</label>
    <button onClick={()=>void save(service)} className="rounded-xl bg-accent-500 px-4 py-2 text-white">Сохранить</button>
  </div></article>)}</div></section>;
}
