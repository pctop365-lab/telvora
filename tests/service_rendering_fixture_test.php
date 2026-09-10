<?php
declare(strict_types=1);
function contains(string $file,array $needles):void{$source=(string)file_get_contents(dirname(__DIR__).'/'.$file);foreach($needles as $needle){if(!str_contains($source,$needle))throw new RuntimeException("FAIL {$file}: {$needle}");}echo "PASS {$file}\n";}
contains('generate_invoice_pdf.php',['order_services','Сервисные услуги','servicesTotal','serviceHtml']);
contains('telegram_polling.php',['orderServicesText','order_services','Сервисные услуги']);
contains('manager.php',['service_catalog_update','Диапазон пересекается','order_services']);
contains('src/pages/OrderSuccessPage.tsx',['summary.services','servicesTotal','Сервисные услуги']);
echo "SERVICE RENDERING FIXTURES PASSED\n";
