<?php
declare(strict_types=1);
function contains(string $file,array $needles):void{$source=(string)file_get_contents(dirname(__DIR__).'/'.$file);foreach($needles as $needle){if(!str_contains($source,$needle))throw new RuntimeException("FAIL {$file}: {$needle}");}echo "PASS {$file}\n";}
contains('generate_invoice_pdf.php',['order_services','<th>Наименование</th>','Доставка — стоимость согласовывается','final-block','table-header-group','min_screen_size']);
$pdfSource=(string)file_get_contents(dirname(__DIR__).'/generate_invoice_pdf.php');
if(str_contains($pdfSource,'<h3>Сервисные услуги</h3>')||str_contains($pdfSource,"' . \$serviceHtml . '")||str_contains($pdfSource,'СЕРВИСНЫЕ УСЛУГИ:</td>'))throw new RuntimeException('FAIL generate_invoice_pdf.php: separate service block remains');
if(substr_count($pdfSource,'class="total-table"')!==1)throw new RuntimeException('FAIL generate_invoice_pdf.php: expected one total block');
contains('telegram_polling.php',['orderServicesText','order_services','Сервисные услуги']);
contains('manager.php',['service_catalog_update','Диапазон пересекается','order_services']);
contains('src/pages/OrderSuccessPage.tsx',['summary.services','servicesTotal','Сервисные услуги']);
echo "SERVICE RENDERING FIXTURES PASSED\n";
