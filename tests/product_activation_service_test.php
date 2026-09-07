<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/product_activation_service.php';

final class ProductActivationFixtureStatement extends PDOStatement
{
    private array $rows = [];
    private mixed $column = false;

    public function __construct(private readonly ProductActivationFixturePdo $fixture, private readonly string $sql) {}

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        if (str_contains($this->sql, 'FROM product_variant_price_overrides')) {
            $this->rows = array_values(array_filter($this->fixture->overrides, static fn(array $row): bool => in_array((int)$row['product_variant_id'], array_map('intval', $params), true)));
        } elseif (str_contains($this->sql, 'FROM product_variants')) {
            if (str_contains($this->sql, 'FOR UPDATE')) {
                $this->fixture->events[] = 'lock_candidate';
                $this->fixture->runBeforeCandidateLock();
                $candidate = null;
                foreach ($this->fixture->variants as $variant) {
                    if ((int)$variant['id'] === (int)$params[':id']) $candidate = $variant;
                }
                $this->rows = $candidate === null ? [] : [$candidate];
            } else {
                $this->fixture->events[] = 'preflight_variants';
                $productId = (int)$params[':product_id'];
                $this->rows = array_values(array_filter(
                    $this->fixture->variants,
                    static fn(array $variant): bool =>
                        (int)$variant['product_id'] === $productId && (int)$variant['is_active'] === 1
                ));
            }
        } elseif (str_contains($this->sql, 'FROM products')) {
            $locked = str_contains($this->sql, 'FOR UPDATE');
            $this->fixture->events[] = $locked ? 'lock_product' : 'preflight_product';
            if ($locked) {
                $this->fixture->runBeforeProductLock();
                if ($this->fixture->lockFailures > 0) {
                    $this->fixture->lockFailures--;
                    $error = new PDOException('fixture lock conflict');
                    $error->errorInfo = ['40001', $this->fixture->lockFailureCode, 'fixture'];
                    throw $error;
                }
            }
            $product = $this->fixture->products[(int)$params[':id']] ?? null;
            $this->rows = $product === null ? [] : [$product];
        } elseif (str_contains($this->sql, 'WEIGHT_STRING')) {
            $this->column = bin2hex(mb_strtolower((string)$params[':value'], 'UTF-8'));
            $this->rows = [];
        } else {
            throw new RuntimeException('Unexpected fixture SQL');
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return $this->column; }
}

final class ProductActivationFixturePdo extends PDO
{
    public array $events = [];
    public int $lockFailures = 0;
    public int $lockFailureCode = 1213;
    private bool $transaction = false;
    private array $snapshot = [];
    private $beforeCandidateLock = null;
    private $beforeProductLock = null;

    public function __construct(public array $products, public array $variants, public array $overrides = []) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ProductActivationFixtureStatement($this, $query);
    }
    public function beginTransaction(): bool
    {
        $this->transaction = true;
        $this->snapshot = [$this->products, $this->variants];
        $this->events[] = 'begin';
        return true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool
    {
        $this->events[] = 'commit';
        $this->transaction = false;
        return true;
    }
    public function rollBack(): bool
    {
        $this->events[] = 'rollback';
        [$this->products, $this->variants] = $this->snapshot;
        $this->transaction = false;
        return true;
    }
    public function beforeCandidateLock(callable $callback): void { $this->beforeCandidateLock = $callback; }
    public function beforeProductLock(callable $callback): void { $this->beforeProductLock = $callback; }
    public function externalProductActive(int $productId, bool $active): void
    {
        $this->products[$productId]['is_active'] = $active ? 1 : 0;
        if ($this->transaction) $this->snapshot[0][$productId]['is_active'] = $active ? 1 : 0;
    }
    public function externalVariantActive(int $index, bool $active): void
    {
        $this->variants[$index]['is_active'] = $active ? 1 : 0;
        if ($this->transaction) $this->snapshot[1][$index]['is_active'] = $active ? 1 : 0;
    }
    public function runBeforeCandidateLock(): void
    {
        if ($this->beforeCandidateLock !== null) {
            $callback = $this->beforeCandidateLock;
            $this->beforeCandidateLock = null;
            $callback($this);
        }
    }
    public function runBeforeProductLock(): void
    {
        if ($this->beforeProductLock !== null) {
            $callback = $this->beforeProductLock;
            $this->beforeProductLock = null;
            $callback($this);
        }
    }
}

function activationLegacy(string $country, int $price, bool $active = true): array
{
    return ['country'=>$country, 'price'=>$price, 'old_price'=>null, 'is_active'=>$active];
}
function activationVariant(int $id, int $productId, string $country, bool $active = true, ?string $key = null): array
{
    return ['id'=>$id, 'product_id'=>$productId,
        'variant_key'=>$key ?? 'legacy-country-sha256-' . hash('sha256', $country),
        'assembly_country'=>$country, 'display_name'=>$country, 'is_active'=>$active ? 1 : 0];
}
function activationPdo(array $legacy, array $variants, bool $productActive = false, array $overrides = []): ProductActivationFixturePdo
{
    return new ProductActivationFixturePdo(
        [5 => ['id'=>5, 'is_active'=>$productActive ? 1 : 0, 'variants'=>json_encode($legacy, JSON_UNESCAPED_UNICODE)]],
        $variants,
        $overrides
    );
}
function activationAttempt(ProductActivationFixturePdo $pdo): void
{
    productActivationRun($pdo, 5, static function () use ($pdo): void {
        $pdo->products[5]['is_active'] = 1;
    });
}
function activationExpect(string $name, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) throw new RuntimeException("FAIL $name: " . var_export($actual, true));
    echo "PASS $name\n";
}
function activationExpectConflict(string $name, ProductActivationFixturePdo $pdo): void
{
    try { activationAttempt($pdo); }
    catch (ProductActivationException $error) {
        activationExpect($name, $error->httpStatus, 409);
        activationExpect("$name remains hidden", $pdo->products[5]['is_active'], 0);
        return;
    }
    throw new RuntimeException("FAIL $name: conflict was not raised");
}

