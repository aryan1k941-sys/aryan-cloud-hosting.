<?php
declare(strict_types=1);
session_start();

/*
 * Aryan Cloud Hosting - Render-friendly PHP control panel.
 * Demo mode: uploads and bot records work; execution requires a separately
 * secured runner. Never execute arbitrary customer ZIP files in the web process.
 */
const DB_DIR = __DIR__ . '/data';
const DB_FILE = DB_DIR . '/app.db';
const UPLOAD_DIR = __DIR__ . '/uploads';
const ADMIN_EMAIL = 'armanmalikh762@gmail.com';
const ADMIN_PASSWORD = 'aryanyt321';

foreach ([DB_DIR, UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
}
if (!is_writable(DB_DIR) || !is_writable(UPLOAD_DIR)) {
    http_response_code(500); exit('Storage is not writable. Check Render disk/storage configuration.');
}
$pdo = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
 password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'user', balance REAL NOT NULL DEFAULT 0,
 status TEXT NOT NULL DEFAULT 'active', referral_code TEXT NOT NULL, created_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS plans (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, price REAL NOT NULL,
 days INTEGER NOT NULL, bot_limit INTEGER NOT NULL, ram_mb INTEGER NOT NULL,
 storage_mb INTEGER NOT NULL, features TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS orders (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, plan_id INTEGER NOT NULL,
 amount REAL NOT NULL, status TEXT NOT NULL DEFAULT 'pending', txn_id TEXT,
 sgvip_order_id TEXT, reference TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(plan_id) REFERENCES plans(id)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS bots (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL,
 file TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'uploaded', env TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, subject TEXT NOT NULL,
 message TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open', reply TEXT,
 created_at TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)
)");
$defaults = [
 ['Free',0,7,1,128,256,'7-day demo plan'],
 ['Basic',99,30,2,256,512,'Starter plan'],
 ['Pro',299,30,5,512,1024,'Advanced plan'],
 ['Premium',799,90,10,1024,2048,'Premium plan']
];
if ((int)$pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn() === 0) {
    $st=$pdo->prepare('INSERT INTO plans(name,price,days,bot_limit,ram_mb,storage_mb,features,active) VALUES(?,?,?,?,?,?,?,1)');
    foreach($defaults as $p) $st->execute($p);
}
$adminCheck=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');
$adminCheck->execute([ADMIN_EMAIL]);
if ((int)$adminCheck->fetchColumn() === 0) {
    $st=$pdo->prepare("INSERT OR IGNORE INTO users(name,email,password,role,balance,status,referral_code,created_at) VALUES(?,?,?,'admin',0,'active',?,?)");
    $st->execute(['Administrator',ADMIN_EMAIL,password_hash(ADMIN_PASSWORD,PASSWORD_DEFAULT),'ARYANADMIN',date('c')]);
}
$initialSettings = [
 'site_name'=>'Aryan Cloud Hosting','currency'=>'INR','maintenance'=>'0',
 'sgvip_enabled'=>'0','sgvip_api_key'=>'','sgvip_secret'=>'','sgvip_webhook_sec'=>'',
 'sgvip_base_url'=>'https://sgvip.site','runner_url'=>'','runner_token'=>''
];
$ins=$pdo->prepare('INSERT OR IGNORE INTO settings(k,v) VALUES(?,?)');
foreach($initialSettings as $k=>$v) $ins->execute([$k,$v]);
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function setting(PDO $db,string $key,string $default=''): string {
    $s=$db->prepare('SELECT v FROM settings WHERE k=?'); $s->execute([$key]);
    $v=$s->fetchColumn(); return $v===false?$default:(string)$v;
}
function csrf(): string {
    if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    if($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')) {
        http_response_code(403); exit('Invalid CSRF token. Please go back and retry.');
    }
}
function user(PDO $db): ?array {
    if(empty($_SESSION['uid'])) return null;
    $s=$db->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$_SESSION['uid']]);
    $u=$s->fetch(); return $u?:null;
}
function go(string $url): void { header('Location: '.$url); exit; }
function notice(string $msg): void { $_SESSION['flash']=$msg; }
function flash(): string { $m=$_SESSION['flash']??''; unset($_SESSION['flash']); return $m; }
function require_login(PDO $db): array { $u=user($db); if(!$u) go('?a=login'); return $u; }
function require_admin(PDO $db): array { $u=require_login($db); if($u['role']!=='admin') { http_response_code(403); exit('Admin access required'); } return $u; }

