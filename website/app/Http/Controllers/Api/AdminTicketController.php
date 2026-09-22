<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Ticket\StaffTicketCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** API محدودِ اتوماسیون برای آغاز گفت‌وگو از سمت پشتیبانی. */
class AdminTicketController extends Controller
{
    private const MAX_RECIPIENTS = 200;

    public function store(Request $request, StaffTicketCreator $creator): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_ids'   => ['nullable', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'customer_ids.*' => ['integer', 'distinct', 'exists:customers,id'],
            'customer_codes'   => ['nullable', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'customer_codes.*' => ['string', 'max:24', 'distinct', 'exists:customers,code'],
            'subject'    => ['required', 'string', 'max:200'],
            'department' => ['required', 'in:technical,billing,sales'],
            'priority'   => ['required', 'in:low,normal,high,urgent'],
            'body'       => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'code' => 'validation_failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $ids = array_values(array_unique(array_map('intval', (array) ($data['customer_ids'] ?? []))));
        $codes = array_values(array_unique(array_map('strval', (array) ($data['customer_codes'] ?? []))));

        if ($ids === [] && $codes === []) {
            return response()->json([
                'ok' => false, 'code' => 'recipients_required',
                'message' => 'حداقل یک customer_id یا customer_code لازم است.',
            ], 422);
        }

        $customers = Customer::query()->where(function ($q) use ($ids, $codes) {
            if ($ids !== []) {
                $q->whereIn('id', $ids);
            }
            if ($codes !== []) {
                $ids === [] ? $q->whereIn('code', $codes) : $q->orWhereIn('code', $codes);
            }
        })->orderBy('id')->get();

        $tickets = $creator->create($customers, $data['subject'], $data['department'], $data['priority'], $data['body']);

        return response()->json([
            'ok' => true,
            'created' => $tickets->count(),
            'tickets' => $tickets->map(fn ($ticket) => [
                'number' => $ticket->number,
                'customer_code' => $ticket->customer?->code,
                'url' => url('/admin/tickets/'.$ticket->id),
            ])->values(),
        ], 201);
    }
}
