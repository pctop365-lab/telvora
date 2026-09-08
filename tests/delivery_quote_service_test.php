<?php
declare(strict_types=1);
require_once __DIR__ . '/../delivery_quote_service.php';
function expectQuote(string $name, mixed $actual, mixed $expected): void { if ($actual !== $expected) { fwrite(STDERR, "FAIL $name\n"); exit(1); } }

$rates = [43=>1000.0,55=>1000.0,56=>1500.0,65=>1500.0,75=>2000.0,77=>2000.0,83=>3000.0,85=>3000.0,98=>5000.0,100=>5000.0,115=>8000.0,116=>8000.0,136=>25000.0,146=>25000.0];
foreach ($rates as $size=>$price) expectQuote("rate $size", telvoraMoscowDeliveryRate($size), $price);
foreach ([42,66,74,78,82,86,97,101,114,117,135,147] as $size) expectQuote("gap $size", telvoraMoscowDeliveryRate($size), null);
$item = static fn(string $size, int $quantity=1): array => ['screen_size'=>$size,'quantity'=>$quantity];
expectQuote('single 55 confirmed', telvoraDeliveryQuote('courier',[$item('55″')])['price'], 1000.0);
expectQuote('client cannot set delivery price', array_key_exists('client_price',telvoraDeliveryQuote('courier',[$item('55″')])), false);
$outside = telvoraDeliveryQuote('courier',[$item('55')],true,10);
expectQuote('outside pending', $outside['status'], 'pending'); expectQuote('outside estimate', $outside['estimate'], 1600.0); expectQuote('outside authoritative null', $outside['price'], null);
expectQuote('invalid outside km', telvoraDeliveryQuote('courier',[$item('55')],true,999)['estimate'], null);
expectQuote('multiple pending', telvoraDeliveryQuote('courier',[$item('55'),$item('65')])['reason'], 'multiple_televisions');
expectQuote('quantity pending', telvoraDeliveryQuote('courier',[$item('55',2)])['status'], 'pending');
expectQuote('missing size pending', telvoraDeliveryQuote('courier',[$item('70')])['reason'], 'size_not_tariffed');
expectQuote('pickup zero', telvoraDeliveryQuote('pickup',[$item('70',5)])['price'], 0.0);
expectQuote('regional carrier separate', telvoraDeliveryQuote('post',[$item('65')])['reason'], 'moscow_terminal');
echo "PASS delivery quote boundaries and authority\n";
