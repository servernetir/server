<?php

namespace Tests\Feature\Concerns;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiProject;
use App\Models\AiProvider;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\Setting;
use App\Services\Ai\PriceBook;
use App\Services\Finance\Wallet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * فیکسچرِ مشترکِ موتورِ پولیِ M5 — همان مثالِ کارشدهٔ m5-spec §3.
 *
 *   بها: ورودی ۲۳۰٬۰۰۰ · کش‌شده ۱۱۵٬۰۰۰ · خروجی ۴۰۰٬۰۰۰ میکرودلار به ازای ۱M
 *   R = ۱۰۰٬۰۰۰ تومان · سربار ۸٪ · حاشیه ۲۵٪ · مالیات ۱۰٪
 *   ⇒ P = ۳۱٬۰۵۰ / ۱۵٬۵۲۵ / ۵۴٬۰۰۰ تومان به ازای ۱M
 *   تماسِ ۱۲٬۰۰۰ ورودی (۸٬۰۰۰ کش‌شده) + ۹۰۰ خروجی ⇒ فروش ۲۹۷ + مالیات ۳۰ = **۳۲۷**، بها ۲۳۸
 *
 * ردیفِ `deepinfra` را مهاجرتِ رجیستری سید می‌کند (و 000120 درایورش را درست)؛ فیکسچر
 * همان را به‌روز می‌کند، نه ردیفِ تکراری — دلیلِ سرخیِ قدیمیِ AiCallerTest همین بود.
 */
trait AiGatewayFixture
{
    protected AiProvider $provider;

    protected AiModel $model;

    protected function sellableCatalog(array $providerOver = [], array $modelOver = [], bool $cached = true): void
    {
        Cache::flush();
        Setting::put('pricing_usd_rate_override', '100000');
        Setting::put('ai_margin_pct', '25');
        Setting::put('ai_sales_open', '1');
        Setting::put('ai_provider_deepinfra_base_url', 'https://api.deepinfra.com/v1/openai');
        Setting::putSecret('ai_provider_deepinfra_key', 'sk-test');

        $this->provider = AiProvider::where('slug', 'deepinfra')->firstOrFail();
        $this->provider->update($providerOver + [
            'driver' => 'OpenAI-Compatible', 'enabled' => true, 'live_calls_enabled' => true,
            'commercial_enabled' => true, 'resale_allowed' => true,
            'agreement_status' => AiProvider::STATUS_SIGNED, 'billing_currency_code' => 'USD',
            'fx_fee_bp' => 800,
        ]);

        $this->model = AiModel::create($modelOver + [
            'ai_provider_id' => $this->provider->id, 'slug' => 'llama-3.3-70b',
            'upstream_model' => 'meta-llama/Llama-3.3-70B-Instruct',
            'name' => 'Llama 3.3 70B', 'category' => AiModel::CATEGORY_CHAT,
            'status' => AiModel::STATUS_ACTIVE, 'max_output_tokens' => 4096,
        ]);

        $book = app(PriceBook::class);
        $book->supersede($this->model, AiModelUnitPrice::UNIT_INPUT, 230_000, currencyCode: 'USD');
        if ($cached) {
            $book->supersede($this->model, AiModelUnitPrice::UNIT_CACHED_INPUT, 115_000, currencyCode: 'USD');
        }
        $book->supersede($this->model, AiModelUnitPrice::UNIT_OUTPUT, 400_000, currencyCode: 'USD');
        $this->model->refresh();
    }

    /**
     * مشتری + پروژه + کلیدِ AI ِ سبز + متنِ خامِ کلید.
     *
     * @return array{0:Customer,1:string,2:CustomerApiToken,3:AiProject}
     */
    protected function greenKey(int $credit = 1_000_000, string $locale = 'fa', array $projectOver = [], array $tokenOver = []): array
    {
        $c = Customer::create([
            'email' => 'ai'.random_int(1, 9_999_999).'@example.com',
            'phone' => '0912'.random_int(1_000_000, 9_999_999),
            'password' => null, 'status' => 'active', 'locale' => $locale,
        ]);

        if ($credit > 0) {
            app(Wallet::class)->credit($c->id, 'IRT', $credit, 'topup', $c, 'شارژِ آزمون');
        }

        $p = $c->aiProjects()->create($projectOver + [
            'name' => 'proj', 'slug' => 'p'.random_int(1, 999_999), 'status' => AiProject::STATUS_ACTIVE,
        ]);
        $p->refreshBudgetWindow();

        $plain = 'sn_'.bin2hex(random_bytes(24));
        $token = CustomerApiToken::create($tokenOver + [
            'customer_id' => $c->id, 'name' => 'ai-key', 'token_hash' => hash('sha256', $plain),
            'abilities' => ['ai:chat'], 'allowed_cidrs' => [], 'ai_project_id' => $p->id,
        ]);

        return [$c, $plain, $token, $p];
    }

