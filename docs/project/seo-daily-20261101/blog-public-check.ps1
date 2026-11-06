param([ValidateSet('Before','After')][string]$Phase='Before')
$ErrorActionPreference='Stop'
$outPath=Join-Path $PSScriptRoot ('blog-public-'+$Phase.ToLowerInvariant()+'.json')
$beforePath=Join-Path $PSScriptRoot 'blog-public-before.json'
if(Test-Path -LiteralPath $outPath){throw 'Snapshot exists; do not repeat blindly.'}
if($Phase -eq 'After' -and -not(Test-Path -LiteralPath $beforePath)){throw 'Before snapshot missing.'}
function Attr([string]$tag,[string]$key){$m=[regex]::Match($tag,'\b'+[regex]::Escape($key)+'\s*=\s*(["''])(.*?)\1','IgnoreCase,Singleline');if($m.Success){[Net.WebUtility]::HtmlDecode($m.Groups[2].Value)}else{''}}
function CleanText([string]$text){([regex]::Replace([Net.WebUtility]::HtmlDecode([regex]::Replace($text,'<[^>]*>',' ')),'\s+',' ')).Trim()}
function HashText([string]$text){[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes($text))).ToLowerInvariant()}
function Canonical([object]$value){
 if($null -eq $value){return $null}
 if($value -is [Collections.IDictionary]){$map=[ordered]@{};foreach($key in @($value.Keys|Sort-Object -CaseSensitive)){$map[$key]=Canonical $value[$key]};return $map}
 if($value -is [Collections.IEnumerable] -and $value -isnot [string]){$items=@();foreach($item in $value){$items+=,(Canonical $item)};return ,$items}
 return $value
}
function Signature([object]$value){HashText (ConvertTo-Json -InputObject (Canonical $value) -Depth 80 -Compress)}
function PublicPath([string]$base,[string]$href){try{([uri]::new([uri]$base,$href)).GetLeftPart([UriPartial]::Path)}catch{''}}
$handler=[Net.Http.HttpClientHandler]::new();$handler.AllowAutoRedirect=$false;$handler.UseCookies=$false
$client=[Net.Http.HttpClient]::new($handler);$client.Timeout=[TimeSpan]::FromSeconds(35)
$client.DefaultRequestHeaders.UserAgent.ParseAdd('ServerNet-Bounded-Blog-Acceptance/2026.11.01')
$client.DefaultRequestHeaders.Accept.ParseAdd('text/html')
$pages=@();$count=0;$started=[DateTime]::UtcNow.ToString('o')
try{
 foreach($language in @(@{locale='fa';prefix=''},@{locale='en';prefix='/en'},@{locale='tr';prefix='/tr'})){
  foreach($kind in @('listing','article')){
   $suffix=if($kind -eq 'listing'){'/blog'}else{'/blog/php-version-upgrade-guide'}
   $url='https://servernet.cloud'+$language.prefix+$suffix
   if(++$count -gt 6){throw 'Six-GET budget exceeded.'}
   $timer=[Diagnostics.Stopwatch]::StartNew();$response=$client.GetAsync($url+'?qa=1').GetAwaiter().GetResult()
   try{
    $html=$response.Content.ReadAsStringAsync().GetAwaiter().GetResult();$timer.Stop()
    $head=[regex]::Match($html,'<head\b[^>]*>(.*?)</head>','IgnoreCase,Singleline').Groups[1].Value
    $canonical=@();$alternates=@();$fonts=@()
    foreach($tag in [regex]::Matches($head,'<link\b[^>]*>','IgnoreCase')){
     $rel=Attr $tag.Value 'rel';$href=Attr $tag.Value 'href'
     if($rel -eq 'canonical'){$canonical+=$href}
     $locale=Attr $tag.Value 'hreflang';if($locale){$alternates+=[ordered]@{locale=$locale;url=$href}}
     if($href -match '/font/|IRANSans'){$fonts+=[ordered]@{url=(PublicPath $url $href);rel=$rel;as=(Attr $tag.Value 'as');type=(Attr $tag.Value 'type')}}
    }
    $description=@();$robots=@()
    foreach($tag in [regex]::Matches($head,'<meta\b[^>]*>','IgnoreCase')){
     $name=Attr $tag.Value 'name';if($name -eq 'description'){$description+=Attr $tag.Value 'content'}
     if($name -eq 'robots'){$robots+=Attr $tag.Value 'content'}
    }
    $schemas=@();$schemaTypes=@();$schemaErrors=0
    foreach($block in [regex]::Matches($html,'<script\b[^>]*type=["'']application/ld\+json["''][^>]*>(.*?)</script>','IgnoreCase,Singleline')){
     try{$schema=$block.Groups[1].Value|ConvertFrom-Json -AsHashtable -Depth 80;$schemas+=$schema;if($schema.Contains('@type')){$schemaTypes+=@($schema['@type'])};if($schema.Contains('@graph')){$schemaTypes+=@($schema['@graph']|ForEach-Object {$_['@type']})}}catch{$schemaErrors++}
    }
    $bodyMatch=[regex]::Match($html,'<article\b[^>]*\bid=["'']bp-article["''][^>]*>(.*?)</article>','IgnoreCase,Singleline')
    $body=$bodyMatch.Groups[1].Value
    $byline=[regex]::Match($html,'<div\b[^>]*class=["''][^"'']*\bbp-byline\b[^"'']*["''][^>]*>(.*?)</div>','IgnoreCase,Singleline').Groups[1].Value
    $lead=[regex]::Match($html,'<p\b[^>]*class=["''][^"'']*\bbp-lead\b[^"'']*["''][^>]*>(.*?)</p>','IgnoreCase,Singleline').Groups[1].Value
    $cover=[regex]::Match($html,'<figure\b[^>]*class=["''][^"'']*\bbp-cover\b[^"'']*["''][^>]*>.*?</figure>','IgnoreCase,Singleline').Value
    $coverImage=[regex]::Match($cover,'<img\b[^>]*>','IgnoreCase').Value
    $cards=@()
    foreach($card in [regex]::Matches($html,'<article\b[^>]*class=["''][^"'']*\bblog-card\b[^"'']*["''][^>]*>(.*?)</article>','IgnoreCase,Singleline')){
     $fragment=$card.Groups[1].Value;$heading=[regex]::Match($fragment,'<h[23]\b[^>]*>(.*?)</h[23]>','IgnoreCase,Singleline').Groups[1].Value
     $anchor=[regex]::Match($heading,'<a\b[^>]*>','IgnoreCase').Value
     $cards+=[ordered]@{url=(PublicPath $url (Attr $anchor 'href'));title=(CleanText $heading);card_markup_sha256=(HashText $fragment)}
    }
    $articleLinks=@([regex]::Matches($body,'<a\b[^>]*>','IgnoreCase')|ForEach-Object {PublicPath $url (Attr $_.Value 'href')})
    $stable=[ordered]@{
     title=(CleanText ([regex]::Match($head,'<title\b[^>]*>(.*?)</title>','IgnoreCase,Singleline').Groups[1].Value))
     h1=@([regex]::Matches($html,'<h1\b[^>]*>(.*?)</h1>','IgnoreCase,Singleline')|ForEach-Object {CleanText $_.Groups[1].Value})
     meta_description=$description;canonical=$canonical;hreflang=$alternates;robots_meta=$robots
     json_ld_count=$schemas.Count;json_ld_valid_count=$schemas.Count;json_ld_error_count=$schemaErrors;json_ld_types=@($schemaTypes|Where-Object {$_}|Sort-Object -Unique);json_ld_signature=(Signature $schemas)
     visible_article_body_present=$bodyMatch.Success;article_body_sha256=$(if($bodyMatch.Success){HashText $body}else{$null});article_body_text_sha256=$(if($bodyMatch.Success){HashText (CleanText $body)}else{$null});article_body_bytes=[Text.Encoding]::UTF8.GetByteCount($body)
     article_metadata=[ordered]@{lead_sha256=(HashText (CleanText $lead));byline_sha256=(HashText $byline);cover_markup_sha256=(HashText $cover);cover_has_image=($coverImage -ne '');cover_src=$(if($coverImage){PublicPath $url (Attr $coverImage 'src')}else{''});cover_priority=(Attr $coverImage 'fetchpriority');cover_decoding=(Attr $coverImage 'decoding')}
     article_body_public_link_paths=$articleLinks;article_card_links=$cards;font_asset_markers=$fonts;iransans_reference=$html.Contains('IRANSans')
    }
    $cache=if($response.Headers.Contains('X-Cache')){$response.Headers.GetValues('X-Cache') -join ','}else{''}
    $pages+=[ordered]@{locale=$language.locale;kind=$kind;url=$url;checked_url=$url+'?qa=1';status=[int]$response.StatusCode;cache=$cache;elapsed_s=[Math]::Round($timer.Elapsed.TotalSeconds,3);html_bytes=[Text.Encoding]::UTF8.GetByteCount($html);canonical_self=($canonical.Count -eq 1 -and $canonical[0] -ceq $url);stable=$stable;semantic_signature=(Signature $stable)}
   }finally{$response.Dispose()}
  }
 }
}finally{$client.Dispose();$handler.Dispose()}
$comparison=@()
if($Phase -eq 'After'){
 $before=Get-Content -LiteralPath $beforePath -Raw|ConvertFrom-Json -AsHashtable -Depth 80
 foreach($row in $pages){$old=@($before.pages|Where-Object {$_.url -ceq $row.url});if($old.Count -ne 1){throw 'Before URL set differs.'};$old=$old[0];$comparison+=[ordered]@{url=$row.url;semantic_signature_unchanged=($old.semantic_signature -ceq $row.semantic_signature);body_hash_unchanged=($old.stable.article_body_sha256 -ceq $row.stable.article_body_sha256);metadata_unchanged=((Signature $old.stable.article_metadata) -ceq (Signature $row.stable.article_metadata));article_cards_unchanged=((Signature $old.stable.article_card_links) -ceq (Signature $row.stable.article_card_links));elapsed_before_s=$old.elapsed_s;elapsed_after_s=$row.elapsed_s}}
}
$failed=@($pages|Where-Object {$_.status -ne 200 -or $_.cache -ne 'BYPASS' -or -not $_.canonical_self -or $_.stable.h1.Count -ne 1 -or $_.stable.hreflang.Count -ne 4 -or $_.stable.json_ld_error_count -gt 0 -or ($_.kind -eq 'article' -and -not $_.stable.visible_article_body_present) -or ($_.kind -eq 'listing' -and $_.stable.article_card_links.Count -eq 0)})
$receipt=[ordered]@{phase=$Phase.ToLowerInvariant();observed_at_utc=$started;completed_at_utc=[DateTime]::UtcNow.ToString('o');request_count=$count;scope='Exactly six anonymous HttpClient GETs with UseCookies=false, no redirects/retries. No browser/account/purchase/API mutation. CSRF/forms/comments/header identity and raw HTML are excluded.';pages=$pages;page_acceptance_failure_count=$failed.Count;comparison_to_before=$comparison;limits='One-pass HTTP timings only, not stable speed, LCP or CWV. Public body/metadata/link/font signatures guard semantic equivalence; investigate unrelated live content drift before attributing it to code.'}
[IO.File]::WriteAllText($outPath,($receipt|ConvertTo-Json -Depth 80)+"`n",[Text.UTF8Encoding]::new($false))
$pages|ForEach-Object {[pscustomobject]@{locale=$_.locale;kind=$_.kind;status=$_.status;cache=$_.cache;canonical=$_.canonical_self;h1=$_.stable.h1.Count;hreflang=$_.stable.hreflang.Count;schema_errors=$_.stable.json_ld_error_count;article=$_.stable.visible_article_body_present;cards=$_.stable.article_card_links.Count;elapsed_s=$_.elapsed_s}}|Format-Table -AutoSize
if($failed.Count){throw 'Blog public acceptance failure; inspect saved receipt.'}
if($Phase -eq 'After' -and @($comparison|Where-Object {@($_.Values|Where-Object {$_ -is [bool] -and $_ -ceq $false}).Count}).Count){throw 'Blog semantic drift requires review.'}
