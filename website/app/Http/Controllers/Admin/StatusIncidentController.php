<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudLocation;
use App\Models\StatusIncident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * اعلانِ اختلال روی صفحهٔ وضعیت.
 *
 * چرا این صفحه ارزش دارد: در یک اختلالِ واقعی، گران‌ترین چیز سکوت است. مشتری
 * از توییتر خبردار می‌شود، پشتیبانی زیر بارِ تیکتِ تکراری می‌رود، و تعهدِ
 * آپتایم بی‌پشتوانه می‌مانَد چون هیچ ثبتی از رویداد نداریم. یک اعلانِ کوتاهِ
 * به‌موقع، هم تیکت را کم می‌کند هم بعداً مدرکِ خودمان است.
 */
class StatusIncidentController extends Controller
{
    public function index(): View
    {
        return view('admin.status', [
            'open'    => StatusIncident::query()->open()->orderByDesc('started_at')->get(),
            'past'    => StatusIncident::query()->whereNotNull('resolved_at')
                ->orderByDesc('started_at')->limit(40)->get(),
            'states'  => StatusIncident::STATES,
            'impacts' => StatusIncident::IMPACTS,
            'locations' => CloudLocation::orderBy('country')->orderBy('city')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        StatusIncident::create($data + [
            'started_at' => $data['started_at'] ?? now(),
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('ok', 'اختلال اعلام شد و روی صفحهٔ وضعیت دیده می‌شود.');
    }

    public function update(Request $request, StatusIncident $incident): RedirectResponse
    {
        $data = $this->validated($request);

        // «برطرف شد» یعنی زمانِ پایان هم ثبت شود — وگرنه ردیف تا ابد «باز»
        // می‌مانَد و صفحهٔ وضعیت به مشتری می‌گوید هنوز مشکلی هست.
        if ($data['state'] === 'resolved' && $incident->resolved_at === null) {
            $data['resolved_at'] = now();
        }

        // و برعکس: اگر دوباره بازش کردیم، زمانِ پایان باید پاک شود
        if ($data['state'] !== 'resolved') {
            $data['resolved_at'] = null;
        }

        $incident->update($data);

        return back()->with('ok', 'به‌روزرسانی شد.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title'       => ['required', 'string', 'max:200'],
            'state'       => ['required', Rule::in(array_keys(StatusIncident::STATES))],
            'impact'      => ['required', Rule::in(array_keys(StatusIncident::IMPACTS))],
            'body'        => ['nullable', 'string', 'max:4000'],
            'locations'   => ['nullable', 'array'],
            'locations.*' => ['string', 'max:60'],
            'started_at'  => ['nullable', 'date'],
        ], [], [
            'title' => 'عنوان', 'state' => 'مرحله', 'impact' => 'شدت',
            'body' => 'توضیح', 'started_at' => 'زمان شروع',
        ]);
    }
}
