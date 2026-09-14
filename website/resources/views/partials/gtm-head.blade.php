@php
    $analyticsEnabled = (bool) config('services.gtm.enabled')
        && preg_match('/^GTM-[A-Z0-9]+$/', (string) config('services.gtm.id'));
    $gtmId = (string) config('services.gtm.id');
@endphp

@if($analyticsEnabled)
<script>
(function(w,d,id){
  w.dataLayer=w.dataLayer||[];
  w.gtag=w.gtag||function(){w.dataLayer.push(arguments);};

  w.gtag('consent','default',{
    analytics_storage:'granted',
    ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'
  });

  function pushEvent(name,params){
    var payload=Object.assign({event:name},params||{});
    if(payload.ecommerce){w.dataLayer.push({ecommerce:null});}
    w.dataLayer.push(payload);
  }

  var analytics=w.ServerNetAnalytics=w.ServerNetAnalytics||{};
  analytics.track=function(name,params){
    name=String(name||'');
    if(!/^[a-z][a-z0-9_]{1,39}$/.test(name))return;
    pushEvent(name,params);
  };
  analytics.push=function(eventData){
    if(!eventData||typeof eventData!=='object')return;
    var data=Object.assign({},eventData),name=data.event;delete data.event;this.track(name,data);
  };
  analytics.trackFunnel=function(internal,attrs){
    attrs=attrs||{};
    var map={product_page_view:'view_item',order_summary_view:'view_cart',cycle_selected:'configure_product',checkout_click:'begin_checkout'};
    var name=map[internal];if(!name)return;
    var cycle=String(attrs.cycle_at_click||attrs.cycle||'').slice(0,16);
    this.track(name,{
      funnel_stage:String(internal).slice(0,40),traffic_bucket:String(attrs.ref||'').slice(0,32),billing_cycle:cycle,
      ecommerce:{items:[{item_id:String(attrs.sku||'servernet-service').slice(0,64),item_name:String(attrs.sku||'ServerNet service').slice(0,100),item_category:String(attrs.product_line||'service').slice(0,40),item_variant:cycle,quantity:1}]}
    });
  };
  analytics.viewItemList=function(listName,items){this.track('view_item_list',{ecommerce:{item_list_name:String(listName||'catalog').slice(0,100),items:items||[]}});};
  analytics.commerce=function(item,currency){
    var result=Object.assign({},item||{}),code=String(currency||'IRT').toUpperCase();
    if(code==='IRT'){
      code='IRR';
      if(result.price!==undefined){result.price=Number(result.price||0)*10;}
    }
    return {item:result,currency:code,value:Number(result.price||0)};
  };
  analytics.viewItem=function(item,currency){var c=this.commerce(item,currency);this.track('view_item',{ecommerce:{currency:c.currency,value:c.value,items:[c.item]}});};
  analytics.beginCheckout=function(item,cycle,currency){var c=this.commerce(item,currency);this.track('begin_checkout',{billing_cycle:cycle||'monthly',ecommerce:{currency:c.currency,value:c.value,items:[c.item]}});};
  analytics.configureProduct=function(specs){
    specs=specs||{};this.track('configure_product',{cpu_cores:specs.cpu||null,ram_gb:specs.ram||null,datacenter:String(specs.datacenter||'').slice(0,32),product_family:String(specs.family||'').slice(0,32),selected_os:String(specs.os||'').slice(0,32),price_irr:specs.price?Number(specs.price)*10:null});
  };
  analytics.domainSearch=function(domain,isAvailable){
    var value=String(domain||'').trim().toLowerCase(),parts=value.split('.');
    this.track('domain_search',{domain_tld:parts.length>1?parts.pop().slice(0,16):'',domain_length:value.length,domain_available:typeof isAvailable==='boolean'?isAvailable:null});
  };
  analytics.useTool=function(toolName,action){this.track('use_tool',{tool_name:String(toolName||'').slice(0,60),tool_action:String(action||'run').slice(0,32)});};
  analytics.copyInfo=function(infoType){this.track('copy_ip',{info_type:String(infoType||'ip_address').slice(0,32)});};
  analytics.generateLead=function(formName,intent){this.track('generate_lead',{form_name:String(formName||'').slice(0,60),lead_intent:String(intent||'inquiry').slice(0,32)});};
  @auth('customer')
  w.dataLayer.push({customer_status:'authenticated'});
  @endauth

  @foreach([\App\Services\Analytics\DataLayerService::SESSION_PURCHASE_KEY, \App\Services\Analytics\DataLayerService::SESSION_AUTH_KEY, \App\Services\Analytics\DataLayerService::SESSION_GENERIC_KEY] as $analyticsSessionKey)
  @if(session()->has($analyticsSessionKey))
  analytics.push({!! json_encode(session($analyticsSessionKey), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!});
  @endif
  @endforeach

  if(!d.getElementById('snet-gtm')){
    var s=d.createElement('script');s.id='snet-gtm';s.async=true;s.src='https://www.googletagmanager.com/gtm.js?id='+encodeURIComponent(id);d.head.appendChild(s);
  }
})(window,document,@json($gtmId));
</script>
@endif
