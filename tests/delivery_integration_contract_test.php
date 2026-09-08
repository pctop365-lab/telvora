<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$api = file_get_contents($root . '/api.php'); $pdf = file_get_contents($root . '/generate_invoice_pdf.php'); $manager = file_get_contents($root . '/manager.php');
foreach (["require_once __DIR__ . '/delivery_quote_service.php'", "':delivery_price' => \$delivery", "'delivery_status' => \$deliveryQuote['status']", "'total' => \$deliveryQuote['status']"] as $needle) if (!str_contains($api,$needle)) { fwrite(STDERR,"FAIL api contract: $needle\n"); exit(1); }
if (str_contains($api,"\$data['delivery_price']")) { fwrite(STDERR,"FAIL client delivery price accepted\n"); exit(1); }
foreach (['delivery_price','delivery_quote_status','Стоимость согласовывается','ДОСТАВКА:'] as $needle) if (!str_contains($pdf.$manager,$needle)) { fwrite(STDERR,"FAIL document/admin contract: $needle\n"); exit(1); }
echo "PASS delivery persistence and invoice contracts\n";
