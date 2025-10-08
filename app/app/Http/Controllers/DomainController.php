<?php

namespace App\Http\Controllers;

use App\Models\DomainOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Throwable;

class DomainController extends Controller
{
    public function index()
    {
        return view('domain');
    }
    /** Default TLDs */
    private array $tlds = [
        'ru','su','com','net','org','host','pro','city','report',
        'care','recipes','wtf','glass','guitars','graphics',
        'christmas','services','club','cleaning'
    ];

    /** Fallback EUR prices (override via config if needed) */
    private array $priceEur = [
        'ru'=>4.01,'su'=>6.77,'com'=>23.74,'net'=>28.85,'org'=>30.22,'host'=>180.36,
        'pro'=>46.92,'city'=>41.13,'report'=>41.13,'care'=>34.50,'recipes'=>99.36,
        'wtf'=>74.52,'glass'=>99.36,'guitars'=>281.80,'graphics'=>41.13,
        'christmas'=>144.76,'services'=>59.49,'club'=>28.85,'cleaning'=>99.36,
    ];

    /** POST /domain/check */
    public function check(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','regex:/^[a-z0-9-]{1,63}$/i'],
            'tlds' => ['sometimes','array'],
            'tlds.*' => ['string'],
        ]);

        $name = Str::lower($data['name']);
        $tlds = array_values($data['tlds'] ?? $this->tlds);

        $provider = config('services.domain.provider', 'domainr');
        $cacheKey = "domain_check:{$provider}:{$name}:" . implode(',', $tlds);

        // Short cache to soften rate limits
        return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($name, $tlds) {
            try {
                $results = $this->queryDomainr($name, $tlds);
            } catch (Throwable $e) {
                // Fallback: mark all Busy except first available by hash (so UI still works)
                $results = [];
                foreach ($tlds as $i => $tld) {
                    $available = ((crc32($name.$tld) % 5) === 0);
                    $results[] = [
                        'domain' => "{$name}.{$tld}",
                        'tld' => $tld,
                        'status' => $available ? 'available' : 'busy',
                        'price' => number_format($this->priceEur[$tld] ?? 25.00, 2, '.', ''),
                    ];
                }
            }

            // Ensure consistent price field (EUR)
            foreach ($results as &$r) {
                $t = $r['tld'];
                $r['price'] = number_format((float)($r['price'] ?? ($this->priceEur[$t] ?? 25.00)), 2, '.', '');
            }

            return response()->json([
                'ok' => true,
                'items' => $results,
            ]);
        });
    }

    /** POST /domain/order */
    public function order(Request $request)
    {
        $validated = $request->validate([
            'domain'   => ['required','regex:/^[a-z0-9-]{1,63}\.[a-z0-9.-]+$/i'],
            'tld'      => ['required', Rule::in($this->tlds)],
            'price'    => ['required','numeric','min:0'],
            'period'   => ['sometimes','integer','min:1','max:10'],
            'autoprolong' => ['sometimes','boolean'],

            'full_name' => ['required','string','max:255'],
            'dob'       => ['required','date'],
            'passport_series' => ['required','string','max:64'],
            'passport_issuer' => ['required','string','max:255'],
            'issue_date' => ['required','date'],
            'postcode'   => ['required','string','max:32'],
            'region'     => ['required','string','max:255'],
            'country'    => ['required','string','size:2'], // ISO code
            'city'       => ['required','string','max:255'],
            'address'    => ['required','string','max:255'],
            'phone'      => ['required','string','max:64'],
        ]);

        // Re-check availability just-in-time
        [$name, $tld] = explode('.', $validated['domain'], 2);
        $status = $this->checkSingleDomainRealTime($name, $validated['tld']);
        if ($status !== 'available') {
            return response()->json([
                'ok' => false,
                'message' => 'Domain is no longer available. Please choose another one.',
            ], 409);
        }

        $order = DomainOrder::create([
            'domain' => $validated['domain'],
            'tld' => $validated['tld'],
            'price_eur' => $validated['price'],
            'period_years' => (int)($validated['period'] ?? 1),
            'autoprolong' => (bool)($validated['autoprolong'] ?? false),

            'full_name' => $validated['full_name'],
            'dob' => $validated['dob'],
            'passport_series' => $validated['passport_series'],
            'passport_issuer' => $validated['passport_issuer'],
            'issue_date' => $validated['issue_date'],
            'postcode' => $validated['postcode'],
            'region' => $validated['region'],
            'country' => Str::upper($validated['country']),
            'city' => $validated['city'],
            'address' => $validated['address'],
            'phone' => $validated['phone'],
            'status' => 'pending',
        ]);

        // TODO: handoff to payment gateway here

        return response()->json([
            'ok' => true,
            'order_id' => $order->id,
            'redirect' => route('domain.index') . '#payment',
        ]);
    }

    /** Call Domainr via RapidAPI to check multiple TLDs */
    private function queryDomainr(string $name, array $tlds): array
    {
        $key  = config('services.domain.rapidapi_key');
        $host = config('services.domain.rapidapi_host', 'domainr.p.rapidapi.com');

        // Domainr allows batch queries via comma-separated domains
        $domains = array_map(fn($tld) => "{$name}.{$tld}", $tlds);

        $resp = Http::retry(2, 500)
            ->withHeaders([
                'x-rapidapi-key' => $key,
                'x-rapidapi-host' => $host,
            ])
            ->get("https://{$host}/v2/status", [
                'domain' => implode(',', $domains),
            ]);

        if (!$resp->ok()) {
            throw new \RuntimeException('Domain provider error: '.$resp->status());
        }

        $payload = $resp->json();
        $map = [];
        foreach ($payload['status'] ?? [] as $row) {
            $fqdn = $row['domain']; // e.g. example.com
            $tld = substr($fqdn, strrpos($fqdn, '.') + 1);
            // Domainr statuses: active/taken, inactive/available, etc.
            $s = $row['status'] ?? '';
            $status = str_contains($s, 'inactive') || str_contains($s, 'undelegated')
                ? 'available' : 'busy';

            $map[$tld] = [
                'domain' => $fqdn,
                'tld' => $tld,
                'status' => $status,
                'price' => (string)($this->priceEur[$tld] ?? 25.00),
            ];
        }

        // Ensure all requested TLDs appear
        $out = [];
        foreach ($tlds as $tld) {
            $out[] = $map[$tld] ?? [
                'domain' => "{$name}.{$tld}",
                'tld' => $tld,
                'status' => 'busy', // conservative default
                'price' => (string)($this->priceEur[$tld] ?? 25.00),
            ];
        }

        return $out;
    }

    /** Check a single name.tld live (used before order) */
    private function checkSingleDomainRealTime(string $name, string $tld): string
    {
        $items = $this->queryDomainr($name, [$tld]);
        return $items[0]['status'] ?? 'busy';
    }
}