$lg = activationPdo(
    [activationLegacy('Russia',271400), activationLegacy('Poland',0), activationLegacy('China',0)],
    [activationVariant(5,5,'Russia'), activationVariant(6,5,'Poland'), activationVariant(7,5,'China')]
);
activationAttempt($lg);
activationExpect('LG-like activation succeeds', $lg->products[5]['is_active'], 1);
activationExpect('candidate lock precedes product lock', array_slice($lg->events, 2, 3), ['begin','lock_candidate','lock_product']);

$manualDraft = activationPdo([activationLegacy('Russia',0)], [activationVariant(5,5,'Russia')], false, [['product_variant_id'=>5,'manual_price'=>'271400.00','manual_old_price'=>null,'is_active'=>1,'updated_at'=>'2026-09-08 00:00:00']]);
activationAttempt($manualDraft);
activationExpect('manual positive overlay makes canonical draft ready', $manualDraft->products[5]['is_active'], 1);

$zeros = activationPdo([activationLegacy('Russia',0)], [activationVariant(5,5,'Russia')]);
activationExpectConflict('all-zero variants conflict', $zeros);

$inactive = activationPdo([activationLegacy('Russia',271400,false)], [activationVariant(5,5,'Russia')]);
activationExpectConflict('inactive legacy variant conflict', $inactive);
$damaged = activationPdo([activationLegacy('Russia',271400)], [activationVariant(5,5,'Russia',true,'wrong-key')]);
activationExpectConflict('damaged identity conflict', $damaged);

$concurrentDisable = activationPdo([activationLegacy('Russia',271400)], [activationVariant(5,5,'Russia')]);
$concurrentDisable->beforeCandidateLock(static function (ProductActivationFixturePdo $pdo): void {
    $pdo->externalVariantActive(0, false);
});
activationExpectConflict('concurrent last candidate disable conflict', $concurrentDisable);

$stage9 = activationPdo([activationLegacy('Russia',260000)], [activationVariant(5,5,'Russia')]);
$stage9->beforeCandidateLock(static function (ProductActivationFixturePdo $pdo): void {
    $legacy = json_decode($pdo->products[5]['variants'], true, 512, JSON_THROW_ON_ERROR);
    $legacy[0]['price'] = 271400;
    $pdo->products[5]['variants'] = json_encode($legacy, JSON_UNESCAPED_UNICODE);
});
activationAttempt($stage9);
activationExpect('concurrent Stage9 snapshot is preserved', json_decode($stage9->products[5]['variants'], true)[0]['price'], 271400);

$candidateChange = activationPdo([activationLegacy('Russia',271400)], [activationVariant(5,5,'Russia')]);
$candidateChange->beforeCandidateLock(static function (ProductActivationFixturePdo $pdo): void {
    $pdo->externalVariantActive(0, false);
});
activationExpectConflict('candidate change before lock conflicts safely', $candidateChange);

$alreadyActive = activationPdo([activationLegacy('Russia',0)], [], true);
activationAttempt($alreadyActive);
activationExpect('already-active edit does not re-run readiness guard', $alreadyActive->products[5]['is_active'], 1);
$becameHidden = activationPdo([activationLegacy('Russia',0)], [], true);
$becameHidden->beforeProductLock(static function (ProductActivationFixturePdo $pdo): void {
    $pdo->externalProductActive(5, false);
});
activationExpectConflict('actual active state is re-read under lock', $becameHidden);
$deactivation = activationPdo([activationLegacy('Russia',0)], [], true);
$deactivation->products[5]['is_active'] = 0;
activationExpect('deactivation remains available without activation guard', $deactivation->products[5]['is_active'], 0);

$rollback = activationPdo([activationLegacy('Russia',271400)], [activationVariant(5,5,'Russia')]);
$rollback->lockFailures = 1;
$rollback->lockFailureCode = 9999;
try { activationAttempt($rollback); } catch (PDOException) {}
activationExpect('exception rolls transaction back', $rollback->events[array_key_last($rollback->events)], 'rollback');
activationExpect('rollback leaves product hidden', $rollback->products[5]['is_active'], 0);

$deadlockRetry = activationPdo([activationLegacy('Russia',271400)], [activationVariant(5,5,'Russia')]);
$deadlockRetry->lockFailures = 1;
activationAttempt($deadlockRetry);
activationExpect('deadlock retries once', count(array_filter($deadlockRetry->events, static fn(string $event): bool => $event === 'begin')), 2);
activationExpect('deadlock retry activates', $deadlockRetry->products[5]['is_active'], 1);

$timeout = activationPdo([activationLegacy('Russia',271400)], [activationVariant(5,5,'Russia')]);
$timeout->lockFailures = 2;
$timeout->lockFailureCode = 1205;
activationExpectConflict('lock timeout becomes conflict after bounded retry', $timeout);

echo "PASS activation concurrency model fixtures\n";
