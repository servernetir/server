<?php

namespace Tests\Feature;

use App\Mail\ServiceReadyMail;
use Tests\TestCase;

/**
 * 🔴 ایمیلِ تحویل نباید چیزی وعده دهد که این سرویس ندارد.
 *
 * ═══ رخداد (۱ مهر ۱۴۰۵، تیکت TK-260923-2867) ═══
 * مشتریِ سرورِ GPU نوشت: «در ایمیل تحویل نوشته شده رمز root فقط یک‌بار در پنل
 * نمایش داده می‌شود، اما در صفحه مدیریت سرور هیچ رمز یا گزینه‌ای برای مشاهده
 * وجود ندارد.» حق داشت — این خط سرویس **اصلاً رمزی ندارد**
 * (`SaladClient::capabilities()` → `reset_password:false`). دسترسی نشانیِ
 * HTTPS و توکن است.
 *
 * ⚠️ ادعاها روی **متنِ رندرشده** است نه روی پارامترها: یک پرچمِ درست که در
 * قالب خوانده نشود، همان ایمیلِ غلط را می‌فرستد و هیچ خطایی نمی‌دهد.
 */
class GpuDeliveryEmailTest extends TestCase
{
    private function render(bool $gateway): string
    {
        return (new ServiceReadyMail(
            'epie-gpu-benchmark',
            $gateway ? 'g-demo.servernet.cloud' : '203.0.113.9',
            'https://console.servernet.cloud/account/cloud/1',
            $gateway ? null : 'root',
            null,
            'fa',
            passwordInPanel: ! $gateway,
            withSshGuide: ! $gateway,
            gatewayAccess: $gateway,
        ))->render();
    }

    public function test_a_gpu_delivery_never_promises_a_root_password(): void
    {
        $html = $this->render(gateway: true);

        $this->assertStringNotContainsString(__('ui.email_service_pass_panel'), $html,
            'ایمیلِ سرویسِ GPU هنوز می‌گوید رمز در پنل نشان داده می‌شود — چنین رمزی وجود ندارد.');
        $this->assertStringNotContainsString(__('ui.email_service_pass_panel_h'), $html);
        $this->assertStringNotContainsString('root', $html,
            'کاربرِ root برای این خط سرویس معنا ندارد.');
    }

    public function test_it_says_what_the_customer_should_actually_look_for(): void
    {
        $html = $this->render(gateway: true);

        $this->assertStringContainsString(__('ui.email_service_gate_h'), $html);
        $this->assertStringContainsString(__('ui.email_service_gate'), $html);
        $this->assertStringContainsString(__('ui.email_service_gate_btn'), $html);
    }

    /**
     * ⚠️ نیمهٔ دیگر: سرورِ ابریِ معمولی **باید** همان راهنمای قبلی را بگیرد.
     * فیکسی که مسیرِ سالم را هم خاموش کند، یک باگِ تازه است نه رفع.
     */
    public function test_an_ordinary_cloud_server_keeps_its_password_guidance(): void
    {
        $html = $this->render(gateway: false);

        $this->assertStringContainsString(__('ui.email_service_pass_panel_h'), $html);
        $this->assertStringContainsString('root', $html);
        $this->assertStringNotContainsString(__('ui.email_service_gate_h'), $html);
    }

    /**
     * 🔴 هیچ مسیری نباید هم‌زمان هر دو جعبه را بگیرد. اگر روزی فراخوانی هر دو
     * پرچم را بدهد، مشتری دو توضیحِ متناقض در یک ایمیل می‌بیند.
     */
    public function test_the_gateway_box_wins_when_both_flags_are_set(): void
    {
        $html = (new ServiceReadyMail(
            'x', 'g-demo.servernet.cloud', 'https://console.servernet.cloud/a', null, null, 'fa',
            passwordInPanel: true, withSshGuide: false, gatewayAccess: true,
        ))->render();

        $this->assertStringContainsString(__('ui.email_service_gate_h'), $html);
        $this->assertStringNotContainsString(__('ui.email_service_pass_panel_h'), $html);
    }
}
