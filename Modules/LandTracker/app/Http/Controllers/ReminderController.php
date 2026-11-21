<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\LandTracker\Models\Instalment;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\Reminder;
use Modules\LandTracker\Services\ReminderService;

// Staff only
class ReminderController extends Controller
{
    public function forParcel(Parcel $parcel)
    {
        return Reminder::with(['instalment:id,plan_id,seq,label,due_date', 'sender:id,full_name'])
            ->whereHas('instalment.plan', fn ($q) => $q->where('parcel_id', $parcel->id))
            ->orderByDesc('sent_at')->get();
    }

    // Send one now, with the standard wording or the staff member's own
    public function send(Request $request, Instalment $instalment, ReminderService $reminders)
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(Reminder::CHANNELS)],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(
            $reminders->send($instalment, $data['channel'], $request->user(), $data['message'] ?? null), 201);
    }
}
