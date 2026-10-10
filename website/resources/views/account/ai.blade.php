@extends('panel.layout')
@section('title',__('ai.usage').' — ServerNet')
@section('noindex','1')
@section('panel')
<div class="pnl-head"><div><h1>{{ __('ai.usage') }}</h1><p>{{ __('ai.month') }}: <strong>{{ number_format($spent) }}</strong></p></div></div>
<div style="display:flex;gap:12px;flex-wrap:wrap;margin:20px 0"><a class="pnl-btn" href="{{ lroute('account.security') }}#sec-ai">{{ __('ai.key') }}</a><a class="pnl-btn" href="{{ lroute('account.topup') }}">{{ __('ai.topup') }}</a><a class="pnl-btn" href="{{ lroute('ai.docs') }}">{{ __('ai.docs') }}</a><a class="pnl-btn" href="{{ lroute('ai.models') }}">{{ __('ai.catalog') }}</a></div>
<section class="pnl-sec"><div class="pnl-sec-h"><h2>{{ __('ai.projects') }}</h2></div><div class="pnl-sec-b">@forelse($projects as $project)<p><strong>{{ $project->name }}</strong> · {{ __('ui.sec_ai_st_'.$project->status) }} · {{ __('ui.sec_ai_keys_chip',['n'=>$project->active_tokens_count]) }}</p>
@empty
<p>{{ __('ai.no_projects') }}</p>
@endforelse
</div></section>
<section class="pnl-sec"><div class="pnl-sec-h"><h2>{{ __('ai.history') }}</h2></div><div class="pnl-sec-b" style="overflow:auto"><table style="width:100%;border-collapse:collapse"><thead><tr>@foreach(['date','detail','status','amount','request'] as $label)<th style="padding:12px;text-align:start">{{ __('ai.'.$label) }}</th>
@endforeach
</tr></thead><tbody>@forelse($history as $usage)<tr><td style="padding:12px;white-space:nowrap">{{ $usage->created_at->format('Y-m-d H:i') }}</td><td dir="ltr">{{ $usage->model_slug }}<br><small>{{ number_format($usage->prompt_tokens ?? 0) }} / {{ number_format($usage->completion_tokens ?? 0) }}</small></td><td>{{ __('ai.state_'.$usage->status) }}</td><td>{{ number_format((int)$usage->charged_irt-(int)$usage->refunded_irt) }}</td><td dir="ltr"><code>{{ $usage->public_id }}</code></td></tr>
@empty
<tr><td colspan="5" style="padding:24px">{{ __('ai.no_usage') }}</td></tr>
@endforelse
</tbody></table>{{ $history->links() }}</div></section>

@endsection
