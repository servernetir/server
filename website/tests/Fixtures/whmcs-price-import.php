<?php

namespace ServerNet\Registrar {
    final class PriceImportTransport
    {
        public static array $rows = [];
        public static int $calls = 0;
    }
    // Namespace-scoped transport fixture prevents any native cURL request.
    function curl_init() { return new \stdClass(); }
    function curl_setopt_array($handle, array $options) { return true; }
    function curl_setopt($handle, $option, $value) { return true; }
    function curl_exec($handle) {
        PriceImportTransport::$calls++;
        return json_encode(['ok' => true, 'data' => PriceImportTransport::$rows]);
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}

namespace WHMCS\Results {
    class ResultsList extends \ArrayObject {}
}

namespace WHMCS\Domain\TopLevel {
    class ImportItem
    {
        public array $values = [];
        public function __call(string $method, array $args): self
        {
            $this->values[$method] = $args[0];
            return $this;
        }
    }
}
