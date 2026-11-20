<?php

namespace App\Services\Studio;

use App\Models\AiProject;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use Illuminate\Support\Facades\DB;

final class StudioAuthorization
{
    public const SCOPES = ['ai:models:read', 'ai:chat', 'ai:wallet:read', 'ai:native'];

    public function configured(): bool
    {
        $uri = (string) config('studio.redirect_uri');
        return config('studio.enabled') && strlen((string) config('studio.client_secret')) >= 32
            && filter_var($uri, FILTER_VALIDATE_URL) && parse_url($uri, PHP_URL_SCHEME) === 'https'
            && ! parse_url($uri, PHP_URL_USER) && ! parse_url($uri, PHP_URL_QUERY)
            && ! parse_url($uri, PHP_URL_FRAGMENT);
    }

    public function validateRequest(array $data): array
    {
        abort_unless($this->configured(), 404);
        $validator = validator($data, [
            'client_id' => ['required', 'string'],
            'redirect_uri' => ['required', 'string', 'max:512'],
            'state' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{32,128}\z/'],
            'code_challenge' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
            'code_challenge_method' => ['required', 'in:S256'],
        ]);
        abort_if($validator->fails(), 400);
        $valid = $validator->validated();
        abort_unless(hash_equals((string) config('studio.client_id'), $valid['client_id'])
            && hash_equals((string) config('studio.redirect_uri'), $valid['redirect_uri']), 400);
        return $valid;
    }

    public function issue(Customer $customer, array $data): string
    {
        abort_unless($customer->isActive(), 403);
        $code = self::random();
        DB::table('studio_authorization_codes')->insert([
            'code_hash' => hash('sha256', $code), 'customer_id' => $customer->id,
            'challenge' => $data['code_challenge'], 'redirect_uri' => $data['redirect_uri'],
            'expires_at' => now()->addSeconds(60), 'consumed_at' => null,
        ]);
        return $code;
    }

    public function exchange(array $data): array
    {
        abort_unless($this->configured(), 404);
        validator($data, [
            'client_id' => ['required', 'string'], 'client_secret' => ['required', 'string', 'max:512'],
            'redirect_uri' => ['required', 'string', 'max:512'],
            'code' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
            'code_verifier' => ['required', 'string', 'regex:/\A[A-Za-z0-9._~-]{43,128}\z/'],
        ])->validate();
        abort_unless(hash_equals((string) config('studio.client_id'), $data['client_id'])
            && hash_equals((string) config('studio.client_secret'), $data['client_secret'])
            && hash_equals((string) config('studio.redirect_uri'), $data['redirect_uri']), 401);
        $hash = hash('sha256', $data['code']);
        $record = DB::table('studio_authorization_codes')->where('code_hash', $hash)->first();
        abort_unless($record, 400);

        return DB::transaction(function () use ($record, $hash, $data) {
            // Global money-path lock order: customer, then the authorization record.
            $customer = Customer::whereKey($record->customer_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('studio_authorization_codes')->where('code_hash', $hash)->lockForUpdate()->first();
            abort_unless($row && ! $row->consumed_at && now()->lt($row->expires_at)
                && $row->redirect_uri === $data['redirect_uri'] && $customer->isActive()
                && hash_equals($row->challenge, self::challenge($data['code_verifier'])), 400);
            abort_if($customer->apiTokens()->usable()->count() >= CustomerApiToken::MAX_ACTIVE, 409);
            $project = $customer->aiProjects()->where('slug', 'servernet-studio')->first();
            if (! $project) {
                $project = $customer->aiProjects()->create([
                    'name' => 'ServerNet Studio', 'slug' => 'servernet-studio',
                    'status' => AiProject::STATUS_ACTIVE, 'budget_period' => AiProject::PERIOD_NONE,
                ]);
            }
            abort_unless($project->status === AiProject::STATUS_ACTIVE, 403);
            $ttl = max(300, min(28800, (int) config('studio.token_ttl', 28800)));
            $plain = 'sn_studio_'.self::random();
            $customer->apiTokens()->create([
                'name' => 'ServerNet Studio', 'token_hash' => hash('sha256', $plain),
                'abilities' => self::SCOPES, 'ai_project_id' => $project->id,
                'expires_at' => now()->addSeconds($ttl),
            ]);
            DB::table('studio_authorization_codes')->where('code_hash', $hash)->update(['consumed_at' => now()]);
            return ['access_token' => $plain, 'token_type' => 'Bearer', 'expires_in' => $ttl,
                'customer' => ['id' => (string) $customer->id, 'name' => $customer->displayName()]];
        });
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
