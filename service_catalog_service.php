<?php
declare(strict_types=1);

final class ServiceCatalogException extends RuntimeException {}

function serviceScreenSize(mixed $value): int {
    if (is_int($value)) return $value;
    if (is_float($value)) return (int)round($value);
    if (preg_match('/\d{2,3}/', (string)$value, $match) !== 1) throw new ServiceCatalogException('Invalid television screen size');
    return (int)$match[0];
}

function serviceCatalogList(PDO $pdo, bool $admin = false): array {
    $where = $admin ? '' : 'WHERE is_active = 1';
    $rows = $pdo->query("SELECT id,service_key,category,name,description,min_screen_size,max_screen_size,price,is_active,sort_order,requires_tv,metadata FROM service_catalog {$where} ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function(array $row): array {
        foreach (['id','min_screen_size','max_screen_size','sort_order'] as $key) if ($row[$key] !== null) $row[$key]=(int)$row[$key];
        $row['price']=$row['price'] === null ? null : (float)$row['price'];
        $row['is_active']=(bool)$row['is_active']; $row['requires_tv']=(bool)$row['requires_tv'];
        $row['metadata']=$row['metadata'] ? json_decode((string)$row['metadata'],true) : null;
        return $row;
    }, $rows);
}

function serviceCatalogCompatible(array $service, int $screenSize): bool {
    if (!($service['requires_tv'] ?? true)) return true;
    $min=$service['min_screen_size'] ?? null; $max=$service['max_screen_size'] ?? null;
    return ($min === null || $screenSize >= (int)$min) && ($max === null || $screenSize <= (int)$max);
}

function serviceCatalogResolve(PDO $pdo, array $requested, array $serverItems, bool $lock = false): array {
    if (count($requested)>100) throw new ServiceCatalogException('Too many services');
    $resolved=[];
    $bindings=[];
    foreach ($requested as $entry) {
        if (!is_array($entry)) throw new ServiceCatalogException('Invalid service');
        $serviceId=filter_var($entry['service_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $targetIndex=filter_var($entry['target_item_index'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $quantity=filter_var($entry['quantity'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($serviceId===false || $targetIndex===false || $quantity===false || !isset($serverItems[$targetIndex])) throw new ServiceCatalogException('Invalid service binding');
        $bindingKey=$serviceId.':'.$targetIndex;
        if (isset($bindings[$bindingKey])) throw new ServiceCatalogException('Duplicate service binding');
        $bindings[$bindingKey]=true;
        if ($quantity > (int)($serverItems[$targetIndex]['quantity'] ?? 0)) throw new ServiceCatalogException('Service quantity exceeds television quantity');
        $sql='SELECT id,service_key,category,name,description,min_screen_size,max_screen_size,price,is_active,sort_order,requires_tv,metadata FROM service_catalog WHERE id=:id' . ($lock?' FOR UPDATE':'');
        $stmt=$pdo->prepare($sql); $stmt->execute([':id'=>$serviceId]); $service=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$service || !(bool)$service['is_active'] || $service['price']===null) throw new ServiceCatalogException('Service unavailable');
        $screen=serviceScreenSize($serverItems[$targetIndex]['screen_size'] ?? '');
        if (!serviceCatalogCompatible($service,$screen)) throw new ServiceCatalogException('Incompatible service');
        $unit=(float)$service['price'];
        $snapshotMetadata=json_encode([
            'catalog_metadata'=>$service['metadata'] ? json_decode((string)$service['metadata'],true) : null,
            'description'=>$service['description'],
            'min_screen_size'=>$service['min_screen_size'] === null ? null : (int)$service['min_screen_size'],
            'max_screen_size'=>$service['max_screen_size'] === null ? null : (int)$service['max_screen_size'],
            'requires_tv'=>(bool)$service['requires_tv'],
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $resolved[]=['service_id'=>(int)$service['id'],'service_key'=>$service['service_key'],'category'=>$service['category'],'name'=>$service['name'],'target_item_index'=>(int)$targetIndex,'television_name'=>$serverItems[$targetIndex]['name'],'screen_size'=>$screen,'unit_price'=>$unit,'quantity'=>(int)$quantity,'total'=>round($unit*$quantity,2),'metadata'=>$snapshotMetadata];
    }
    return $resolved;
}

function serviceCatalogTotal(array $services): float { return round(array_reduce($services,static fn(float $sum,array $s):float=>$sum+(float)$s['total'],0.0),2); }
