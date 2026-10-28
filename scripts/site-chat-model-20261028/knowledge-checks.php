<?php
// Read-only QA against an existing saved application. No inference or customer writes.
if (PHP_SAPI !== 'cli' || !isset($argv[1])) { fwrite(STDERR, "Usage: php knowledge-checks.php /absolute/app/path\n"); exit(2); }
require rtrim($argv[1],'/').'/vendor/autoload.php';
$app=require rtrim($argv[1],'/').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$checks = [];
$check = function (bool $ok, string $label) use (&$checks): void { if (! $ok) { throw new RuntimeException('qa_'.$label); } $checks[] = $label; };
$class = App\Services\PublicChatKnowledge::class;
$clean = $class::clean('<script>secret-script()</script><b>متن فارسی</b> token=abc sk-12345678901234567890 vless://private', 1000);
$check(str_contains($clean, 'متن فارسی'), 'persian_preserved');
$check(!str_contains($clean,'secret-script') && !str_contains($clean,'<b>'), 'active_html_removed');
$check(!str_contains($clean,'abc') && !str_contains($clean,'sk-123') && !str_contains($clean,'vless://'), 'credential_and_vpn_redaction');
$check(mb_strlen($class::clean(str_repeat('آ',100),20))===20, 'unicode_length_bound');
$docs=[['title'=>'راهنمای تنظیم داکر','text'=>'مراحل نصب داکر و تنظیم شبکه کانتینر','url'=>'https://servernet.cloud/docs/docker'],['title'=>'دامنه','text'=>'ثبت دامنه و DNS','url'=>'https://servernet.cloud/docs/domain']];
$found=$class::relevant('تنظيم داکر',$docs);
$check(count($found)===1 && $found[0]['url']==='https://servernet.cloud/docs/docker', 'persian_normalized_retrieval');
$check($class::relevant('سلام لطفا برای این',$docs)===[], 'stopwords_do_not_retrieve');
$check($class::relevant('برای راه اندازی n8n هوش مصنوعی API', [['title'=>'سایت ساز هوش مصنوعی','text'=>'راهنمای سایت ساز هوش مصنوعی و API','url'=>'https://servernet.cloud/docs/site-builder']])===[], 'specific_product_excludes_generic_ai_article');
$check(count($class::relevant('راه اندازی n8n', [['title'=>'راه اندازی n8n','text'=>'نصب کانتینر n8n','url'=>'https://servernet.cloud/docs/n8n']]))===1, 'specific_product_document_retained');
$many=[];for($i=0;$i<6;$i++){$many[]=['title'=>'داکر','text'=>str_repeat('داکر ',1000),'url'=>'https://servernet.cloud/docs/docker-'.$i];}
$found=$class::relevant('داکر',$many);
$check(count($found)===3, 'retrieval_count_bound');
$check(max(array_map(fn($d)=>mb_strlen($d['text']),$found))<=1500, 'excerpt_length_bound');
$knowledgeService=new $class;
$ctx=$knowledgeService->forMessage('برای راه اندازی n8n چه خدماتی دارید؟','fa');
$check($ctx['locale']==='fa' && !empty($ctx['as_of']), 'dated_locale_context');
$check(array_diff(array_keys($ctx['contact']),['phone','phone_link','email','sales_email'])===[], 'only_published_contact_fields');
$check(!isset($ctx['contact']['notify_phones']) && !isset($ctx['contact']['notify_chat_ids']), 'internal_notification_recipients_excluded');
$check($ctx['contact']['phone']===site_contact('fa')['phone'], 'live_contact_source');
$check(count($ctx['products'])>10 && count($ctx['products'])<=90, 'live_bounded_catalogue');
$check(collect($ctx['products'])->contains(fn($p)=>str_contains($p['url'],'/cloud/n8n')), 'business_n8n_offering_present');
$check($ctx['pages']['account']==='https://console.servernet.cloud', 'current_client_panel');
$check(!isset($ctx['tickets']) && !isset($ctx['customers']) && !isset($ctx['hermes']), 'private_sources_absent');
$check($knowledgeService->forMessage('hosting','xx')['locale']==='fa', 'unsupported_locale_fallback');

echo json_encode(["passed"=>count($checks),"checks"=>$checks], JSON_THROW_ON_ERROR).PHP_EOL;