<?php

namespace App\Services\Ticket;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** ساخت یک گفت‌وگوی پشتیبانی از سمت تیم، با همان مسیر اعلانِ پاسخ‌ها. */
class StaffTicketCreator
{
    public function __construct(private TicketReplyService $replies) {}

    /**
     * @param  Collection<int,Customer>  $customers
     * @return Collection<int,Ticket>
     */
    public function create(
        Collection $customers,
        string $subject,
        string $department,
        string $priority,
        string $body,
        ?User $author = null,
        ?string $authorName = null,
    ): Collection {
        $created = collect();
        $name = trim((string) ($authorName ?: $author?->name ?: 'تیم پشتیبانی سرورنت'));

        foreach ($customers as $customer) {
            $ticket = DB::transaction(function () use ($customer, $subject, $department, $priority, $body, $author, $name) {
                $ticket = $customer->tickets()->create([
                    'subject'         => $subject,
                    'department'      => $department,
                    'priority'        => $priority,
                    'status'          => 'answered',
                    'last_reply_role' => 'staff',
                    'last_reply_at'   => now(),
                ]);

                // پاسخِ نخست هم یک پیامِ واقعیِ کارمند است و اعلان مشتری را
                // از همان قیفِ استاندارد می‌فرستد؛ مسیر موازی نمی‌سازیم.
                $this->replies->post($ticket, $author?->id, $name, $body);

                return $ticket->fresh();
            });

            $created->push($ticket);
        }

        return $created;
    }
}
