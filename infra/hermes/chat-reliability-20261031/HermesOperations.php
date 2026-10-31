<?php

namespace App\Services\Hermes;

use App\Models\CloudInstance;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\Setting;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\BaleSender;
use App\Services\Cloud\CloudInventory;
use App\Services\Cloud\CloudManager;
use Illuminate\Support\Facades\Crypt;

/** Application coordinator: diagnoses, durable work, owner feedback and read-only cost audit. */
final class HermesOperations
{
    public function __construct(
        private HermesOperationsStore $store, private AdminBaleGate $gate,
        private HermesServiceDossier $dossiers, private HermesReadOnlyDiagnostics $diagnostics,
        private HermesSupportState $support, private CloudInventory $inventory,
        private CloudManager $cloud, private BaleSender $sender,
    ) {}

    public function owner(): ?array
    {
        $binding = $this->gate->binding(); $user = $this->gate->boundUser();
        return $this->gate->enabled() && $binding && $user
            ? ['user_id' => (string) $user->id, 'chat_id' => (string) $binding['chat_id']] : null;
    }

    public function enabled(): bool { return Setting::get('hermes_operations_enabled') === '1'; }

    public function recordHealth(bool $healthy): void
    {
        if (! ($owner = $this->owner())) { return; }
        $this->store->change($owner, function (array &$state) use ($healthy): void {
            if (! $healthy) { HermesOperationsPolicy::alert($state, 'operations:run', 'operations_run_failed', []); }
            elseif (isset($state['alerts']['operations:run'])) { $state['alerts']['operations:run']['active'] = false; }
        });
    }

    public function incidents(array $owner): array
    {
        $owner = ['user_id' => (string) $owner['user_id'], 'chat_id' => (string) $owner['chat_id']];
        $this->assertOwner($owner);
        return array_values(array_filter($this->store->read($owner)['alerts'], fn ($a) => $a['active'] && $a['code'] === 'operations_run_failed'));
    }

    public function chatContext(array $owner, string $query = ''): array
    {
        $owner = ['user_id' => (string) $owner['user_id'], 'chat_id' => (string) $owner['chat_id']];
        $this->assertOwner($owner);
        $state = $this->store->read($owner);
        $support = $this->support->read();
        $events = [];
        foreach ($support['items'] as $item) {
            $ownProposal = (string) ($item['approval_owner_id'] ?? '') === $owner['user_id']
                && (string) ($item['approval_chat_id'] ?? '') === $owner['chat_id'];
            $ownNotice = (string) ($item['notice_owner_id'] ?? '') === $owner['user_id']
                && (string) ($item['notice_chat_id'] ?? '') === $owner['chat_id'];
            if (! $ownProposal && ! $ownNotice) { continue; }
            $task = $state['tasks']['ticket:'.($item['ticket_id'] ?? '')] ?? [];
            $events[] = ['ticket_id' => (int) $item['ticket_id'], 'status' => ! empty($item['investigation_required']) ? 'waiting_owner_investigation' : (string) ($item['status'] ?? 'unknown'),
                'updated_at' => (int) ($item['updated_at'] ?? 0), 'owner_notified_at' => $item['owner_notified_at'] ?? null,
                'owner_notice_at' => $item['owner_notice_at'] ?? null,
                'diagnostics_recorded' => ! empty($task['evidence']['diagnostics']['checks'])
                    && (int) ($task['message_id'] ?? 0) === (int) ($item['customer_message_id'] ?? -1),
                'customer_reply_recorded' => isset($item['reply_id'])];
        }
        usort($events, fn ($a, $b) => $b['updated_at'] <=> $a['updated_at']);
        $context = ['available' => true, 'checked_at' => time(), 'operations_enabled' => $this->enabled(),
            'support_enabled' => Setting::get('hermes_support_enabled') === '1',
            'support_last_run_at' => $support['last_run_at'] ?? null,
            'support_last_success_at' => $support['last_success_at'] ?? null,
            'recent_ticket_events' => array_slice($events, 0, 6),
            'customer_send' => 'exact_owner_approval', 'memory' => 'durable_encrypted_editable',
            'approved_reference_rules' => HermesOperationsPolicy::approvedRules($state),
            'tracked_tasks' => array_values(array_map(fn ($task) => array_intersect_key($task,
                array_flip(['ticket_id', 'service_id', 'status', 'updated_at'])), array_slice($state['tasks'], -15))),
            'inventory_checks' => $state['scans'],
            'business_review' => HermesBusinessReport::context($state),
            'installed_knowledge' => $state['release_knowledge'] ?? null,
            'knowledge' => HermesKnowledge::context($state['knowledge'] ?? [], time(), $query),
            'support_experience' => ['observed_at' => $state['support_experience']['observed_at'] ?? null,
                'case_count' => count($state['support_experience']['cases'] ?? []),
                'confirmed_case_count' => $state['support_experience']['confirmed_case_count'] ?? 0,
                'reference_only' => true, 'grants_permissions' => false],
            'infrastructure_changes_authorized' => false];
        // The owner caller fits optional runbooks after its existing history and
        // runtime compression, so guidance cannot evict existing observations.
        $context['approved_business_knowledge'] = app(HermesApprovedBusinessKnowledge::class)->read($query, true);
        return $context;
    }

