<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Account\MadarController as CustomerMadar;
use App\Http\Controllers\Controller;
use App\Models\MadarMessage;
use App\Models\MadarParticipation;
use App\Models\MarketingCampaign;
use App\Services\Madar\MadarEvidence;
use App\Services\Madar\MadarHub;
use App\Services\Madar\MadarReview;
use App\Services\Madar\MadarRewards;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MadarController extends Controller
{
    public function index(Request $request, MadarHub $hub)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(MadarParticipation::STATUSES)],
            'campaign' => ['nullable', Rule::in(array_keys(config('madar.campaigns')))]]);
        $query = MadarParticipation::query();
        foreach (['status' => 'status', 'campaign' => 'campaign_key'] as $key => $column) {
            if (! empty($data[$key])) {
                $query->where($column, $data[$key]);
            }
        }

        return response()->view('admin.madar.index', ['ready' => $hub->ready(), 'campaigns' => $hub->campaigns(),
            'participations' => $hub->ready() ? $query->with('customer')->orderByRaw('staff_read_at IS NULL DESC')->latest('updated_at')->paginate(25)->withQueryString() : collect(),
            'rewardCampaigns' => Schema::hasTable('marketing_campaigns') ? MarketingCampaign::where('status', 'active')
                ->where('trigger', 'manual')->where('currency_code', 'IRT')->orderByDesc('id')->get() : collect(),
            'counts' => $hub->ready() ? MadarParticipation::select('status')->selectRaw('count(*) AS total')->groupBy('status')->pluck('total', 'status') : collect()])->header('Cache-Control', 'private, no-store');
    }

    public function settings(Request $request, MadarHub $hub)
    {
        abort_unless($hub->ready(), 503);
        $data = $request->validate(['campaign' => ['required', Rule::in(array_keys(config('madar.campaigns')))],
            'open' => 'required|boolean', 'reward_campaign_id' => 'nullable|integer|exists:marketing_campaigns,id']);
        DB::table('madar_campaigns')->where('campaign_key', $data['campaign'])->update(['open' => $data['open'],
            'reward_campaign_id' => $data['reward_campaign_id'] ?? null, 'updated_by' => $request->user('web')->id, 'updated_at' => now()]);

        return back()->with('ok', __('madar.saved'));
    }

    public function show(MadarParticipation $participation, MadarReview $review, MadarHub $hub)
    {
        DB::table('madar_participations')->where('id', $participation->id)->update(['staff_read_at' => now()]);

        $messages = $participation->messages()->latest('id')->paginate(40);
        $messages->setCollection($messages->getCollection()->reverse());

        return response()->view('admin.madar.show', ['participation' => $participation->load('customer', 'grant'),
            'messages' => $messages, 'checklist' => $review->checklist($participation),
            'campaign' => $hub->campaigns()[$participation->campaign_key]])->header('Cache-Control', 'private, no-store');
    }

    public function message(Request $request, MadarParticipation $participation, MadarEvidence $evidence)
    {
        $data = $request->validate(MadarEvidence::rules());
        $evidence->write($participation, 'staff', $request->user('web')->id, $data, $request->file('attachment'));

        return back()->with('ok', __('madar.message_sent'));
    }

    public function offer(Request $request, MadarParticipation $participation, MadarRewards $rewards)
    {
        $data = $request->validate(['body' => 'required|string|min:10|max:5000', 'work_days' => 'required|integer|min:1|max:14']);
        $id = DB::table('madar_campaigns')->where('campaign_key', $participation->campaign_key)->value('reward_campaign_id');
        app(MadarHub::class)->check($id !== null, 'reward_campaign');
        $rewards->offer($participation, $request->user('web'), (int) $id, (int) $data['work_days'], $data['body']);

        return back()->with('ok', __('madar.saved'));
    }

    public function review(Request $request, MadarParticipation $participation, MadarRewards $rewards)
    {
        $data = $request->validate(['action' => 'required|in:approve,changes', 'body' => 'required|string|min:10|max:5000',
            'human_reviewed' => 'accepted']);
        $rewards->review($participation, $request->user('web'), $data['action'], $data['body']);

        return back()->with('ok', __('madar.saved'));
    }

    public function extend(Request $request, MadarParticipation $participation, MadarRewards $rewards)
    {
        $data = $request->validate(['work_days' => 'required|integer|min:1|max:14', 'body' => 'required|string|min:10|max:1000']);
        $rewards->extend($participation, $request->user('web'), (int) $data['work_days'], $data['body']);

        return back()->with('ok', __('madar.saved'));
    }

    public function reject(Request $request, MadarParticipation $participation, MadarRewards $rewards)
    {
        $data = $request->validate(['body' => 'required|string|min:10|max:1000']);
        $rewards->close($participation, 'rejected', $data['body'], 'staff', $request->user('web')->id);

        return back()->with('ok', __('madar.saved'));
    }

    public function attachment(MadarParticipation $participation, MadarMessage $message)
    {
        return CustomerMadar::download($participation, $message);
    }
}
