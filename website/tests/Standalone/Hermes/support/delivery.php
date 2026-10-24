<?php
/** Isolated delivery adapter tests: fake DB/models/notifier, no Laravel or live keys. */
declare(strict_types=1);

namespace HermesDeliveryTest {
    final class Store
    {
        public static array $events = [];
        public static object $ticket;
        public static object $latest;
        public static ?string $failure = null;
        public static object $connection;
    }
    final class Connection
    {
        public int $level = 0;
        public string $driver = 'mysql';
        public function transactionLevel(): int { return $this->level; }
        public function getDriverName(): string { return $this->driver; }
        public function beginTransaction(): void { Store::$events[] = 'begin'; ++$this->level; }
        public function commit(): void { Store::$events[] = 'commit'; --$this->level; }
        public function rollBack(): void { Store::$events[] = 'rollback'; --$this->level; }
    }
    final class Query
    {
        public function __construct(private bool $ticket = false) {}
        public function lockForUpdate(): self { Store::$events[] = $this->ticket ? 'lock_ticket' : 'lock_message'; return $this; }
        public function find(int $id): object { Store::$events[] = 'find_ticket'; return Store::$ticket; }
        public function orderByDesc(string $field): self { return $this; }
        public function limit(int $limit): self { return $this; }
        public function first(): object { Store::$events[] = 'read_latest'; return Store::$latest; }
        public function get(): Collection { return new Collection([Store::$latest]); }
    }
    final class Collection
    {
        public function __construct(private array $items) {}
        public function map(callable $callback): self { return new self(array_map($callback, $this->items)); }
        public function implode(string $separator): string { return implode($separator, $this->items); }
    }
}

namespace Illuminate\Support\Facades {
    final class DB
    {
        public static function connection(): object { return \HermesDeliveryTest\Store::$connection; }
    }
}

namespace App\Models {
    final class Ticket
    {
        public int $id = 21;
        public int $customer_id = 7;
        public string $status = 'open';
        public string $subject = 'اشکال سرویس';
        public string $department = 'technical';
        public object $customer;
        public function __construct() { $this->customer = (object) ['id' => 7]; }
        public static function query(): \HermesDeliveryTest\Query { return new \HermesDeliveryTest\Query(true); }
        public function visibleMessages(): \HermesDeliveryTest\Query { return new \HermesDeliveryTest\Query(); }
    }
}

namespace App\Services\Ticket {
    class TicketReplyService
    {
        public function post(object $ticket, ?int $authorId, ?string $authorName, string $body, bool $internal = false, bool $close = false, ?callable $afterMessage = null): object
        {
            if ($internal || $close || \HermesDeliveryTest\Store::$connection->transactionLevel() !== 1) {
                throw new \RuntimeException('Unsafe insertion flags or missing transaction');
            }
            \HermesDeliveryTest\Store::$events[] = 'insert_message';
            if (\HermesDeliveryTest\Store::$failure === 'insert') {
                throw new \RuntimeException('Simulated insertion failure');
            }
            $message = (object) ['id' => 99];
            $afterMessage($message);
            if (\HermesDeliveryTest\Store::$connection->transactionLevel() !== 0) {
                throw new \RuntimeException('Notification before commit');
            }
            \HermesDeliveryTest\Store::$events[] = 'notify';
            if (\HermesDeliveryTest\Store::$failure === 'notification') {
                throw new \RuntimeException('Simulated notification failure');
            }
            return $message;
        }
    }
}

namespace {
    use App\Models\Ticket;
    use App\Services\Hermes\HermesSupportDelivery;
    use App\Services\Hermes\HermesSupportPolicy;
    use App\Services\Ticket\TicketReplyService;
    use HermesDeliveryTest\Connection;
    use HermesDeliveryTest\Store;

    $source = dirname(__DIR__, 4).'/app/Services/Hermes/';
    require $source.'HermesSupportPolicy.php';
    require $source.'HermesSupportDelivery.php';

