<?php

declare(strict_types=1);

define('TELVORA_PRODUCT_DELETE_TEST_LIBRARY', true);
require_once __DIR__ . '/product_delete_mysql_test.php';

function configGuardAssert(string $name, bool $condition): void
{
    if (!$condition) throw new RuntimeException("FAIL {$name}");
    echo "PASS {$name}\n";
}

$base = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'name' => 'telvora_product_delete_test',
    'user' => 'telvora_test',
    'password' => 'ci-only-password',
    'ci' => true,
];

deleteTestValidateConfig($base);
configGuardAssert('canonical CI database name accepted', true);

foreach ([
    ['name' => 'telvora', 'label' => 'bare production name'],
    ['name' => 'telvora_prod', 'label' => 'prod name'],
    ['name' => 'production', 'label' => 'production name'],
    ['name' => 'telvora_production', 'label' => 'telvora production name'],
    ['name' => 'customer_db', 'label' => 'unscoped name'],
] as $case) {
    try {
        deleteTestValidateConfig(array_replace($base, ['name' => $case['name']]));
        throw new RuntimeException("accepted {$case['label']}");
    } catch (RuntimeException $error) {
        configGuardAssert($case['label'] . ' rejected', str_contains($error->getMessage(), 'database name'));
    }
}

foreach ([
    ['host' => 'server45.hosting.reg.ru', 'label' => 'REG.RU host'],
    ['host' => 'db.telvora.ru', 'label' => 'TELVORA host'],
    ['host' => '10.0.0.5', 'label' => 'non-loopback host'],
] as $case) {
    try {
        deleteTestValidateConfig(array_replace($base, ['host' => $case['host']]));
        throw new RuntimeException("accepted {$case['label']}");
    } catch (RuntimeException $error) {
        configGuardAssert($case['label'] . ' rejected', str_contains($error->getMessage(), 'host'));
    }
}

foreach (['name', 'host', 'port', 'user', 'password'] as $missing) {
    $config = $base;
    $config[$missing] = '';
    try {
        deleteTestValidateConfig($config);
        throw new RuntimeException("accepted missing {$missing}");
    } catch (RuntimeException $error) {
        configGuardAssert("missing {$missing} rejected", str_contains($error->getMessage(), 'variables'));
    }
}

echo "PASS product delete config guard\n";
