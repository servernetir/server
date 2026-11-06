<?php

/** Pure fact/intent regressions using the actual bounded projection, without app/network. */
declare(strict_types=1);
use App\Services\Hermes\HermesInfrastructureContext as Context;
use App\Services\Hermes\HermesProviderReply as Reply;
require_once dirname(__DIR__, 4).'/website/app/Services/Hermes/HermesInfrastructureContext.php';
require_once dirname(__DIR__, 4).'/website/app/Services/Hermes/HermesProviderReply.php';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++; if (! $condition) { throw new RuntimeException('FAILED: '.$label); }
};
$now = time();
$registry = ['hetzner'=>'Hetzner Cloud','aeza'=>'Aeza','arvan'=>'ArvanCloud','ovh'=>'OVHcloud','proxmox'=>'Proxmox',
    'salad'=>'SaladCloud','hetzner-robot'=>'Hetzner Robot','lightnode'=>'LightNode','vpstc'=>'VPS.TC'];
$records = [];
foreach ($registry as $id=>$label) { $records[$id] = ['sales'=>['ok'=>true,'reason'=>'available','offers'=>8],'disabled'=>false]; }
$records['proxmox'] = ['sales'=>['ok'=>false,'reason'=>'admin_disabled','offers'=>0],'disabled'=>true];
$records['arvan'] = ['sales'=>['ok'=>true,'reason'=>'available','offers'=>268],'disabled'=>false,
    'catalog'=>['plans'=>804,'active'=>332,'in_stock'=>300,'admin_disabled'=>473,'synced_at'=>$now-10,'regions'=>['ir-tabriz']],
    'quarantine_records'=>array_fill(0,32,['admin_disabled'=>true,'note'=>'manual review','updated_at'=>$now-5]),
    'configured'=>true,'inventory'=>['tracked'=>4,'by_status'=>['running'=>3],'synced_at'=>$now-10]];
$query = 'چرا زیرساخت آروان باز فروش پنل هاست بسته هست چیشده؟';
$context = Context::project($records,$query,$now,$registry);
$answer = Reply::reply($query,$context);
$check(Reply::intent($query)==='arvan' && is_string($answer),'exact owner Arvan question receives fact answer');
$check(str_contains($answer,'۲۶۸ عرضهٔ قابل‌فروش'),'uses offers268');
$check(str_contains($answer,'۸۰۴ ردیف کاتالوگ') && str_contains($answer,'۴۷۳ ردیف'),'catalogue and blocked counts distinct');
$check(str_contains($answer,'۳۳۲ ردیف «فعال» کاتالوگ، تعداد عرضهٔ قابل‌فروش نیست'),'active332 never substituted for sellable offers');
$check(str_contains($answer,'نمونهٔ ۳۲ یادداشت') && str_contains($answer,'علت همهٔ ردیف‌های بسته'),'sample not all473 explanations');
$check(str_contains($answer,'در این نمونه خطای سقف RAM تأیید نشده'),'historical RAM report not fabricated as current cause');
$check(str_contains($answer,'سهمیهٔ لحظه‌ای') && str_contains($answer,'بررسی نشده'),'unknown live quota and API explicit');
$listQuery = 'نه در مورد سرورنت اطلاعاتی نداری چه زیرساخت هایی میفروشیم؟';
$listContext = Context::project($records,$listQuery,$now,$registry);
$list = Reply::reply($listQuery,$listContext);
$check(Reply::intent($listQuery)==='list' && is_string($list),'exact owner infrastructure question receives registry answer');
foreach (['Hetzner Cloud','Aeza','آروان','OVHcloud','Proxmox','SaladCloud','Hetzner Robot','LightNode','VPS.TC'] as $name) {
    $check(str_contains($list,$name),'registered supplier present:'.$name);
}
$check(substr_count($list,'• ')===9,'all nine registered suppliers listed');
$check(str_contains($list,'Proxmox — بسته'),'closed registry provider remains visible');
$check(! preg_match('/VM|128|VPN|AI Gateway|هوش مصنوعی/u',$list),'no private VM/VPN or unrelated product fabricated');
$check(str_contains($list,'آروان — باز؛ ۲۶۸'),'list also uses actual offer count');
foreach (['آراز چخبر؟','چه خبر','سلام','دانشت بروزه؟','حافظه چیست؟','آخرین خبرهای هاستینگ را بگو',
    'فروش آروان را باز کن','آروان را فعال کن','چرا فروش آروان بسته است و بازش کن','چرا فروش آروان بسته است بازشکن',
    'چرا فروش آروان بسته است؟ بعدش رله را بررسی کن','چه زیرساخت هایی میفروشیم و یکی بخر',
    'چه زیرساخت هایی میفروشیم؟ رله رو هم ببین','وضعیت فروش آروان چیه سپس رله را بخوان',
    'فهرست زیرساخت های فروش را بنویس و منتشر کن','وضعیت فروش آروان را زنده چک کن',
    'چرا فروش دیروز آروان بسته بود','هتزنر و آروان را مقایسه کن','چه محصولاتی میفروشیم؟',
    'Check live Arvan sales status','Enable Arvan sales','What providers do we sell? Then buy one'] as $counterexample) {
    $check(Reply::reply($counterexample,$context)===null,'ordinary chat/work reaches model:'.$counterexample);
}
foreach ([[],['available'=>false],array_replace($context,['checked_at'=>$now+100]),
    array_replace($context,['checked_at'=>$now-301]),array_replace($context,['audience'=>'public']),
    array_replace($context,['version'=>'untrusted'])] as $missing) {
    $unknown = Reply::reply($query,$missing);
    $check(is_string($unknown) && str_contains($unknown,'حدس نمی‌زنم') && ! str_contains($unknown,'۲۶۸'),'matched invalid source yields bounded unknown');
}
$unsafe = $context;
$unsafe['providers'][0]['label']='private VPN VM128 token=synthetic-secret';
$unsafe['providers'][0]['sales']['state']='closed';
$unsafe['providers'][0]['sales']['reason_code']='token=synthetic-secret';
$safe = Reply::reply($query,$unsafe);
$check(! str_contains($safe,'synthetic-secret') && ! str_contains($safe,'VM128'),'raw source label and reason never echoed');
$check(str_contains($safe,'علت ثبت‌شدهٔ قابل گزارش نامعلوم'),'unrecognized closed reason stays unknown');
$ram = $context;
$ram['providers'][0]['sales']['quarantine_reason_codes']=['account_ram_limit'];
$ramReply=Reply::reply($query,$ram);
$check(str_contains($ramReply,'علت همهٔ ردیف‌های بسته یا ظرفیت امروز حساب را ثابت نمی‌کند'),'recorded RAM refusal kept sample scoped');
$zero = $context;
$zero['providers'][0]['sales']['offers']=0;
$check(str_contains(Reply::reply($query,$zero),'۰ عرضهٔ قابل‌فروش'),'known zero not replaced with catalogue active count');
$unknownCount=$context;
$unknownCount['providers'][0]['sales']['offers']=null;
$check(str_contains(Reply::reply($query,$unknownCount),'تعداد عرضهٔ قابل‌فروش نامعلوم'),'missing offers not zero or active count');
$check(mb_strlen($answer)<1400 && mb_strlen($list)<1800,'factual replies remain compact');
echo json_encode(['ok'=>true,'checks'=>$checks,'network_calls'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