    function check(bool $condition, string $message = 'check failed'): void
    {
        if (! $condition) { throw new RuntimeException($message); }
    }
    function fixture(): HermesSupportDelivery
    {
        Store::$events = [];
        Store::$connection = new Connection();
        Store::$ticket = new Ticket();
        Store::$latest = (object) ['id' => 42, 'author_role' => 'customer', 'body' => 'سرویس من باز نمی شود'];
        Store::$failure = null;
        return new HermesSupportDelivery(new TicketReplyService(), new HermesSupportPolicy());
    }
    function written(int $id): void
    {
        check($id === 99);
        check(Store::$connection->transactionLevel() === 0, 'State callback before commit');
        Store::$events[] = 'written';
    }
    $tests = [];
    $tests['lock canonical ticket, commit insertion before notification'] = function (): void {
        $delivery = fixture();
        check($delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written') === 99);
        check(Store::$events === ['begin', 'lock_ticket', 'find_ticket', 'lock_message', 'read_latest', 'insert_message', 'commit', 'written', 'notify']);
    };
    $tests['MariaDB uses the same guarded commit-before-notification path'] = function (): void {
        $delivery = fixture(); Store::$connection->driver = 'mariadb';
        check($delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written') === 99);
        check(Store::$events === ['begin', 'lock_ticket', 'find_ticket', 'lock_message', 'read_latest', 'insert_message', 'commit', 'written', 'notify']);
    };
    $tests['changed customer prevents insertion and notification'] = function (): void {
        $delivery = fixture(); Store::$ticket->customer_id = 8;
        check($delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written') === null);
        check(in_array('rollback', Store::$events, true) && ! in_array('insert_message', Store::$events, true));
    };
    $tests['changed latest message prevents insertion'] = function (): void {
        $delivery = fixture(); Store::$latest->id = 43;
        check($delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written') === null);
        check(! in_array('insert_message', Store::$events, true));
    };
    $tests['closed ticket prevents insertion'] = function (): void {
        $delivery = fixture(); Store::$ticket->status = 'closed';
        check($delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written') === null);
        check(! in_array('insert_message', Store::$events, true));
    };
    $tests['changed financial subject prevents insertion at final boundary'] = function (): void {
        $delivery = fixture(); Store::$ticket->subject = 'استرداد وجه';
        check($delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written') === null);
        check(! in_array('insert_message', Store::$events, true));
    };
    $tests['insertion failure rolls back and never notifies'] = function (): void {
        $delivery = fixture(); Store::$failure = 'insert'; $failed = false;
        try { $delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written'); } catch (Throwable) { $failed = true; }
        check($failed && in_array('rollback', Store::$events, true) && ! in_array('notify', Store::$events, true));
    };
    $tests['notification failure leaves the committed message intact'] = function (): void {
        $delivery = fixture(); Store::$failure = 'notification'; $failed = false;
        try { $delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written'); } catch (Throwable) { $failed = true; }
        check($failed && in_array('commit', Store::$events, true) && in_array('written', Store::$events, true) && ! in_array('rollback', Store::$events, true));
    };
    $tests['nested transactions are rejected before delivery'] = function (): void {
        $delivery = fixture(); Store::$connection->level = 1; $failed = false;
        try { $delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written'); } catch (Throwable) { $failed = true; }
        check($failed && Store::$events === []);
    };
    $tests['unsupported driver is rejected before delivery'] = function (): void {
        $delivery = fixture(); Store::$connection->driver = 'sqlite'; $failed = false;
        try { $delivery->post(21, 7, 42, HermesSupportPolicy::CLARIFICATION, 'written'); } catch (Throwable) { $failed = true; }
        check($failed && Store::$events === []);
    };
    $results = [];
    foreach ($tests as $name => $test) {
        try { $test(); $results[] = ['name' => $name, 'ok' => true]; }
        catch (Throwable $e) { $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()]; }
    }
    $failed = count(array_filter($results, static fn (array $r): bool => ! $r['ok']));
    echo json_encode(['tests' => count($results), 'failed' => $failed, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
    exit($failed === 0 ? 0 : 1);
}
