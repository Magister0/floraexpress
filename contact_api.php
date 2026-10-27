<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/bootstrap.php';
$db = db();

function fail(int $code,string $msg){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg],JSON_UNESCAPED_UNICODE); exit; }
function ok(array $p=[]){ echo json_encode(['ok'=>true]+$p,JSON_UNESCAPED_UNICODE); exit; }

try{
  $db->exec("CREATE TABLE IF NOT EXISTS contact_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT NOT NULL,
    email TEXT,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL
  )");
}catch(Throwable $e){ fail(500,'db_init'); }

$name = trim((string)($_POST['name'] ?? ''));
$phone= trim((string)($_POST['phone'] ?? ''));
$email= trim((string)($_POST['email'] ?? ''));
$msg  = trim((string)($_POST['message'] ?? ''));

if ($name==='' || $phone==='' || $msg==='') fail(400,'Заполните обязательные поля');
if (mb_strlen($name) < 2) fail(400, 'Имя слишком короткое');
$digits = preg_replace('~\D+~', '', $phone);
if (strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
if (strlen($digits) !== 11 || $digits[0] !== '7') fail(400, 'Телефон указан неверно');
$phone = '+7 (' . substr($digits,1,3) . ') ' . substr($digits,4,3) . '-' . substr($digits,7,2) . '-' . substr($digits,9,2);
if (mb_strlen($msg) < 5) fail(400, 'Сообщение слишком короткое');

try{
  $st=$db->prepare("INSERT INTO contact_messages(name,phone,email,message,created_at) VALUES(?,?,?,?,datetime('now'))");
  $st->execute([$name,$phone,$email,$msg]);
  ok();
}catch(Throwable $e){ fail(500,'db'); }
