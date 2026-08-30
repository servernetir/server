<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuOverride;
use App\Services\MenuManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Validator;

/**
 * ویرایشِ منوی هدر و فوتر از پنل.
 *
 * ═══ 🔴 اعتبارسنجی، چون فوتر روی هر صفحه است ═══
 *
 * لینکی که مدیر اضافه می‌کند، در فوتر روی **تمامِ** صفحاتِ سایت می‌نشیند. یک
 * مقصدِ بد یعنی سایتِ ۵۰۰ (مرداد ۱۴۰۵ دقیقاً همین افتاد). پس دو لایه:
 *
 * ۱) این‌جا: نامِ روت باید واقعاً وجود داشته باشد و نشانی باید امن باشد؛
 *    خطا همان لحظه به مدیر نشان داده می‌شود.
 * ۲) `MenuManager`: هنگامِ رندر، هر مقصدِ ناساختنی **رد** می‌شود.
 *
 * لایهٔ دوم جایگزینِ اول نیست: روتی که امروز هست، با دیپلویِ فردا ممکن است
 * نباشد و آن‌وقت هیچ‌کس چیزی در پنل عوض نکرده ولی سایت می‌افتد.
 *
 * ═══ ⚠️ اعتبارسنجیِ صریح ═══
 *
 * `shouldRenderJsonWhen(api/*)` یعنی `$request->validate()` در `/admin` به‌جای
 * ۴۲۲ یک ریدایرکتِ HTML می‌دهد. پس مثلِ `CalendarController::check()` خودمان
 * `Validator` را صدا می‌زنیم.
 */
class MenuController extends Controller
{
    /** ذخیرهٔ یک گره: متنِ سه‌زبانه، ترتیب، روشن/خاموش. */
    public function save(Request $request): RedirectResponse
    {
        abort_unless(optional($request->user())->isAdmin(), 403);

        $v = Validator::make($request->all(), [
            'path'     => ['required', 'string', 'max:191'],
            'menu'     => ['required', 'string', 'in:'.implode(',', MenuOverride::MENUS)],
            'sort'     => ['nullable', 'integer', 'between:-999,999'],
            'label_fa' => ['nullable', 'string', 'max:120'],
            'label_en' => ['nullable', 'string', 'max:120'],
            'label_tr' => ['nullable', 'string', 'max:120'],
            'desc_fa'  => ['nullable', 'string', 'max:190'],
            'desc_en'  => ['nullable', 'string', 'max:190'],
            'desc_tr'  => ['nullable', 'string', 'max:190'],
        ]);

        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        $data = $v->validated();

        $row = MenuOverride::firstOrNew(['path' => $data['path']]);
        $row->fill($data);
        $row->menu = $data['menu'];
        $row->updated_by = $request->user()->id;

        if (! $row->exists) {
            $row->visible = true;
        }

        $row->save();

        MenuManager::forget();

        return back()->with('ok', 'منو به‌روز شد.');
    }

    /** روشن/خاموش با یک کلیک. */
    public function toggle(Request $request, MenuOverride $override): RedirectResponse
    {
        abort_unless(optional($request->user())->isAdmin(), 403);

        $override->visible = ! $override->visible;
        $override->updated_by = $request->user()->id;
        $override->save();

        MenuManager::forget();

        return back()->with('ok', $override->visible ? 'لینک روشن شد.' : 'لینک خاموش شد.');
    }

    /**
     * خاموش‌کردنِ گره‌ای که هنوز ردیف ندارد.
     *
     * ⚠️ گره‌های config پیش‌فرض ردیف ندارند؛ بی‌این مسیر، مدیر برای خاموش‌کردنِ
     * یک لینک اول باید متنی را ذخیره می‌کرد تا ردیف ساخته شود.
     */
    public function hide(Request $request): RedirectResponse
    {
        abort_unless(optional($request->user())->isAdmin(), 403);

        $v = Validator::make($request->all(), [
            'path' => ['required', 'string', 'max:191'],
            'menu' => ['required', 'string', 'in:'.implode(',', MenuOverride::MENUS)],
        ]);

        if ($v->fails()) {
            return back()->withErrors($v);
        }

        $row = MenuOverride::firstOrNew(['path' => $request->string('path')->toString()]);
        $row->menu = $request->string('menu')->toString();
        $row->visible = $row->exists ? ! $row->visible : false;
        $row->updated_by = $request->user()->id;
        $row->save();

        MenuManager::forget();

        return back()->with('ok', $row->visible ? 'لینک روشن شد.' : 'لینک خاموش شد.');
    }

