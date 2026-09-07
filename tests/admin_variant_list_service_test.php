<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/admin_variant_list_service.php';

final class AdminVariantFixtureStatement extends PDOStatement
{
    private array $rows = [];
    private int $position = 0;
    private mixed $column = false;

    public function __construct(private readonly AdminVariantFixturePdo $fixture, private readonly string $query) {}

    public function execute(?array $params = null): bool
    {
        $this->fixture->queries[] = $this->query;
        $this->position = 0;
        $params ??= [];
        if (str_contains($this->query, 'FROM products WHERE id')) {
            $product = $this->fixture->products[(int)$params[':id']] ?? null;
            $this->rows = $product === null ? [] : [$product];
        } elseif (str_contains($this->query, 'FROM product_variants pv')) {
            $productId = (int)$params[':product_id'];
            $this->rows = array_values(array_filter(
                $this->fixture->variants,
                static fn(array $variant): bool => $variant['product_id'] === $productId
            ));
        } elseif (str_contains($this->query, 'WEIGHT_STRING')) {
            $this->column = bin2hex(mb_strtolower((string)$params[':value'], 'UTF-8'));
            $this->rows = [];
        } else {
            throw new RuntimeException('Unexpected fixture query');
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[$this->position++] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return $this->column; }
}

class AdminVariantFixturePdo extends PDO
{
    public array $queries = [];
    public function __construct(public array $products, public array $variants) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AdminVariantFixtureStatement($this, $query);
    }
}

final class AdminVariantThrowingPdo extends AdminVariantFixturePdo
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new PDOException('fixture database failure');
    }
}

function fixtureLegacy(string $country, mixed $price, mixed $oldPrice = null, mixed $active = true): array
{
    return ['country' => $country, 'price' => $price, 'old_price' => $oldPrice, 'is_active' => $active];
}

function fixtureVariant(int $id, int $productId, string $country, bool $active = true, array $overrides = []): array
{
    return $overrides + [
        'id' => $id, 'product_id' => $productId,
        'variant_key' => 'legacy-country-sha256-' . hash('sha256', $country),
        'assembly_country' => $country, 'is_active' => $active ? 1 : 0,
        'offers_count' => 0, 'matches_count' => 0, 'import_rows_count' => 0,
        'audit_count' => 0, 'order_references_count' => 0,
        'provenance_mismatch_count' => 0,
    ];
}

function expectValue(string $name, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) throw new RuntimeException("FAIL $name: " . var_export($actual, true));
    echo "PASS $name\n";
}

function expectThrows(string $name, callable $callback, string $class): void
{
    try { $callback(); } catch (Throwable $error) {
        expectValue($name, $error::class, $class);
        return;
    }
    throw new RuntimeException("FAIL $name: exception was not thrown");
}

