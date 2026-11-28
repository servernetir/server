@php
    $partUrl = lroute('parts.show', [$quotePart->category, $quotePart->slug]);
    $partLabel = $quotePart->label();
    $quoteSubject = __('part_finder.quote_subject', ['part' => mb_substr($partLabel, 0, 160)]);
    $quoteLines = [$quoteSubject, $partUrl];
    if ($quotePartNumbers !== []) {
        $quoteLines[] = __('part_finder.part_number').': '.implode(', ', $quotePartNumbers);
    }
    $quoteLines[] = '';
    $quoteLines[] = __('part_finder.quote_quantity').': ';
    $quoteLines[] = __('part_finder.quote_server').': ';
    $quoteLines[] = __('part_finder.quote_condition').': ';
    $quoteLines[] = '';
    $quoteLines[] = __('part_finder.quote_details');
    $quoteMailto = 'mailto:'.$contact['sales_email'].'?subject='.rawurlencode($quoteSubject)
        .'&body='.rawurlencode(implode("\r\n", $quoteLines));
@endphp
<section class="ct-form-wrap" id="hardware-quote" aria-labelledby="hardware-quote-heading" style="scroll-margin-top:8rem;overflow-wrap:anywhere">
  <h2 id="hardware-quote-heading">{{ __('part_finder.quote_heading') }}</h2>
  <p><a href="{{ $partUrl }}"><bdi>{{ $partLabel }}</bdi></a></p>
  @if($quotePartNumbers !== [])
    <p>{{ __('part_finder.part_number') }}: <bdi>{{ implode(', ', $quotePartNumbers) }}</bdi></p>
  @endif
  <p>{{ __('part_finder.quote_intro') }}</p>
  <div class="ct-row">
    <a class="btn btn-primary" href="{{ $quoteMailto }}">{{ __('part_finder.quote_email') }}</a>
    <a class="btn btn-glass" href="tel:{{ $contact['phone_link'] }}">{{ __('ui.srv_call') }}</a>
  </div>
  <p>{{ __('part_finder.quote_email_hint') }} <bdi>{{ $contact['sales_email'] }}</bdi></p>
  <p><a href="{{ lroute('contact').'#form' }}">{{ __('part_finder.general_contact') }}</a></p>
</section>
