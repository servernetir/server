<?php

namespace App\Http\Controllers;

use App\Services\Crm\ChatLeadCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ChatLeadIngestController extends Controller
{
    public function __invoke(Request $request, ChatLeadCapture $capture): JsonResponse
    {
        if (! Schema::hasTable('crm_leads') || ! Schema::hasTable('crm_messages')) {
            return response()->json(['ok' => false, 'code' => 'crm_not_ready'], 503);
        }
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9:_-]+$/'],
            'ticket' => ['nullable', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:40', 'regex:/^[0-9۰-۹٠-٩+().\s-]{7,40}$/u'],
            'locale' => ['required', 'in:fa,en,tr'],
            'summary' => ['required', 'string', 'max:10000'],
            'occurred_at' => ['nullable', 'date'],
            'notify' => ['sometimes', 'boolean'],
        ]);
        $result = $capture->capture($data);
        return response()->json([
            'ok' => true, 'created' => $result['created'],
            'reference' => 'CHAT-'.$result['lead']->id,
        ], $result['created'] ? 201 : 200);
    }
}