$products = [
    1 => ['id'=>1, 'variants'=>json_encode([fixtureLegacy('Russia',271400,290000), fixtureLegacy('Poland',0)], JSON_UNESCAPED_UNICODE)],
    2 => ['id'=>2, 'variants'=>json_encode([fixtureLegacy('Japan',250000), fixtureLegacy('China',230000)], JSON_UNESCAPED_UNICODE)],
    3 => ['id'=>3, 'variants'=>'[]'],
    4 => ['id'=>4, 'variants'=>json_encode([fixtureLegacy('Turkey',220000), fixtureLegacy('turkey',225000)], JSON_UNESCAPED_UNICODE)],
    5 => ['id'=>5, 'variants'=>json_encode([fixtureLegacy('Mexico',240000)], JSON_UNESCAPED_UNICODE)],
    6 => ['id'=>6, 'variants'=>json_encode([fixtureLegacy('Vietnam',210000), fixtureLegacy('Korea','invalid')], JSON_UNESCAPED_UNICODE)],
    7 => ['id'=>7, 'variants'=>'{invalid-json'],
    8 => ['id'=>8, 'variants'=>json_encode([fixtureLegacy('Indonesia',280000,300000,false)], JSON_UNESCAPED_UNICODE)],
    9 => ['id'=>9, 'variants'=>json_encode([fixtureLegacy('Spain',260000,null,false)], JSON_UNESCAPED_UNICODE)],
    10 => ['id'=>10, 'variants'=>json_encode([fixtureLegacy('Korea',275000)], JSON_UNESCAPED_UNICODE)],
];
$variants = [
    fixtureVariant(1,1,'Russia',true,['offers_count'=>1,'provenance_mismatch_count'=>1]),
    fixtureVariant(2,1,'Poland'), fixtureVariant(3,2,'Japan'),
    fixtureVariant(4,3,'Default',false), fixtureVariant(5,4,'Turkey'),
    fixtureVariant(6,5,'Brazil',true,['variant_key'=>'wrong-key']),
    fixtureVariant(7,6,'Vietnam'), fixtureVariant(8,8,'Indonesia',false),
    fixtureVariant(9,9,'Spain',true),
    fixtureVariant(10,10,'Korea',true,['variant_key'=>'wrong-key']),
];
$pdo = new AdminVariantFixturePdo($products, $variants);

$twoCorrect = adminVariantListFetch($pdo, 1);
expectValue('two correct relational variants', count($twoCorrect['variants']), 2);
expectValue('two correct have no orphan', $twoCorrect['legacy_orphans'], []);
expectValue('two correct have no unresolved legacy', $twoCorrect['legacy_unresolved'], []);
expectValue('positive exact price', $twoCorrect['variants'][0]['published_price'], 271400);
expectValue('positive exact old price', $twoCorrect['variants'][0]['old_price'], 290000);
expectValue('positive identity ready', $twoCorrect['variants'][0]['identity_ready'], true);
expectValue('positive published price flag', $twoCorrect['variants'][0]['has_published_price'], true);
expectValue('provenance remains diagnostic', $twoCorrect['variants'][0]['diagnostics'], ['offer_source_mismatch']);
expectValue('zero draft exact price', $twoCorrect['variants'][1]['published_price'], 0);
expectValue('zero draft identity ready', $twoCorrect['variants'][1]['identity_ready'], true);
expectValue('zero draft has no published price', $twoCorrect['variants'][1]['has_published_price'], false);

$oneOrphan = adminVariantListFetch($pdo, 2);
expectValue('one correct relational variant', count($oneOrphan['variants']), 1);
expectValue('exactly one proven orphan', count($oneOrphan['legacy_orphans']), 1);
expectValue('proven orphan country', $oneOrphan['legacy_orphans'][0]['country'], 'China');
expectValue('proven orphan diagnostic price', $oneOrphan['legacy_orphans'][0]['published_price'], 230000);
expectValue('proven orphan is not identity ready', array_key_exists('identity_ready', $oneOrphan['legacy_orphans'][0]), false);

$relationalOnly = adminVariantListFetch($pdo, 3)['variants'][0];
expectValue('empty JSON relational diagnostic', $relationalOnly['diagnostic_code'], 'relational_without_legacy');
expectValue('empty JSON relational price closed', $relationalOnly['published_price'], null);
expectValue('empty JSON relational identity closed', $relationalOnly['identity_ready'], false);

$duplicate = adminVariantListFetch($pdo, 4);
expectValue('global duplicate diagnostic', $duplicate['diagnostics'], ['duplicate_legacy_country']);
expectValue('duplicate creates no proven orphan', $duplicate['legacy_orphans'], []);
expectValue('duplicate unresolved includes whole document', count($duplicate['legacy_unresolved']), 2);
expectValue('duplicate unresolved price closed', $duplicate['legacy_unresolved'][0]['published_price'], null);
expectValue('matched variant closed on invalid document', $duplicate['variants'][0]['identity_ready'], false);
expectValue('matched variant price closed on invalid document', $duplicate['variants'][0]['published_price'], null);

