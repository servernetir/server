@if(\App\Services\Cloud\ManagedN8nCatalog::handles($service))
  @include('account.partials.card-n8n', ['service' => $service, 'instance' => $instance, 'compact' => false])
@endif
