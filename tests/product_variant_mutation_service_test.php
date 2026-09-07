<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/product_variant_mutation_service.php';

final class VariantMutationTransactionPdo extends PDO
{
    public array $events=[];
    private bool $active=false;
    public function __construct() {}
    public function beginTransaction(): bool { $this->active=true; $this->events[]='begin'; return true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool { $this->active=false; $this->events[]='commit'; return true; }
    public function rollBack(): bool { $this->active=false; $this->events[]='rollback'; return true; }
}

function variantMutationExpect(string $name,mixed $actual,mixed $expected): void
{
    if($actual!==$expected) throw new RuntimeException("FAIL $name: ".var_export($actual,true));
    echo "PASS $name\n";
}
function variantMutationLockError(int $driverCode): PDOException
{
    $error=new PDOException('fixture lock conflict');
    $error->errorInfo=['40001',$driverCode,'fixture'];
    return $error;
}

variantMutationExpect('integer id',productVariantMutationPositiveId(5),5);
variantMutationExpect('string id',productVariantMutationPositiveId('5'),5);
foreach([null,0,-1,'','0','01','1.0',true] as $invalid) variantMutationExpect('invalid id '.var_export($invalid,true),productVariantMutationPositiveId($invalid),null);

$pdo=new VariantMutationTransactionPdo();$attempts=0;
$result=productVariantMutationRun($pdo,static function()use(&$attempts):string{
    $attempts++;if($attempts===1)throw variantMutationLockError(1213);return 'ok';
});
variantMutationExpect('1213 retries whole operation',$result,'ok');
variantMutationExpect('1213 rollback then commit',$pdo->events,['begin','rollback','begin','commit']);

$pdo=new VariantMutationTransactionPdo();$attempts=0;
try{productVariantMutationRun($pdo,static function()use(&$attempts):void{$attempts++;throw variantMutationLockError(1205);});$status=200;}
catch(ProductVariantMutationException $error){$status=$error->httpStatus;}
variantMutationExpect('repeated 1205 becomes conflict',$status,409);
variantMutationExpect('1205 attempts are bounded',$attempts,2);

$pdo=new VariantMutationTransactionPdo();
try{productVariantMutationRun($pdo,static function():void{throw new RuntimeException('programming error');});$class='none';}
catch(Throwable $error){$class=$error::class;}
variantMutationExpect('programming error is not retried',$class,RuntimeException::class);
variantMutationExpect('programming error rolls back',$pdo->events,['begin','rollback']);
echo "PASS variant mutation unit fixtures\n";