verify_csrf();
$action=$_GET['a']??'home';
$me=user($pdo);

if($action==='logout') { session_destroy(); go('?a=home'); }

if($_SERVER['REQUEST_METHOD']==='POST') {
    if($action==='register') {
        $name=trim($_POST['name']??''); $email=strtolower(trim($_POST['email']??'')); $pass=$_POST['password']??'';
        if($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<8) { notice('Valid name/email and password of at least 8 characters required.'); go('?a=register'); }
        try {
            $ref=strtoupper(bin2hex(random_bytes(4)));
            $s=$pdo->prepare("INSERT INTO users(name,email,password,referral_code,created_at) VALUES(?,?,?,?,?)");
            $s->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$ref,date('c')]);
            $_SESSION['uid']=(int)$pdo->lastInsertId(); go('?a=dashboard');
        } catch(Throwable $e) { notice('Email already registered or registration failed.'); go('?a=register'); }
    }
    if($action==='login') {
        $s=$pdo->prepare('SELECT * FROM users WHERE email=?'); $s->execute([strtolower(trim($_POST['email']??''))]); $u=$s->fetch();
        if($u && $u['status']==='active' && password_verify($_POST['password']??'', $u['password'])) {
            session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; go($u['role']==='admin'?'?a=admin':'?a=dashboard');
        }
        notice('Login failed. Check your credentials or account status.'); go('?a=login');
    }
    if($action==='upload') {
        $u=require_login($pdo);
        if(empty($_FILES['botzip']) || $_FILES['botzip']['error']!==UPLOAD_ERR_OK) { notice('ZIP upload failed.'); go('?a=dashboard'); }
        $f=$_FILES['botzip'];
        if($f['size']>20*1024*1024 || strtolower(pathinfo($f['name'],PATHINFO_EXTENSION))!=='zip') { notice('Only ZIP files up to 20 MB are allowed.'); go('?a=dashboard'); }
        $count=$pdo->prepare('SELECT COUNT(*) FROM bots WHERE user_id=?'); $count->execute([$u['id']]);
        $plan=$pdo->prepare("SELECT bot_limit FROM plans WHERE id=(SELECT plan_id FROM orders WHERE user_id=? AND status='paid' ORDER BY id DESC LIMIT 1)");
        $plan->execute([$u['id']]); $limit=(int)$plan->fetchColumn(); if($limit<1)$limit=1;
        if((int)$count->fetchColumn()>=$limit) { notice('Bot limit reached for your current account.'); go('?a=dashboard'); }
        $zip=new ZipArchive();
        if($zip->open($f['tmp_name'])!==true) { notice('Invalid ZIP archive.'); go('?a=dashboard'); }
        $hasMain=false; $bad=false;
        for($i=0;$i<$zip->numFiles;$i++) {
            $n=$zip->getNameIndex($i);
            if(strpos($n,"\0")!==false || preg_match('~(^/|(^|/)\.\.(/|$)|^[A-Za-z]:)~',$n)) { $bad=true; break; }
            if(basename($n)==='main.py') $hasMain=true;
        }
        $zip->close();
        if($bad || !$hasMain) { notice('ZIP must be safe and contain a main.py file.'); go('?a=dashboard'); }
        $safe='u'.$u['id'].'_'.bin2hex(random_bytes(8)).'.zip';
        if(!move_uploaded_file($f['tmp_name'],UPLOAD_DIR.'/'.$safe)) { notice('Could not save uploaded ZIP.'); go('?a=dashboard'); }
        $name=trim($_POST['bot_name']??pathinfo($f['name'],PATHINFO_FILENAME)); if($name==='')$name='My Python Bot';
        $s=$pdo->prepare("INSERT INTO bots(user_id,name,file,status,created_at) VALUES(?,?,?,'uploaded',?)");
        $s->execute([$u['id'],substr($name,0,80),$safe,date('c')]);
        notice('ZIP uploaded successfully. Bot is saved; connect a separate isolated runner to execute it.'); go('?a=dashboard');
    }
    if($action==='admin' ) {
        $admin=require_admin($pdo); $tab=$_POST['tab']??'';
        if($tab==='gateway' || $tab==='settings') {
            $keys=$tab==='gateway'?['sgvip_enabled','sgvip_api_key','sgvip_secret','sgvip_webhook_sec','sgvip_base_url','runner_url','runner_token']:['site_name','currency','maintenance'];
            $s=$pdo->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v');
            foreach($keys as $k) $s->execute([$k,trim((string)($_POST[$k]??''))]);
            notice('Settings saved.'); go('?a=admin&tab='.$tab);
        }
        if($tab==='addplan') {
            $s=$pdo->prepare('INSERT INTO plans(name,price,days,bot_limit,ram_mb,storage_mb,features,active) VALUES(?,?,?,?,?,?,?,1)');
            $s->execute([trim($_POST['name']??'New Plan'),max(0,(float)($_POST['price']??0)),max(1,(int)($_POST['days']??30)),max(1,(int)($_POST['bot_limit']??1)),max(64,(int)($_POST['ram_mb']??128)),max(64,(int)($_POST['storage_mb']??256)),trim($_POST['features']??'')]);
            notice('Plan added.'); go('?a=admin&tab=plans');
        }
        if($tab==='deleteplan') {
            $s=$pdo->prepare('DELETE FROM plans WHERE id=?'); $s->execute([(int)($_POST['plan_id']??0)]);
            notice('Plan deleted.'); go('?a=admin&tab=plans');
        }
        if($tab==='userstatus') {
            $s=$pdo->prepare('UPDATE users SET status=? WHERE id=?');
            $s->execute([($_POST['status']??'active')==='suspended'?'suspended':'active',(int)($_POST['user_id']??0)]);
            notice('User status updated.'); go('?a=admin&tab=users');
        }
    }
    if($action==='buy') {
        $u=require_login($pdo); $pid=(int)($_POST['plan_id']??0);
        $s=$pdo->prepare('SELECT * FROM plans WHERE id=? AND active=1'); $s->execute([$pid]); $p=$s->fetch();
        if(!$p) { notice('Plan not found.'); go('?a=plans'); }
        if((float)$p['price']===0.0) {
            $ref='FREE-'.bin2hex(random_bytes(6));
            $s=$pdo->prepare("INSERT INTO orders(user_id,plan_id,amount,status,reference,created_at) VALUES(?,?,0,'paid',?,?)");
            $s->execute([$u['id'],$pid,$ref,date('c')]); notice('Free plan activated.'); go('?a=dashboard');
        }
        if(setting($pdo,'sgvip_enabled')!=='1' || setting($pdo,'sgvip_api_key')==='') { notice('Payment gateway is not configured by admin.'); go('?a=plans'); }
        // Payment initiation intentionally fails closed until the merchant API contract is verified.
        notice('Gateway is configured, but checkout is disabled until SGVIP merchant credentials and API response are verified.'); go('?a=plans');
    }
}