    /** لینکِ تازه‌ای که در کد نیست. */
    public function add(Request $request): RedirectResponse
    {
        abort_unless(optional($request->user())->isAdmin(), 403);

        $v = Validator::make($request->all(), [
            'menu'     => ['required', 'string', 'in:'.implode(',', MenuOverride::MENUS)],
            'scope'    => ['nullable', 'string', 'max:60'],
            'label_fa' => ['required', 'string', 'max:120'],
            'label_en' => ['nullable', 'string', 'max:120'],
            'label_tr' => ['nullable', 'string', 'max:120'],
            'target'   => ['required', 'string', 'max:190'],
            'sort'     => ['nullable', 'integer', 'between:-999,999'],
        ]);

        $v->after(function ($v) use ($request) {
            $t = trim((string) $request->input('target'));

            if ($t === '') {
                return;
            }

            /*
            | 🔴 دو شکلِ مجاز و بس:
            |
            |   · نشانی: باید با `https://` یا `/` یا `#` شروع شود. بی‌این شرط،
            |     `javascript:...` در فوترِ **هر** صفحه می‌نشیند — یعنی XSS روی
            |     کلِ سایت، با دستِ خودِ پنل.
            |   · نامِ روت: باید واقعاً ثبت شده باشد، وگرنه `lroute()` استثنا
            |     می‌دهد. لایهٔ رندر می‌گیردش، ولی مدیر باید **همین‌جا** بفهمد
            |     که لینکش کار نمی‌کند، نه اینکه ساکت ناپدید شود.
            */
            if (preg_match('~^(https?://|/|#)~i', $t)) {
                return;
            }

            if (! RouteFacade::has($t)) {
                $v->errors()->add('target',
                    'مقصد باید نشانیِ کامل (https://…)، مسیرِ داخلی (/…) یا نامِ یک روتِ موجود باشد. «'.$t.'» هیچ‌کدام نیست.');
            }
        });

        if ($v->fails()) {
            return back()->withErrors($v)->withInput();
        }

        $data = $v->validated();
        $t = trim($data['target']);
        $isUrl = (bool) preg_match('~^(https?://|/|#)~i', $t);

        $custom = ['scope' => (string) ($data['scope'] ?? '')]
            + ($isUrl ? ['url' => $t] : ['route' => $t]);

        // فوتر به‌جای «دامنه»، «ستون» دارد
        if ($data['menu'] === 'footer') {
            $custom['column'] = (string) ($data['scope'] ?? '');
        }

        MenuOverride::create([
            'menu'     => $data['menu'],
            'path'     => $data['menu'].':custom:'.substr(md5($t.'|'.$data['label_fa']), 0, 12),
            'visible'  => true,
            'sort'     => $data['sort'] ?? null,
            'label_fa' => $data['label_fa'],
            'label_en' => $data['label_en'] ?? null,
            'label_tr' => $data['label_tr'] ?? null,
            'custom'   => $custom,
            'updated_by' => $request->user()->id,
        ]);

        MenuManager::forget();

        return back()->with('ok', 'لینک اضافه شد.');
    }

    /**
     * حذفِ یک رویه = **برگشت به پیش‌فرضِ config**، نه حذفِ لینک.
     *
     * ⚠️ برای لینکِ افزودهٔ مدیر، همین حذف واقعاً پاکش می‌کند — چون پیش‌فرضی
     * ندارد که به آن برگردد.
     */
    public function destroy(Request $request, MenuOverride $override): RedirectResponse
    {
        abort_unless(optional($request->user())->isAdmin(), 403);

        $wasCustom = $override->isCustom();
        $override->delete();

        MenuManager::forget();

        return back()->with('ok', $wasCustom ? 'لینک حذف شد.' : 'به حالتِ پیش‌فرض برگشت.');
    }
}
