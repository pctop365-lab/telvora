<?php

declare(strict_types=1);

const TELVORA_DELIVERY_QUOTE_CONFIRMED = 'confirmed';
const TELVORA_DELIVERY_QUOTE_PENDING = 'pending';

function telvoraParseScreenSize(mixed $value): ?int
{
    if (!is_string($value) && !is_numeric($value)) return null;
    if (!preg_match('/\d{2,3}/', (string)$value, $match)) return null;
    $size = (int)$match[0];
    return $size >= 20 && $size <= 200 ? $size : null;
}

function telvoraMoscowDeliveryRate(?int $size): ?float
{
    if ($size === null) return null;
    return match (true) {
        $size >= 43 && $size <= 55 => 1000.0,
        $size >= 56 && $size <= 65 => 1500.0,
        $size >= 75 && $size <= 77 => 2000.0,
        $size >= 83 && $size <= 85 => 3000.0,
        $size >= 98 && $size <= 100 => 5000.0,
        $size >= 115 && $size <= 116 => 8000.0,
        $size >= 136 && $size <= 146 => 25000.0,
        default => null,
    };
}

/** @return array{status:string,price:?float,estimate:?float,reason:string,details:array<string,mixed>} */
function telvoraDeliveryQuote(string $method, array $items, bool $outsideMkad = false, mixed $requestedKm = null): array
{
    $details = ['outside_mkad' => $outsideMkad];
    if ($method === 'pickup') {
        return ['status'=>TELVORA_DELIVERY_QUOTE_CONFIRMED,'price'=>0.0,'estimate'=>0.0,'reason'=>'pickup','details'=>$details];
    }
    if (!in_array($method, ['courier', 'post'], true)) throw new InvalidArgumentException('Invalid delivery method');

    $unitCount = 0;
    foreach ($items as $item) $unitCount += max(0, (int)($item['quantity'] ?? 0));
    if (count($items) !== 1 || $unitCount !== 1) {
        return ['status'=>TELVORA_DELIVERY_QUOTE_PENDING,'price'=>null,'estimate'=>null,'reason'=>'multiple_televisions','details'=>$details];
    }

    $size = telvoraParseScreenSize($items[0]['screen_size'] ?? null);
    $details['screen_size'] = $size;
    $base = telvoraMoscowDeliveryRate($size);
    if ($base === null) {
        return ['status'=>TELVORA_DELIVERY_QUOTE_PENDING,'price'=>null,'estimate'=>null,'reason'=>'size_not_tariffed','details'=>$details];
    }
    $details['moscow_base'] = $base;

    if ($outsideMkad) {
        $km = filter_var($requestedKm, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>500]]);
        if ($km === false) $km = null;
        $details['requested_outside_mkad_km'] = $km;
        return [
            'status'=>TELVORA_DELIVERY_QUOTE_PENDING,
            'price'=>null,
            'estimate'=>$km === null ? null : $base + 60.0 * $km,
            'reason'=>'outside_mkad_requires_confirmation',
            'details'=>$details,
        ];
    }

    return ['status'=>TELVORA_DELIVERY_QUOTE_CONFIRMED,'price'=>$base,'estimate'=>$base,'reason'=>$method === 'post' ? 'moscow_terminal' : 'moscow','details'=>$details];
}