$me=user($pdo); $site=setting($pdo,'site_name','Aryan Cloud Hosting');
function header_html(string $title,string $site,?array $me): void { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> - <?=h($site)?></title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-900 text-slate-200 min-h-screen"><nav class="border-b border-slate-700 bg-slate-950"><div class="max-w-6xl mx-auto px-4 py-4 flex flex-wrap gap-4 items-center justify-between"><a class="font-bold text-xl text-white" href="?a=home"><?=h($site)?></a><div class="flex gap-4 text-sm items-center"><a href="?a=plans" class="hover:text-indigo-300">Plans</a><?php if($me): ?><a href="?a=dashboard">Dashboard</a><?php if($me['role']==='admin'):?><a href="?a=admin">Admin</a><?php endif;?><a href="?a=logout">Logout</a><?php else:?><a href="?a=login">Login</a><a class="bg-indigo-600 px-3 py-2 rounded-lg" href="?a=register">Sign up</a><?php endif;?></div></div></nav>
<main class="max-w-6xl mx-auto p-4 md:p-8"><?php $msg=flash(); if($msg):?><div class="mb-5 rounded-lg bg-indigo-900/60 border border-indigo-700 p-3"><?=h($msg)?></div><?php endif; ?>
<?php }
function footer_html(): void { echo '</main><footer class="text-center text-slate-500 text-xs py-8">Aryan Cloud Hosting · Secure demo control panel</footer></body></html>'; }
function field(string $label,string $name,string $value='',string $type='text'): void { ?>
<label class="block text-sm mb-3"><?=h($label)?><input class="mt-1 w-full rounded-lg bg-slate-900 border border-slate-700 p-3 text-white" type="<?=h($type)?>" name="<?=h($name)?>" value="<?=h($value)?>"></label>
<?php }

