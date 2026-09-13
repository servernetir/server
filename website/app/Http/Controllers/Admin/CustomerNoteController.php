<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerNoteController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->user()?->isStaff(), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $note = $customer->notes()->create(['user_id' => $request->user()->id, 'body' => trim($data['body'])]);
        ActivityLog::record($customer->id, 'internal_note_created', 'یادداشت داخلی #'.$note->id.' ثبت شد', $request, 'staff');

        return back()->with('ok', 'یادداشت داخلی ثبت شد.');
    }

    public function update(Request $request, Customer $customer, CustomerNote $note): RedirectResponse
    {
        $this->authorizeNote($request, $customer, $note);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $note->update(['body' => trim($data['body'])]);
        ActivityLog::record($customer->id, 'internal_note_updated', 'یادداشت داخلی #'.$note->id.' ویرایش شد', $request, 'staff');

        return back()->with('ok', 'یادداشت داخلی ویرایش شد.');
    }

    public function destroy(Request $request, Customer $customer, CustomerNote $note): RedirectResponse
    {
        $this->authorizeNote($request, $customer, $note);
        $id = $note->id;
        $note->delete();
        ActivityLog::record($customer->id, 'internal_note_deleted', 'یادداشت داخلی #'.$id.' حذف شد', $request, 'staff');

        return back()->with('ok', 'یادداشت داخلی حذف شد.');
    }

    private function authorizeNote(Request $request, Customer $customer, CustomerNote $note): void
    {
        abort_unless($request->user()?->isStaff(), 403);
        abort_unless($note->customer_id === $customer->id, 404);
        abort_unless($request->user()->isAdmin() || $note->user_id === $request->user()->id, 403);
    }
}
