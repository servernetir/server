const d = $('Evaluate Response').first().json;

// Return only stable, allowlisted classifications. Raw provider messages can
// contain sensitive details and are never valid public error identifiers.
const allowed = ['rate_limited', 'provider_failed', 'upstream_rejected'];
const reason = allowed.includes(d.failure_reason) ? d.failure_reason : 'upstream_rejected';

return [{ json: {
  status: d.delivered ? 'sent' : 'failed',
  template: d.template,
  request_id: d.request_id,
  ...(d.outbox_id ? { outbox_id: d.outbox_id } : {}),
  ...(d.delivered ? {} : { reason }),
} }];