header_html($site.' · '.$action,$site,$me);
if($action==='home') { ?>
<section class="py-16 md:py-24 text-center"><div class="inline-flex rounded-full bg-indigo-900/60 text-indigo-200 px-4 py-2 text-sm mb-6">Python Bot Control Panel</div><h1 class="text-4xl md:text-6xl font-extrabold text-white max-w-4xl mx-auto">Host and manage your Telegram bots</h1><p class="text-slate-400 mt-5 max-w-2xl mx-auto">Upload Python bot ZIP projects, manage plans and view your hosting dashboard from one place.</p><div class="mt-8 flex justify-center gap-3"><a href="?a=plans" class="bg-indigo-600 hover:bg-indigo-500 px-6 py-3 rounded-xl font-semibold">View Plans</a><a href="?a=register" class="border border-slate-600 px-6 py-3 rounded-xl">Create account</a></div></section>
<div class="grid md:grid-cols-3 gap-4"><?php foreach([['Upload ZIP','Store Python bot projects securely'],['Bot dashboard','Track uploaded projects and statuses'],['Admin tools','Manage users, plans and settings']] as $x):?><div class="bg-slate-800 p-6 rounded-xl"><h3 class="font-bold text-white"><?=h($x[0])?></h3><p class="text-slate-400 text-sm mt-2"><?=h($x[1])?></p></div><?php endforeach;?></div>
<?php } elseif($action==='register' || $action==='login') { ?>
<div class="max-w-md mx-auto bg-slate-800 p-6 rounded-2xl"><h1 class="text-2xl font-bold mb-5"><?= $action==='register'?'Create account':'Welcome back' ?></h1><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><?php if($action==='register')field('Name','name');field('Email','email','','email');field('Password (minimum 8 characters)','password','','password');?><button class="w-full bg-indigo-600 rounded-lg p-3 font-semibold"><?= $action==='register'?'Sign up':'Login' ?></button></form></div>
<?php } elseif($action==='plans') { $plans=$pdo->query('SELECT * FROM plans WHERE active=1 ORDER BY price')->fetchAll(); ?>
<h1 class="text-3xl font-bold text-white mb-2">Hosting Plans</h1><p class="text-slate-400 mb-6">Choose a plan for your bot projects.</p><div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4"><?php foreach($plans as $p):?><div class="bg-slate-800 rounded-xl p-5 border border-slate-700"><h2 class="font-bold text-xl"><?=h($p['name'])?></h2><p class="text-3xl font-extrabold my-4">₹<?=h(number_format((float)$p['price'],0))?></p><ul class="text-sm text-slate-400 space-y-2"><li><?=h($p['days'])?> days</li><li><?=h($p['bot_limit'])?> bots</li><li><?=h($p['ram_mb'])?> MB RAM allocation*</li><li><?=h($p['storage_mb'])?> MB storage quota*</li></ul><form method="post" action="?a=buy" class="mt-5"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="plan_id" value="<?=h($p['id'])?>"><button class="w-full bg-indigo-600 p-2 rounded-lg">Choose plan</button></form><p class="text-xs text-slate-500 mt-3">*Plan limits are display values in demo mode.</p></div><?php endforeach;?></div>
<?php } elseif($action==='dashboard') { $u=require_login($pdo); $s=$pdo->prepare('SELECT b.* FROM bots b WHERE user_id=? ORDER BY id DESC');$s->execute([$u['id']]);$bots=$s->fetchAll();$s=$pdo->prepare('SELECT o.*,p.name plan FROM orders o JOIN plans p ON p.id=o.plan_id WHERE o.user_id=? ORDER BY o.id DESC');$s->execute([$u['id']]);$orders=$s->fetchAll(); ?>
<div class="flex flex-wrap justify-between gap-3 items-center"><div><h1 class="text-3xl font-bold">Welcome, <?=h($u['name'])?></h1><p class="text-slate-400">Your hosting dashboard</p></div><div class="bg-slate-800 p-4 rounded-xl">Referral: <b><?=h($u['referral_code'])?></b></div></div>
<div class="grid md:grid-cols-3 gap-4 my-6"><div class="bg-slate-800 p-5 rounded-xl"><p class="text-slate-400 text-sm">Balance</p><p class="text-2xl font-bold">₹<?=h(number_format((float)$u['balance'],2))?></p></div><div class="bg-slate-800 p-5 rounded-xl"><p class="text-slate-400 text-sm">My bots</p><p class="text-2xl font-bold"><?=count($bots)?></p></div><div class="bg-slate-800 p-5 rounded-xl"><p class="text-slate-400 text-sm">Account</p><p class="text-2xl font-bold"><?=h(ucfirst($u['status']))?></p></div></div>
<div class="bg-slate-800 rounded-xl p-5 mb-6"><h2 class="font-bold text-xl mb-4">Upload Python Bot ZIP</h2><form method="post" action="?a=upload" enctype="multipart/form-data" class="grid md:grid-cols-3 gap-3 items-end"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label class="text-sm">Bot name<input name="bot_name" class="block mt-1 w-full bg-slate-900 border border-slate-700 rounded-lg p-3"></label><label class="text-sm">Project ZIP (max 20 MB)<input required type="file" name="botzip" accept=".zip" class="block mt-2 w-full text-sm"></label><button class="bg-indigo-600 rounded-lg p-3">Upload ZIP</button></form><p class="text-xs text-amber-300 mt-3">Demo limitation: ZIP files are stored, not executed. Connect a separately isolated runner before enabling Start/Stop for customer code.</p></div>
<div class="bg-slate-800 rounded-xl p-5 mb-6"><h2 class="font-bold text-xl mb-4">My Bots</h2><?php if(!$bots):?><p class="text-slate-400">No bots uploaded yet.</p><?php else:?><div class="overflow-x-auto"><table class="w-full text-sm"><tr class="text-slate-400 text-left"><th class="p-2">Name</th><th>File</th><th>Status</th><th>Created</th></tr><?php foreach($bots as $b):?><tr class="border-t border-slate-700"><td class="p-2"><?=h($b['name'])?></td><td><?=h($b['file'])?></td><td><span class="text-amber-300"><?=h($b['status'])?></span></td><td><?=h($b['created_at'])?></td></tr><?php endforeach;?></table></div><?php endif;?></div>
<div class="bg-slate-800 rounded-xl p-5"><h2 class="font-bold text-xl mb-4">Order History</h2><div class="overflow-x-auto"><table class="w-full text-sm"><tr class="text-slate-400 text-left"><th class="p-2">Reference</th><th>Plan</th><th>Amount</th><th>Status</th><th>Date</th></tr><?php foreach($orders as $o):?><tr class="border-t border-slate-700"><td class="p-2"><?=h($o['reference'])?></td><td><?=h($o['plan'])?></td><td>₹<?=h($o['amount'])?></td><td><?=h($o['status'])?></td><td><?=h($o['created_at'])?></td></tr><?php endforeach;?></table></div></div>
<?php } elseif($action==='admin') { $ad=require_admin($pdo);$tab=$_GET['tab']??'dashboard'; ?>
<h1 class="text-3xl font-bold mb-5">Admin Panel</h1><div class="flex flex-wrap gap-2 mb-6"><?php foreach(['dashboard'=>'Dashboard','gateway'=>'Gateway','plans'=>'Plans','users'=>'Users','orders'=>'Orders','settings'=>'Settings'] as $k=>$v):?><a class="px-3 py-2 rounded-lg <?= $tab===$k?'bg-indigo-600':'bg-slate-800'?>" href="?a=admin&tab=<?=$k?>"><?=h($v)?></a><?php endforeach;?></div>
<?php if($tab==='dashboard') { $stats=[$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),$pdo->query('SELECT COUNT(*) FROM bots')->fetchColumn(),$pdo->query("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid'")->fetchColumn()];?><div class="grid md:grid-cols-3 gap-4"><?php foreach(['Total users','Total bots','Paid revenue'] as $i=>$label):?><div class="bg-slate-800 p-6 rounded-xl"><p class="text-slate-400"><?=$label?></p><p class="text-3xl font-bold mt-2"><?=h($i===2?'₹'.number_format((float)$stats[$i],2):$stats[$i])?></p></div><?php endforeach;?></div>
<?php } elseif($tab==='gateway') { ?><div class="bg-slate-800 p-6 rounded-xl"><h2 class="text-xl font-bold mb-4">Gateway & Runner Settings</h2><form method="post" action="?a=admin"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="tab" value="gateway"><?php field('SGVIP enabled (1 or 0)','sgvip_enabled',setting($pdo,'sgvip_enabled'));field('SGVIP API key','sgvip_api_key',setting($pdo,'sgvip_api_key'),'password');field('SGVIP API secret','sgvip_secret',setting($pdo,'sgvip_secret'),'password');field('Webhook secret','sgvip_webhook_sec',setting($pdo,'sgvip_webhook_sec'),'password');field('SGVIP base URL','sgvip_base_url',setting($pdo,'sgvip_base_url'));field('Private Runner URL (optional)','runner_url',setting($pdo,'runner_url'));field('Runner API token','runner_token',setting($pdo,'runner_token'),'password');?><p class="text-xs text-slate-400 mb-4">Webhook URL: <?=h((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'your-domain').'/index.php?a=webhook')?>. Payment checkout remains disabled until the merchant API contract is tested.</p><button class="bg-indigo-600 p-3 rounded-lg">Save gateway settings</button></form></div>
<?php } elseif($tab==='plans') { $plans=$pdo->query('SELECT * FROM plans ORDER BY price')->fetchAll();?><div class="bg-slate-800 rounded-xl p-5 mb-5 overflow-x-auto"><h2 class="font-bold mb-3">All plans</h2><table class="w-full text-sm"><tr><th class="text-left p-2">Name</th><th>Price</th><th>Days</th><th>Bots</th><th></th></tr><?php foreach($plans as $p):?><tr class="border-t border-slate-700"><td class="p-2"><?=h($p['name'])?></td><td>₹<?=h($p['price'])?></td><td><?=h($p['days'])?></td><td><?=h($p['bot_limit'])?></td><td><form method="post" action="?a=admin"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="tab" value="deleteplan"><input type="hidden" name="plan_id" value="<?=h($p['id'])?>"><button class="text-red-300">Delete</button></form></td></tr><?php endforeach;?></table></div><div class="bg-slate-800 rounded-xl p-5"><h2 class="font-bold mb-3">Add plan</h2><form method="post" action="?a=admin" class="grid md:grid-cols-2 gap-3"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="tab" value="addplan"><?php field('Name','name');field('Price ₹','price','99','number');field('Days','days','30','number');field('Bot limit','bot_limit','2','number');field('RAM MB','ram_mb','256','number');field('Storage MB','storage_mb','512','number');field('Features','features');?><button class="bg-indigo-600 rounded-lg p-3">Add plan</button></form></div>
<?php } elseif($tab==='users') { $users=$pdo->query('SELECT * FROM users ORDER BY id DESC')->fetchAll();?><div class="bg-slate-800 p-5 rounded-xl overflow-x-auto"><table class="w-full text-sm"><tr class="text-left"><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Balance</th><th>Status</th><th>Action</th></tr><?php foreach($users as $x):?><tr class="border-t border-slate-700"><td class="p-2"><?=h($x['id'])?></td><td><?=h($x['name'])?></td><td><?=h($x['email'])?></td><td><?=h($x['role'])?></td><td><?=h($x['balance'])?></td><td><?=h($x['status'])?></td><td><?php if($x['role']!=='admin'):?><form method="post" action="?a=admin"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="tab" value="userstatus"><input type="hidden" name="user_id" value="<?=h($x['id'])?>"><input type="hidden" name="status" value="<?= $x['status']==='active'?'suspended':'active'?>"><button class="text-indigo-300"><?= $x['status']==='active'?'Suspend':'Activate'?></button></form><?php endif;?></td></tr><?php endforeach;?></table></div>
<?php } elseif($tab==='orders') { $orders=$pdo->query('SELECT o.*,u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC')->fetchAll();?><div class="bg-slate-800 p-5 rounded-xl overflow-x-auto"><table class="w-full text-sm"><tr class="text-left"><th>Reference</th><th>User Email</th><th>Amount</th><th>Status</th><th>Date</th></tr><?php foreach($orders as $o):?><tr class="border-t border-slate-700"><td class="p-2"><?=h($o['reference'])?></td><td><?=h($o['email'])?></td><td>₹<?=h($o['amount'])?></td><td><?=h($o['status'])?></td><td><?=h($o['created_at'])?></td></tr><?php endforeach;?></table></div>
<?php } elseif($tab==='settings') { ?><div class="bg-slate-800 p-6 rounded-xl"><form method="post" action="?a=admin"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="tab" value="settings"><?php field('Site name','site_name',setting($pdo,'site_name'));field('Currency','currency',setting($pdo,'currency'));field('Maintenance mode (1/0)','maintenance',setting($pdo,'maintenance'));?><button class="bg-indigo-600 p-3 rounded-lg">Save settings</button></form></div><?php } else {echo '<p>Unknown admin tab.</p>';} ?>
<?php } elseif($action==='webhook') { http_response_code(503); echo 'Payment webhook is not enabled in this demo build. Configure and test the verified SGVIP merchant contract before accepting payments.'; } else { http_response_code(404); echo '<h1 class="text-2xl">Page not found</h1>'; }
footer_html();
?>