    public function evidence(Ticket $ticket, string $intent = ''): array
    {
        $owner = $this->owner();
        if (! $owner || ! $this->enabled()) { return []; }
        $selection = $this->dossiers->resolve($ticket);
        $dossier = $this->dossiers->ticket($ticket, $selection);
        $service = $selection['service'];
        $inspectPhp = preg_match('/php|session\.upload_progress/iu', (string) $ticket->subject.' '.$intent) === 1;
        $checks = $service ? $this->diagnostics->inspect($service, $inspectPhp) : ['checks' => [], 'reason' => $selection['status']];
        $rules = HermesOperationsPolicy::approvedRules($this->store->read($owner));
        // Only allowlisted diagnostic fields and metadata; no raw provider bodies/credentials.
        $pendingSelections = [];
        if ($service === null) {
            foreach (Service::query()->where('customer_id', $ticket->customer_id)
                ->where('status', 'pending')->whereNotNull('cloud_plan_id')
                ->orderByDesc('id')->limit(3)->get(['id', 'status', 'cloud_plan_id']) as $selection) {
                $plan = \App\Models\CloudPlan::with('location')->find($selection->cloud_plan_id);
                if (! $plan) { continue; }
                $capabilities = app(CloudManager::class)->capabilitiesForPlan($plan);
                $pendingSelections[] = [
                    'service_id' => (int) $selection->id, 'status' => 'pending',
                    'plan' => ['name' => (string) $plan->public_name,
                        'location_code' => (string) $plan->location_code,
                        'country' => (string) ($plan->location?->country ?? '')],
                    'extra_ipv4_ordering' => ! array_key_exists('extra_ip', $capabilities)
                        ? 'unknown' : ($capabilities['extra_ip'] ? 'supported_in_driver' : 'not_supported_in_driver'),
                    'network_access' => 'not_tested', 'custom_ptr' => 'not_verified',
                ];
            }
        }
        // Customer-owned pending selections are context only, never a fabricated service link.
        $customerContext = ['locale' => in_array($ticket->customer?->locale, ['fa', 'en', 'tr'], true)
                ? $ticket->customer->locale : null,
            'pending_vps_selections' => $pendingSelections,
            'checked_at' => time(), 'source' => 'servernet_database_and_driver_capabilities'];
        $learning = [];
        if (Setting::get('hermes_support_learning_enabled') === '1') {
            $learning['service_investigation'] = $service ? app(HermesServiceInvestigation::class)->read($service)
                : ['result' => 'unavailable', 'reason' => 'exact_owned_service_not_selected'];
            try { $learning['support_experience'] = app(HermesSupportExperience::class)->context($ticket, $owner, $intent); }
            catch (\Throwable) { $learning['support_experience'] = ['available' => false, 'reason' => 'history_unavailable']; }
        }
        $evidence = $learning + ['dossier' => $dossier, 'diagnostics' => $checks, 'approved_reference_rules' => $rules,
            'customer_context' => $customerContext,
            'support_playbooks' => HermesSupportPlaybooks::build((string) $ticket->subject.' '.$intent, $dossier, $checks)];
        $business = app(HermesApprovedBusinessKnowledge::class)->read((string) $ticket->subject.' '.$intent);
        // The native writer has a 24KB immutable evidence limit. Preserve its
        // exact-service diagnostics and history ahead of optional reference text.
        if (strlen(json_encode($evidence + ['approved_business_knowledge' => $business], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) <= 24000) {
            $evidence['approved_business_knowledge'] = $business;
        }
        return $evidence;
    }

    public function recordReview(Ticket $ticket, int $messageId, array $evidence): void
    {
        if (! $this->enabled() || ! ($owner = $this->owner())) { return; }
        $this->store->change($owner, function (array &$state) use ($ticket, $messageId, $evidence): void {
            $key = 'ticket:'.$ticket->id;
            $prior = $state['tasks'][$key] ?? [];
            HermesOperationsPolicy::task($state, $key, ['ticket_id' => (int) $ticket->id,
                'customer_id' => (int) $ticket->customer_id, 'service_id' => $evidence['dossier']['service']['id'] ?? null,
                'message_id' => $messageId, 'status' => 'waiting_owner',
                'evidence_sha256' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR))], ($prior['message_id'] ?? null) !== $messageId);
            $state['tasks'][$key]['evidence'] = $evidence;
        });
    }

    public function view(array $owner): array
    {
        $this->assertOwner($owner);
        $state = $this->store->read($owner);
        $proposals = [];
        foreach ($this->support->read()['items'] as $key => $item) {
            if (! empty($item['investigation_required']) || ($item['status'] ?? '') !== 'awaiting_approval' || ($item['approval_expires_at'] ?? 0) <= time()
                || (string) ($item['approval_owner_id'] ?? '') !== $owner['user_id']
                || (string) ($item['approval_chat_id'] ?? '') !== $owner['chat_id'] || empty($item['owner_notified_at'])) { continue; }
            $proposals[] = ['key' => $key, 'ticket_id' => $item['ticket_id'], 'token' => $item['approval_token'],
                'reply' => Crypt::decryptString($item['draft_ciphertext']),
                'analysis' => isset($item['analysis_ciphertext']) ? Crypt::decryptString($item['analysis_ciphertext']) : '',
                'hash' => $item['approval_draft_sha256'], 'expires_at' => $item['approval_expires_at']];
        }
        $tasks = array_values($state['tasks']);
        usort($tasks, fn ($a, $b) => $b['updated_at'] <=> $a['updated_at']);
        return ['enabled' => $this->enabled(), 'tasks' => array_slice($tasks, 0, 200),
            'rules' => array_values($state['rules']), 'alerts' => array_values(array_filter($state['alerts'], fn ($a) => $a['active'])),
            'scans' => $state['scans'], 'proposals' => array_slice($proposals, -100),
            'storage_scans' => $state['storage_scans'] ?? [],
            'business' => $state['business'] ?? null, 'management' => HermesBusinessReport::context($state),
            'installed_knowledge' => $state['release_knowledge'] ?? null,
            'digests' => $state['digests'], 'capabilities' => ['diagnostics' => 'read_only', 'customer_send' => 'exact_owner_approval',
                'infrastructure_changes' => 'separate_plan_and_approval_required', 'storage_costs' => 'hetzner_inventory_only_amounts_unavailable']];
    }

    public function mutate(array $owner, array $input): array
    {
        $this->assertOwner($owner);
        $action = $input['action'];
        if ($action === 'task_recheck') { return $this->queueTicketReview($owner, $input); }
        if ($action === 'proposal_edit' || $action === 'proposal_recheck') {
            return $this->editProposal($owner, $input, $action === 'proposal_recheck');
        }
        return $this->store->change($owner, function (array &$state) use ($input, $action): array {
            $id = $input['id'] ?? '';
            if ($action === 'task_update') {
                $row = $state['tasks'][$id] ?? null;
                if (! $row || (int) ($input['revision'] ?? -1) !== $row['revision']) { throw new \DomainException('stale'); }
                if (! in_array($input['status'] ?? '', HermesOperationsPolicy::TASK_STATES, true)) { throw new \InvalidArgumentException('status'); }
                HermesOperationsPolicy::task($state, $id, ['status' => $input['status']]);
            } elseif ($action === 'rule_propose') {
                $text = $this->safeText($input['text'] ?? '', 500);
                $id = hash('sha256', 'manual:'.$text);
                if (! isset($state['rules'][$id])) {
                    $state['rules'][$id] = ['id' => $id, 'text' => $text, 'scope' => 'support_reference', 'status' => 'proposed', 'revision' => 1, 'created_at' => time()];
                }
            } elseif ($action === 'rule_decide') {
                $row = $state['rules'][$id] ?? null;
                if (! $row || $row['revision'] !== (int) ($input['revision'] ?? -1)) { throw new \DomainException('stale'); }
                if (! in_array($input['status'] ?? '', ['approved', 'rejected'], true)) { throw new \InvalidArgumentException('status'); }
                $state['rules'][$id]['status'] = $input['status'];
                $state['rules'][$id]['revision']++; $state['rules'][$id]['decided_at'] = time();
            } elseif ($action === 'alert_ack') {
                if (! isset($state['alerts'][$id])) { throw new \DomainException('missing'); }
                $state['alerts'][$id]['acknowledged_at'] = time();
            } else { throw new \InvalidArgumentException('action'); }
            return ['status' => 'saved'];
        });
    }

    /** Explicit owner request can refresh an expired proposal, but never replay ambiguous delivery. */
    private function queueTicketReview(array $owner, array $input): array
    {
        $task = $this->store->read($owner)['tasks'][$input['id'] ?? ''] ?? null;
        if (! $task || $task['revision'] !== (int) ($input['revision'] ?? -1)) { throw new \DomainException('stale'); }
        $ticket = Ticket::query()->whereKey($task['ticket_id'])->where('customer_id', $task['customer_id'])->first();
        $message = $ticket?->visibleMessages()->orderByDesc('id')->first();
        if (! $ticket || $ticket->status !== 'open' || ! $message || $message->author_role !== 'customer') { throw new \DomainException('stale'); }
        $cutoff = (int) Setting::get('hermes_support_enabled_at');
        if ($cutoff < 1 || ! $message->created_at || $message->created_at->timestamp < $cutoff) { throw new \DomainException('outside_review_window'); }
        $key = 'ticket:'.$ticket->id.':message:'.$message->id;
        $queued = $this->support->withItemLock($key, function () use ($key, $ticket, $message, $owner): bool {
            $this->assertOwner($owner);
            $prior = $this->support->item($key) ?? [];
            if (in_array($prior['status'] ?? '', ['dispatching', 'message_written', 'delivery_uncertain', 'sent', 'sent_notification_error'], true)) {
                throw new \DomainException('delivery_requires_reconciliation');
            }
            $this->support->put($key, ['ticket_id' => (int) $ticket->id, 'customer_id' => (int) $ticket->customer_id,
                'customer_message_id' => (int) $message->id, 'status' => 'retry', 'reason' => 'owner_requested_recheck',
                'attempts' => 0, 'next_retry_at' => time(), 'approval_token' => null, 'owner_notified_at' => null,
                'owner_message_id' => null, 'approval_expires_at' => null, 'draft_ciphertext' => null, 'analysis_ciphertext' => null]);
            return true;
        });
        if (! $queued) { throw new \DomainException('busy'); }
        return ['status' => 'recheck_queued'];
    }

    private function editProposal(array $owner, array $input, bool $recheck): array
    {
        if (trim((string) ($input['rule'] ?? '')) !== '') { $this->safeText($input['rule'], 500); }
        $token = $input['token'] ?? ''; $found = null;
        if (! preg_match('/^[a-f0-9]{32}$/D', $token)) { throw new \InvalidArgumentException('token'); }
        foreach ($this->support->read()['items'] as $key => $row) {
            if (hash_equals((string) ($row['approval_token'] ?? ''), $token)) { $found = $key; break; }
        }
        if ($found === null) { throw new \DomainException('stale'); }
        $result = $this->support->withItemLock($found, function () use ($found, $owner, $input, $recheck): array {
            $row = $this->support->item($found);
            $this->assertOwner($owner);
            if (($row['status'] ?? '') !== 'awaiting_approval' || ($row['approval_expires_at'] ?? 0) <= time()
                || ($row['approval_owner_id'] ?? '') !== $owner['user_id'] || ($row['approval_chat_id'] ?? '') !== $owner['chat_id']
                || ! hash_equals((string) ($row['approval_token'] ?? ''), $input['token'])
                || ! hash_equals((string) ($row['approval_draft_sha256'] ?? ''), (string) ($input['hash'] ?? ''))) { throw new \DomainException('stale'); }
            $ticket = Ticket::query()->find($row['ticket_id']);
            $latest = $ticket?->visibleMessages()->orderByDesc('id')->first();
            if (! $ticket || $ticket->status !== 'open' || (int) $ticket->customer_id !== (int) $row['customer_id']
                || ! $latest || $latest->author_role !== 'customer' || (int) $latest->id !== (int) $row['customer_message_id']
                || ! hash_equals((string) ($row['source_content_sha256'] ?? ''), HermesSupportSnapshot::capture($ticket)['source_content_sha256'])) {
                throw new \DomainException('stale');
            }
            $old = Crypt::decryptString($row['draft_ciphertext']);
            $body = $recheck ? $old : $this->safeText($input['text'] ?? '', 1800);
            $this->support->put($found, ['status' => $recheck ? 'retry' : 'review_required',
                'draft_ciphertext' => $recheck ? null : Crypt::encryptString($body),
                'approval_token' => null, 'owner_notified_at' => null, 'owner_message_id' => null,
                'owner_notify_retry_at' => null, 'approval_expires_at' => null, 'next_retry_at' => time(),
                'reason' => $recheck ? 'owner_requested_recheck' : 'owner_edited_reply', 'edited_at' => time()]);
            return ['status' => $recheck ? 'recheck_queued' : 'edited_requires_new_approval', 'ticket_id' => $row['ticket_id'],
                'changed' => $old !== $body, 'body' => $body, 'before' => $old];
        });
        if ($result === null) { throw new \DomainException('busy'); }
        if ($result['changed'] && ! $recheck) {
            // A suggestion remains proposed until explicit approval. It cannot authorize infrastructure actions.
            $suggestion = trim((string) ($input['rule'] ?? ''));
            if ($suggestion !== '') { $this->mutate($owner, ['action' => 'rule_propose', 'text' => $suggestion]); }
            $this->store->change($owner, function (array &$state) use ($result, $input): void {
                $key = hash('sha256', $input['token']);
                $state['receipts'][$key] = ['ticket_id' => $result['ticket_id'], 'kind' => 'owner_correction',
                    'at' => time(), 'before' => $result['before'], 'after' => $result['body'],
                    'learning_status' => trim((string) ($input['rule'] ?? '')) !== '' ? 'manual_rule_proposed' : 'pending'];
            });
        }
        unset($result['body'], $result['before'], $result['changed']);
        return $result;
    }

    public function scan(string $provider): array
    {
        if (! $this->enabled() || ! ($owner = $this->owner())) { return ['status' => 'disabled']; }
        if (! array_key_exists($provider, CloudManager::DRIVERS)) { throw new \InvalidArgumentException('provider'); }
        try { $r = $this->inventory->reconcile([$provider]); }
        catch (\Throwable) { $r = ['checked' => [$provider], 'errors' => [$provider => 'unavailable'], 'orphans' => [], 'ghosts' => [], 'attached' => []]; }
        $complete = in_array($provider, $r['checked'], true) && ! isset($r['errors'][$provider]);
        $findings = [];
        if ($complete) {
            foreach (['orphans' => 'unassigned_resource', 'ghosts' => 'service_resource_missing', 'attached' => 'attached'] as $bucket => $code) {
                foreach ($r[$bucket] as $row) {
                    $type = $bucket !== 'attached' ? $code : (! empty($row['service_dead']) ? 'closed_service_still_running'
                        : (! empty($row['ip_mismatch']) ? 'recorded_ip_mismatch' : null));
                    if ($type) { $findings[] = ['code' => $type, 'provider' => $provider, 'ref' => (string) ($row['ref'] ?? $row['provider_ref'] ?? ''),
                        'service_id' => $row['service_id'] ?? null, 'amount' => null, 'currency' => null]; }
                }
            }
        }
        $this->store->change($owner, function (array &$state) use ($provider, $complete, $findings): void {
            HermesOperationsPolicy::reconcileAlerts($state, $provider, $findings, $complete);
            $state['scans'][$provider] = ['at' => time(), 'complete' => $complete, 'finding_count' => $complete ? count($findings) : null,
                'cost_amount' => null, 'cost_currency' => null, 'coverage' => 'virtual_servers_only'];
        });
        $this->scanStorage($owner, $provider, $r, $complete);
        return ['status' => $complete ? 'complete' : 'unavailable', 'finding_count' => $complete ? count($findings) : null];
    }

    private function scanStorage(array $owner, string $provider, array $servers, bool $serverInventoryComplete): void
    {
        $driver = $this->cloud->driver($provider);
        $supported = $driver && method_exists($driver, 'storageInventory');
        try { $storage = $supported ? $driver->storageInventory() : ['ok' => false, 'items' => []]; }
        catch (\Throwable) { $storage = ['ok' => false, 'items' => []]; }
        $complete = $supported && ($storage['ok'] ?? false) === true;
        $findings = [];
        $live = array_fill_keys(array_map(fn ($row) => (string) $row['ref'], array_merge($servers['attached'], $servers['orphans'])), true);
        foreach ($complete ? $storage['items'] : [] as $row) {
            $code = $row['kind'] === 'volume' && $row['server_ref'] === null ? 'detached_volume_review'
                : ($row['kind'] === 'snapshot' && $serverInventoryComplete && $row['server_ref'] !== null
                    && ! isset($live[$row['server_ref']]) ? 'snapshot_origin_missing_review' : null);
            if ($code) { $findings[] = $row + ['code' => $code, 'provider' => $provider.'-storage', 'amount' => null, 'currency' => null]; }
        }
        $this->store->change($owner, function (array &$state) use ($provider, $supported, $complete, $findings, $serverInventoryComplete): void {
            // Storage has its own namespace: a healthy VM inventory cannot dismiss storage findings.
            if ($supported) { HermesOperationsPolicy::reconcileAlerts($state, $provider.'-storage', $findings, $complete && $serverInventoryComplete); }
            $state['storage_scans'][$provider] = ['at' => time(), 'supported' => $supported, 'complete' => $complete, 'server_inventory_complete' => $serverInventoryComplete,
                'finding_count' => $complete ? count($findings) : null, 'cost_amount' => null, 'cost_currency' => null];
        });
    }

    /** Bounded rotating sample. Unknown traffic and unsupported storage are shown as coverage gaps. */
    public function refresh(int $limit = 10): array
    {
        if (! $this->enabled() || ! ($owner = $this->owner())) { return ['status' => 'disabled']; }
        if (Setting::get('hermes_support_learning_enabled') === '1') {
            try { app(HermesSupportExperience::class)->refresh($owner); }
            catch (\Throwable) { $this->store->change($owner, function (array &$s): void {
                $s['support_experience_last_failure_at'] = time();
            }); }
        }
        $state = $this->store->read($owner); $cursor = (int) ($state['traffic_cursor'] ?? 0);
        $services = Service::query()->where('id', '>', $cursor)->whereHas('cloudInstance')->orderBy('id')->limit(max(1, min(25, $limit)))->get();
        if ($services->isEmpty()) { $services = Service::query()->whereHas('cloudInstance')->orderBy('id')->limit(max(1, min(25, $limit)))->get(); }
        foreach ($services as $service) {
            $diagnostic = $this->diagnostics->inspect($service);
            $cloud = collect($diagnostic['checks'])->firstWhere('check', 'cloud_status');
            $plan = $service->cloud_plan_id ? \App\Models\CloudPlan::query()->find($service->cloud_plan_id) : null;
            $alert = HermesOperationsPolicy::traffic(isset($cloud['traffic_used_gb']) ? (float) $cloud['traffic_used_gb'] : null,
                $plan && $plan->traffic_gb > 0 ? (int) $plan->traffic_gb : null);
            $this->store->change($owner, function (array &$state) use ($service, $cloud, $alert): void {
                $key = 'traffic:'.$service->id;
                $businessTraffic = $state['business']['services'][$service->id]['traffic'] ?? [];
                if (! empty($businessTraffic['available']) && time() - ($businessTraffic['at'] ?? 0) <= 7200) {
                    // The business review owns this same risk; retain the older sampler only as fallback.
                    if (isset($state['alerts'][$key])) { $state['alerts'][$key]['active'] = false; }
                }
                elseif ($alert) { HermesOperationsPolicy::alert($state, $key, 'traffic_threshold', $alert + ['service_id' => (int) $service->id]); }
                elseif (isset($state['alerts'][$key]) && ($cloud['result'] ?? '') === 'verified' && isset($cloud['traffic_used_gb'])) {
                    $state['alerts'][$key]['active'] = false;
                }
                $state['traffic_cursor'] = (int) $service->id;
                $state['traffic_last_sample_at'] = time();
                $state['traffic_coverage'] = 'rotating_sample_provider_reported_only';
            });
        }
        $this->refreshTasks($owner);
        $this->learnCorrections($owner);
        return ['status' => 'sampled', 'services_checked' => $services->count()];
    }

    private function learnCorrections(array $owner): void
    {
        $processed = 0;
        foreach ($this->store->read($owner)['receipts'] as $id => $receipt) {
            if (($receipt['learning_status'] ?? '') !== 'pending' || ++$processed > 3) { continue; }
            $rule = app(HermesSupportDraftWriter::class)->suggestRule($receipt['before'], $receipt['after']);
            $this->store->change($owner, function (array &$state) use ($id, $rule): void {
                if (($state['receipts'][$id]['learning_status'] ?? '') !== 'pending') { return; }
                $state['receipts'][$id]['learning_status'] = $rule ? 'proposed' : 'no_reusable_rule';
                if ($rule) {
                    $key = hash('sha256', 'feedback:'.$id);
                    $state['rules'][$key] = ['id' => $key, 'text' => $rule, 'scope' => 'support_reference',
                        'status' => 'proposed', 'revision' => 1, 'source' => 'owner_correction', 'created_at' => time()];
                }
                unset($state['receipts'][$id]['before'], $state['receipts'][$id]['after']);
            });
        }
    }

    private function refreshTasks(array $owner): void
    {
        $tasks = array_slice($this->store->read($owner)['tasks'], -200, null, true);
        foreach ($tasks as $key => $task) {
            $ticket = Ticket::query()->find($task['ticket_id']);
            if (! $ticket) { continue; }
            $message = $ticket->visibleMessages()->orderByDesc('id')->first();
            $next = match ($ticket->status) { 'closed' => 'resolved', 'answered' => 'waiting_customer', 'held' => 'waiting_owner', default => 'investigating' };
            if ($ticket->status === 'open' && (int) ($task['message_id'] ?? 0) === (int) $message?->id) {
                $next = $task['status']; // Preserve explicit owner decisions while evidence is unchanged.
            }
            $this->store->change($owner, function (array &$state) use ($key, $task, $next, $message): void {
                if (($state['tasks'][$key]['revision'] ?? 0) !== $task['revision']) { return; }
                HermesOperationsPolicy::task($state, $key, ['status' => $next, 'message_id' => (int) $message?->id]);
            });
        }
    }

    public function digest(bool $daily = true): string
    {
        if (! $this->enabled() || ! ($owner = $this->owner())) { return 'disabled'; }
        $state = $this->store->read($owner);
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format($daily ? 'Y-m-d' : 'Y-m-d-H');
        $key = ($daily ? 'daily:' : 'alerts:').$day;
        $alerts = array_filter($state['alerts'], fn ($a) => $a['active'] && empty($a['acknowledged_at']));
        if (! $daily && ! $alerts) { return 'no_alerts'; }
        $fingerprint = HermesBusinessReport::fingerprint($alerts);
        if (! $daily && ($state['last_alert_fingerprint'] ?? null) === $fingerprint) { return 'unchanged_alerts'; }
        $counts = array_count_values(array_column($state['tasks'], 'status'));
        $coverage = count(array_filter($state['scans'], fn ($s) => $s['complete'] && time() - $s['at'] < 7200));
        $text = $daily ? "📋 گزارش روزانه هرمس\n" : "⚠️ موارد نیازمند بررسی هرمس\n";
        $text .= 'منتظر تصمیم تو: '.($counts['waiting_owner'] ?? 0).' | در حال بررسی: '.($counts['investigating'] ?? 0)
            .' | منتظر مشتری: '.($counts['waiting_customer'] ?? 0).' | حل‌شده در فهرست پیگیری: '.($counts['resolved'] ?? 0)
            ."\nهشدار فعال: ".count($alerts).' | موجودی تازه و کامل: '.$coverage." زیرساخت\n";
        $text .= HermesBusinessReport::text($state)."\n";
        $text .= HermesAlertSummary::render($alerts, HermesBusinessReport::LABELS);
        $text .= 'جزئیات در مینی‌اپ ← عملیات. مبلغ هزینه و مصرف ناموجود، صفر فرض نشده‌اند. تغییر زیرساختی اجرا نشده است.';
        $claimed = $this->store->change($owner, function (array &$s) use ($key): bool {
            if (isset($s['digests'][$key])) { return false; }
            $s['digests'][$key] = ['status' => 'dispatching', 'at' => time()]; return true;
        });
        if (! $claimed) { return 'already_claimed'; }
        try { $this->assertOwner($owner); $ok = $this->sender->send($owner['chat_id'], $text); }
        catch (\Throwable) { $ok = false; }
        $this->store->change($owner, function (array &$s) use ($key, $ok, $daily, $fingerprint): void {
            $s['digests'][$key]['status'] = $ok ? 'sent' : 'uncertain';
            if (! $daily) { $s['last_alert_fingerprint'] = $fingerprint; }
        });
        return $ok ? 'sent' : 'uncertain';
    }

    private function assertOwner(array $owner): void
    {
        if ($this->owner() !== $owner) { throw new \DomainException('owner_changed'); }
    }

    private function safeText(string $text, int $limit): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > $limit || strip_tags($text) !== $text
            || (new HermesSupportPolicy())->classify('', $text, 'technical') === 'credentials') { throw new \InvalidArgumentException('invalid_text'); }
        return $text;
    }
}
