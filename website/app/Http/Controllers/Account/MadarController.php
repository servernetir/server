<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\MadarMessage;
use App\Models\MadarParticipation;
use App\Models\MarketingRedemption;
use App\Services\Madar\MadarClaims;
use App\Services\Madar\MadarEvidence;
use App\Services\Madar\MadarHub;
use App\Services\Madar\MadarRewards;
use App\Services\Marketing\DiscountEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MadarController extends Controller
{
    public function index(MadarHub $hub)
    {
        return response()->view('account.madar.index', AccountController::shell('madar') + ['ready' => $hub->ready(),
            'campaigns' => $hub->campaigns(Auth::guard('customer')->user()), 'catalogReady' => $hub->catalogReady(), 'requestKey' => $hub->newKey(),
            'participations' => $hub->ready() ? MadarParticipation::where('customer_id', Auth::guard('customer')->id())
                ->latest('updated_at')->paginate(15) : collect()])->header('Cache-Control', 'private, no-store');
    }

    public function apply(Request $request, MadarHub $hub)
    {
        abort(410); // Initial negotiation is retired; customers submit completed published work.
    }

    public function claim(Request $request, MadarClaims $claims)
    {
        $data = $request->validate(MadarEvidence::rules() + ['request_key' => 'required|uuid', 'mission_id' => 'required|integer',
            'title' => 'required|string|min:5|max:180', 'evidence_url' => 'nullable|string|max:1500', 'distribution_url' => 'nullable|string|max:1500',
            'rights_confirmed' => 'accepted', 'disclosure_confirmed' => 'sometimes|accepted', 'terms' => 'accepted', 'terms_version' => 'required|string|max:40']);
        $p = $claims->submit(Auth::guard('customer')->user(), $data, $request->file('attachment'));

        return redirect()->to(lroute('account.madar.show', $p))->with('ok', __('madar.submitted'));
    }

    private function owned(MadarParticipation $participation): void
    {
        abort_unless((int) $participation->customer_id === (int) Auth::guard('customer')->id(), 404);
    }

    public function show(MadarParticipation $participation)
    {
        $this->owned($participation);
        DB::table('madar_participations')->where('id', $participation->id)->update(['customer_read_at' => now()]);

        $messages = $participation->messages()->latest('id')->paginate(40);
        $messages->setCollection($messages->getCollection()->reverse());

        return response()->view('account.madar.show', AccountController::shell('madar') + ['participation' => $participation->load('grant'),
            'messages' => $messages,
            'redemption' => $participation->grant_id ? MarketingRedemption::where('grant_id', $participation->grant_id)->latest('id')->first() : null,
            'invoices' => $participation->status === 'rewarded'
                ? Invoice::where('customer_id', $participation->customer_id)->where('status', 'unpaid')
                    ->where('kind', '!=', 'topup')->latest('id')->limit(30)->get() : collect()])->header('Cache-Control', 'private, no-store');
    }

    public function message(Request $request, MadarParticipation $participation, MadarEvidence $evidence)
    {
        $this->owned($participation);
        $data = $request->validate(MadarEvidence::rules());
        $evidence->write($participation, 'customer', Auth::guard('customer')->id(), $data, $request->file('attachment'));

        return back()->with('ok', __('madar.message_sent'));
    }

    public function accept(Request $request, MadarParticipation $participation, MadarRewards $rewards)
    {
        $this->owned($participation);
        $request->validate(['accept_offer' => 'accepted']);
        $rewards->accept($participation);

        return back()->with('ok', __('madar.offer_accepted'));
    }

    public function submit(Request $request, MadarParticipation $participation, MadarEvidence $evidence)
    {
        $this->owned($participation);
        $data = $request->validate(MadarEvidence::rules() + ['evidence_url' => 'nullable|string|max:1500', 'distribution_url' => 'nullable|string|max:1500',
            'rights_confirmed' => 'accepted', 'disclosure_confirmed' => 'sometimes|accepted']);
        $evidence->write($participation, 'customer', Auth::guard('customer')->id(), $data, $request->file('attachment'), true);

        return back()->with('ok', __('madar.submitted'));
    }

    public function cancel(Request $request, MadarParticipation $participation, MadarRewards $rewards)
    {
        $this->owned($participation);
        $data = $request->validate(['body' => 'required|string|min:5|max:1000']);
        $rewards->close($participation, 'cancelled', $data['body'], 'customer', Auth::guard('customer')->id());

        return back()->with('ok', __('madar.saved'));
    }

    public function redeem(Request $request, MadarParticipation $participation, DiscountEngine $engine)
    {
        $this->owned($participation);
        abort_unless($participation->status === 'rewarded' && $participation->grant_id, 422);
        $data = $request->validate(['invoice_id' => 'required|integer']);
        $invoice = Invoice::where('customer_id', $participation->customer_id)->findOrFail($data['invoice_id']);
        // This explicit earned-reward action does not enable generic coupon entry for the customer.
        $engine->reserve($invoice, Auth::guard('customer')->user(), $participation->grant->code);

        return redirect()->to(lroute('account.invoice', $invoice))->with('ok', __('marketing.reserved_ok'));
    }

    public function attachment(MadarParticipation $participation, MadarMessage $message)
    {
        $this->owned($participation);

        return self::download($participation, $message);
    }

    public static function download(MadarParticipation $participation, MadarMessage $message)
    {
        abort_unless((int) $message->participation_id === $participation->id && filled($message->attachment_path), 404);
        abort_unless(Storage::disk('local')->exists($message->attachment_path), 404);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$message->attachment_mime] ?? 'bin';

        return Storage::disk('local')->download($message->attachment_path, 'madar-evidence.'.$ext,
            ['Content-Type' => $message->attachment_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
