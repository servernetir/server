<section class="sn-email-choice container" aria-labelledby="email-choice-title">
  <h2 id="email-choice-title">{{ __('transactional-email.choice_title') }}</h2>
  <div class="sn-email-choice-grid">
    <article>
      <span class="sn-email-choice-icon"><svg class="icon" aria-hidden="true"><use href="#i-mail"/></svg></span>
      <div><h3>{{ __('transactional-email.choice_mailbox') }}</h3><p>{{ __('transactional-email.choice_mailbox_desc') }}</p>
        <a class="btn btn-glass" href="#pricing">{{ __('transactional-email.choice_mailbox_cta') }}</a>
      </div>
    </article>
    <article class="sn-email-choice-highlight">
      <span class="sn-email-choice-icon"><svg class="icon" aria-hidden="true"><use href="#i-send"/></svg></span>
      <div><span class="sn-email-choice-platform" dir="ltr">Amazon SES</span><h3>{{ __('transactional-email.choice_transactional') }}</h3>
        <p>{{ __('transactional-email.choice_transactional_desc') }}</p>
        <a class="btn btn-primary" href="{{ lroute('email.transactional') }}">{{ __('transactional-email.choice_transactional_cta') }}</a>
      </div>
    </article>
  </div>
</section>
