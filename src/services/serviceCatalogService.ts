import type { ServiceCatalogItem } from '@/types';
const API='https://telvora.ru/services.php';
export async function fetchServices():Promise<ServiceCatalogItem[]>{const response=await fetch(API);const data=await response.json();if(!response.ok||!data.success||!Array.isArray(data.services))throw new Error(data.message||'Не удалось загрузить услуги');return data.services}
export const screenSizeNumber=(value:string)=>Number(value.match(/\d{2,3}/)?.[0]??0);
export const serviceFits=(service:ServiceCatalogItem,size:number)=>!service.requires_tv||((service.min_screen_size===null||size>=service.min_screen_size)&&(service.max_screen_size===null||size<=service.max_screen_size));
