{{-- گوگل تگ منیجر و لایه داده‌های تحلیلی (DataLayer) سرورنت --}}
@php
    $gtmId = config('services.gtm.id', 'GTM-MRSC7BF7');
@endphp

@if(!empty($gtmId))
<script>
window.dataLayer = window.dataLayer || [];

{{-- ۱. رویداد خرید فلش‌شده --}}
@if(session()->has(\App\Services\Analytics\DataLayerService::SESSION_PURCHASE_KEY))
window.dataLayer.push({!! json_encode(session(\App\Services\Analytics\DataLayerService::SESSION_PURCHASE_KEY), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!});
@endif

{{-- ۲. رویدادهای احراز هویت (ثبت‌نام و ورود) --}}
@if(session()->has(\App\Services\Analytics\DataLayerService::SESSION_AUTH_KEY))
window.dataLayer.push({!! json_encode(session(\App\Services\Analytics\DataLayerService::SESSION_AUTH_KEY), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!});
@endif

{{-- ۳. سایر رویدادهای فلش‌شده عمومی --}}
@if(session()->has(\App\Services\Analytics\DataLayerService::SESSION_GENERIC_KEY))
window.dataLayer.push({!! json_encode(session(\App\Services\Analytics\DataLayerService::SESSION_GENERIC_KEY), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!});
@endif

{{-- ۴. مشخصات کاربر وارد شده (User Properties) --}}
@auth('customer')
window.dataLayer.push({
    'user_id': '{{ (string) auth('customer')->id() }}',
    'customer_status': 'authenticated'
});
@endauth

{{-- ۵. هلپر جامع کلاینت برای کاتالوگ رویدادهای سرورنت --}}
window.ServerNetAnalytics = {
    push: function(eventData) {
        try {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(eventData);
        } catch (e) {
            console.warn('[Analytics] error pushing dataLayer', e);
        }
    },
    // مشاهده لیست محصولات و پلن‌ها (view_item_list)
    viewItemList: function(listName, items) {
        this.push({
            event: 'view_item_list',
            ecommerce: {
                item_list_name: listName,
                items: items || []
            }
        });
    },
    // مشاهده یک محصول / پلن خاص (view_item)
    viewItem: function(item, currency) {
        this.push({
            event: 'view_item',
            ecommerce: {
                currency: currency || 'IRT',
                value: item.price || 0,
                items: [item]
            }
        });
    },
    // شروع فرآیند تسویه حساب (begin_checkout)
    beginCheckout: function(item, cycle, currency) {
        this.push({
            event: 'begin_checkout',
            ecommerce: {
                currency: currency || 'IRT',
                value: item.price || 0,
                billing_cycle: cycle || 'monthly',
                items: [item]
            }
        });
    },
    // کانفیگوراتور و سفارشی‌سازی سرور (configure_product)
    configureProduct: function(specs) {
        this.push({
            event: 'configure_product',
            cpu_cores: specs.cpu || null,
            ram_gb: specs.ram || null,
            datacenter: specs.datacenter || null,
            product_family: specs.family || null,
            selected_os: specs.os || null,
            price_toman: specs.price || null
        });
    },
    // جستجوی نام دامنه (domain_search)
    domainSearch: function(domain, isAvailable) {
        this.push({
            event: 'domain_search',
            search_term: domain,
            domain_available: typeof isAvailable === 'boolean' ? isAvailable : null
        });
    },
    // استفاده از ابزارهای شبکه و سئو (use_tool)
    useTool: function(toolName, action) {
        this.push({
            event: 'use_tool',
            tool_name: toolName,
            tool_action: action || 'run'
        });
    },
    // کپی آی‌پی یا اطلاعات مهم سرور (copy_ip)
    copyInfo: function(infoType, value) {
        this.push({
            event: 'copy_ip',
            info_type: infoType || 'ip_address',
            copied_value: value || ''
        });
    },
    // ثبت سرنخ و فرم لید (generate_lead)
    generateLead: function(formName, intent) {
        this.push({
            event: 'generate_lead',
            form_name: formName,
            lead_intent: intent || 'inquiry'
        });
    }
};
</script>

<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{{ $gtmId }}');</script>
<!-- End Google Tag Manager -->
@endif