    /** پاسخِ سازگارِ OpenAI با مصرفِ دلخواه (شکلِ DeepInfra: usage روی همان پاسخ) */
    protected function fakeUsage(int $prompt = 12_000, int $completion = 900, ?int $cached = 8_000, array $extraUsage = [], int $status = 200): void
    {
        $usage = ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion] + $extraUsage;
        if ($cached !== null) {
            $usage['prompt_tokens_details'] = ['cached_tokens' => $cached];
        }

        Http::fake(['*/chat/completions' => Http::response([
            'id' => 'chatcmpl-up-1', 'object' => 'chat.completion', 'model' => 'meta-llama/Llama-3.3-70B-Instruct',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'سلام!'], 'finish_reason' => 'stop']],
            'usage' => $usage,
        ], $status)]);
    }

    /** حالتِ جعلِ بالادست: ok | timeout — با `fakeUpstream()` یک بار ثبت و بعد فقط این عوض می‌شود */
    protected string $upstreamMode = 'ok';

    /** پاسخِ نشانیِ جست‌وجوی مصرف (`usage_lookup_url`)؛ null ⇒ ۴۰۴ */
    protected ?array $lookupUsage = null;

    /**
     * یک جعلِ واحد برای همهٔ تماس‌های بالادست. `Http::fake` ِ لاراول «اولین ثبت‌شده»
     * را برنده می‌کند، پس دو بار صدا زدنش یعنی جعلِ دوم هرگز دیده نمی‌شود.
     */
    protected function fakeUpstream(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $r) {
            if (str_contains($r->url(), '/usage/')) {
                return $this->lookupUsage === null ? Http::response([], 404) : Http::response(['usage' => $this->lookupUsage]);
            }
            if ($this->upstreamMode === 'timeout') {
                throw new \GuzzleHttp\Exception\ConnectException(
                    'cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received', $r->toPsrRequest());
            }

            return Http::response([
                'id' => 'chatcmpl-up-1', 'object' => 'chat.completion',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'سلام!'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 12_000, 'completion_tokens' => 900, 'prompt_tokens_details' => ['cached_tokens' => 8_000]],
            ]);
        });
    }

    protected function workedUsage(): array
    {
        return ['prompt_tokens' => 12_000, 'completion_tokens' => 900, 'prompt_tokens_details' => ['cached_tokens' => 8_000]];
    }

    protected function chatBody(array $over = []): array
    {
        return $over + [
            'model' => 'llama-3.3-70b',
            'messages' => [['role' => 'user', 'content' => 'سلام']],
        ];
    }

    /**
     * بدنه‌ای که واقعاً ۱۲٬۰۰۰ توکن ورودی می‌تواند داشته باشد. سقفِ رزرو از **بایت**‌های
     * بدنه است؛ پیامِ «سلام» با مصرفِ ادعاییِ ۱۲٬۰۰۰ توکن یعنی «بیرون از سقف» — که موتور
     * درست تشخیص می‌دهد و مدل را معلق می‌کند (`AiSettlementTest`).
     */
    protected function bigBody(array $over = []): array
    {
        return $this->chatBody($over + ['messages' => [['role' => 'user', 'content' => str_repeat('a', 12_000)]]]);
    }

    protected function v1(string $plain, array $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($headers + ['Authorization' => 'Bearer '.$plain])
            ->postJson('/v1/chat/completions', $body);
    }

    protected function available(Customer $c): int
    {
        return app(Wallet::class)->availableOf($c->id, 'IRT');
    }

    protected function balance(Customer $c): int
    {
        return app(Wallet::class)->balanceOf($c->id, 'IRT');
    }
}
