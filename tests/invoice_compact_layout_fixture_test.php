<?php
declare(strict_types=1);
use Dompdf\Dompdf;
use Dompdf\Options;
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$configuredRoot=getenv('TELVORA_TEST_ROOT');$root=is_string($configuredRoot)&&$configuredRoot!==''?$configuredRoot:dirname(__DIR__);$source=(string)file_get_contents($root.'/generate_invoice_pdf.php');
foreach(['<th>Наименование</th>','table-header-group','page-break-inside: avoid','class="final-block"','Доставка — стоимость согласовывается','min_screen_size'] as $needle)check(str_contains($source,$needle),"generator missing {$needle}");
check(!str_contains($source,'<h3>Сервисные услуги</h3>'),'separate service table remains');
check(!str_contains($source,'position: fixed'),'footer must stay inside the final block');
check(str_contains($source,'SELECT service_name,television_name,screen_size,unit_price,quantity,total,metadata FROM order_services'),'historical service snapshot query is incomplete');
check(!str_contains($source,'FROM service_catalog'),'invoice must not read current service catalog prices');
check(str_contains($source,"\$totalText = \$isDeliveryPending ? 'После согласования доставки' : money(\$order['total'])"),'order snapshot total or pending behavior changed');
check(str_contains($source,'mailto:'),'contact mail links are missing');
$autoload=getenv('TELVORA_TEST_AUTOLOAD');check(is_string($autoload)&&is_file($autoload),'TELVORA_TEST_AUTOLOAD is required');require $autoload;
$css='@page{margin:18mm 15mm}body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111;margin:0}table{width:100%;border-collapse:collapse}.header{text-align:center;margin-bottom:14px}.info{margin-top:9px}.info td,.rows td,.rows th{border:1px solid #222;padding:5.5px 6px}.rows{margin-top:12px}.rows th{background:#e9e9e9}.rows thead{display:table-header-group}.rows tr{page-break-inside:avoid;break-inside:avoid}.final{page-break-inside:avoid;break-inside:avoid}.total{margin-top:8px;font-size:12px;font-weight:bold}.notice{margin-top:13px;font-size:8.5px}.signatures{margin-top:18px}.signatures td{width:50%}.line{margin-top:22px;border-bottom:1px solid #222;height:20px}.logo{text-align:center;margin-top:12px;font-weight:bold;font-size:18px}';
function fixtureHtml(string $css,int $products,array $services,bool $pending,float $delivery):string{$rows='';$n=1;$total=0;for($i=1;$i<=$products;$i++,$n++){$price=100000+$i;$total+=$price;$rows.="<tr><td>{$n}</td><td>Телевизор fixture {$i}</td><td>1</td><td>{$price} ₽</td><td>{$price} ₽</td></tr>";}foreach($services as [$name,$range,$price]){$total+=$price;$rows.="<tr><td>{$n}</td><td>{$name} {$range}″</td><td>1</td><td>{$price} ₽</td><td>{$price} ₽</td></tr>";$n++;}if($pending){$rows.="<tr><td>{$n}</td><td>Доставка — стоимость согласовывается</td><td>1</td><td>—</td><td>—</td></tr>";$totalText='После согласования доставки';}else{$total+=$delivery;$rows.="<tr><td>{$n}</td><td>Доставка — по Москве</td><td>1</td><td>{$delivery} ₽</td><td>{$delivery} ₽</td></tr>";$totalText="{$total} ₽";}return '<!doctype html><html lang="ru"><meta charset="UTF-8"><style>'.$css.'</style><body><div class="header"><h2>ТОВАРНАЯ НАКЛАДНАЯ</h2>№ FIXTURE</div><table class="info"><tr><td>Продавец</td><td>TELVORA</td></tr><tr><td>Почта</td><td><a href="mailto:telvora24@gmail.com">telvora24@gmail.com</a> / <a href="mailto:telvorasupport24@gmail.com">telvorasupport24@gmail.com</a></td></tr><tr><td>Покупатель</td><td>Fixture</td></tr></table><table class="rows"><thead><tr><th>№</th><th>Наименование</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>'.$rows.'</tbody></table><div class="final"><table class="total"><tr><td>ИТОГО:</td><td>'.$totalText.'</td></tr></table><div class="notice">Товар проверен. Претензий к внешнему виду не имею.</div><table class="signatures"><tr><td>Продавец<div class="line"></div>ФИО</td><td>Покупатель<div class="line"></div>ФИО</td></tr></table><div class="logo">TELVORA</div></div></body></html>';}
$fixtures=[
 'one-product'=>[1,[],false,2000],
 'mounting'=>[1,[['Монтаж телевизора на стену','66–77',12000]],false,2000],
 'two-services'=>[1,[['Монтаж телевизора на стену','66–77',12000],['Пиксель-тест','75–82',7000]],false,2000],
 'long-order'=>[42,array_fill(0,8,['Монтаж телевизора на стену','66–77',12000]),false,2000],
 'pending-delivery'=>[1,[['Монтаж телевизора на стену','43–55',7000]],true,0],
];
foreach($fixtures as $name=>[$products,$services,$pending,$delivery]){$options=new Options();$options->set('isRemoteEnabled',false);$pdf=new Dompdf($options);$pdf->loadHtml(fixtureHtml($css,$products,$services,$pending,$delivery),'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();$pages=$pdf->getCanvas()->get_page_count();check($name==='long-order'?$pages>=2:$pages===1,"{$name} unexpected page count {$pages}");echo "FIXTURE {$name}: {$pages} page(s)\n";}
echo "INVOICE COMPACT LAYOUT FIXTURES PASSED\n";