$damagedCounterpart = adminVariantListFetch($pdo, 5);
expectValue('damaged relational identity stays unresolved', $damagedCounterpart['variants'][0]['diagnostic_code'], 'identity_mismatch');
expectValue('damaged relational identity price closed', $damagedCounterpart['variants'][0]['published_price'], null);
expectValue('damaged counterpart is not orphan', $damagedCounterpart['legacy_orphans'], []);
expectValue('damaged counterpart is unresolved', count($damagedCounterpart['legacy_unresolved']), 1);
expectValue('damaged counterpart diagnostic', $damagedCounterpart['legacy_unresolved'][0]['diagnostic_code'], 'counterpart_identity_mismatch');
expectValue('damaged counterpart price closed', $damagedCounterpart['legacy_unresolved'][0]['published_price'], null);

$invalidNeighbor = adminVariantListFetch($pdo, 6);
expectValue('invalid neighbor document diagnostic', $invalidNeighbor['diagnostics'], ['invalid_published_price']);
expectValue('invalid neighbor creates no proven orphan', $invalidNeighbor['legacy_orphans'], []);
expectValue('invalid neighbor closes matching variant', $invalidNeighbor['variants'][0]['identity_ready'], false);
expectValue('invalid neighbor does not mix price', $invalidNeighbor['variants'][0]['published_price'], null);
expectValue('invalid neighbor unresolved prices closed', $invalidNeighbor['legacy_unresolved'][1]['published_price'], null);

$invalidJson = adminVariantListFetch($pdo, 7);
expectValue('globally invalid JSON diagnostic', $invalidJson['diagnostics'], ['invalid_legacy_json']);
expectValue('globally invalid JSON has no orphan prices', $invalidJson['legacy_orphans'], []);
expectValue('globally invalid JSON has no fabricated entries', $invalidJson['legacy_unresolved'], []);

$inactive = adminVariantListFetch($pdo, 8)['variants'][0];
expectValue('inactive exact retained price', $inactive['published_price'], 280000);
expectValue('inactive exact retained old price', $inactive['old_price'], 300000);
expectValue('inactive exact identity ready', $inactive['identity_ready'], true);
expectValue('inactive exact relational state', $inactive['relational_is_active'], false);

$statusMismatch = adminVariantListFetch($pdo, 9)['variants'][0];
expectValue('status mismatch identity closed', $statusMismatch['identity_ready'], false);
expectValue('status mismatch price closed', $statusMismatch['published_price'], null);
expectValue('status mismatch old price closed', $statusMismatch['old_price'], null);

$keyMismatch = adminVariantListFetch($pdo, 10);
expectValue('key-only mismatch keeps resolver diagnostic', $keyMismatch['variants'][0]['diagnostic_code'], 'identity_mismatch');
expectValue('key-only mismatch price closed', $keyMismatch['variants'][0]['published_price'], null);
expectValue('key-only mismatch identity closed', $keyMismatch['variants'][0]['identity_ready'], false);
expectValue('key-only mismatch is not orphan', $keyMismatch['legacy_orphans'], []);

expectThrows('PDO exception propagates', static fn(): ?array => adminVariantListFetch(new AdminVariantThrowingPdo([], []), 1), PDOException::class);

expectValue('valid product id', adminVariantListProductId('1'), 1);
foreach ([null,1,'','0','-1','01','1.0','abc','9223372036854775808'] as $invalidId) {
    expectValue('invalid product id ' . var_export($invalidId, true), adminVariantListProductId($invalidId), null);
}
expectValue('missing product', adminVariantListFetch($pdo, 404), null);
foreach ($pdo->queries as $query) {
    if (preg_match('/\A\s*SELECT\b/i', $query) !== 1) throw new RuntimeException('FAIL non-read-only SQL in service');
}
echo "PASS service SQL is SELECT-only\n";
