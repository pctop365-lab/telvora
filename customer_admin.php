<?php
declare(strict_types=1);
// Included exclusively AFTER the existing manager.php administrator guard.
if (!defined('TELVORA_MANAGER_REQUEST') || empty($_SESSION['telvora_admin'])) { http_response_code(403); exit; }
require_once __DIR__.'/customer_service.php';
header('Cache-Control: no-store');
try {
    $page=max(1,min(100000,(int)($_GET['page'] ?? 1)));
    if ($action==='customers_list') {
        $q=customerText($_GET,'q',100);
        $search='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';
        $where="login LIKE ? ESCAPE '!' OR full_name LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!'";
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM customers WHERE $where");$stmt->execute([$search,$search,$search]);$total=(int)$stmt->fetchColumn();
        $offset=($page-1)*20;
        $stmt=$pdo->prepare("SELECT id,login,full_name,phone,email,address,created_at FROM customers WHERE $where ORDER BY id DESC LIMIT 20 OFFSET $offset");$stmt->execute([$search,$search,$search]);
        $result=['customers'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page];
    } else {
        $id=(int)($_GET['id'] ?? 0);
        $stmt=$pdo->prepare('SELECT id,login,full_name,phone,email,email_verified_at,address,created_at FROM customers WHERE id=?');$stmt->execute([$id]);$customer=$stmt->fetch();
        if(!$customer)throw new CustomerError('Клиент не найден.',404);
        $result=['customer'=>$customer]+customerOrders($pdo,$id,$page);
    }
    echo json_encode(['success'=>true]+$result,JSON_UNESCAPED_UNICODE);
} catch(CustomerError $error) { http_response_code($error->status);echo json_encode(['success'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE); }
catch(Throwable) { http_response_code(503);echo json_encode(['success'=>false,'message'=>'Не удалось загрузить клиентов.'],JSON_UNESCAPED_UNICODE); }
exit;
