<?php

namespace Modules\LandTracker\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\LandTracker\Models\Instalment;
use Modules\LandTracker\Models\Reminder;

class ReminderService
{
    public function __construct(private MessageSender $sender) {}

    // Sends, then records. Nothing is recorded if sending fails.
    public function send(Instalment $instalment, string $channel, ?User $staff = null, ?string $message = null): Reminder
    {
        $instalment->loadMissing('plan.owner', 'plan.parcel.land.organization');
        $owner = $instalment->plan->owner;

        if ($instalment->status === 'paid') {
            throw ValidationException::withMessages(['instalment' => 'This instalment is already paid.']);
        }

        $message ??= $this->defaultMessage($instalment);

        try {
            $this->sender->send($owner, $channel, 'Payment reminder: ' . $instalment->plan->parcel->label, $message);
        } catch (ChannelNotAvailable $e) {
            throw ValidationException::withMessages(['channel' => $e->getMessage()]);
        }

        return Reminder::forceCreate([
            'instalment_id' => $instalment->id,
            'owner_id' => $owner->id,
            'channel' => $channel,
            'message' => $message,
            'sent_by' => $staff?->id,
            'sent_at' => now(),
        ]);
    }

    // Daily run: each company's own reminder days and channel; once per day per instalment
    public function sendDue(): array
    {
        $today = today();
        $sent = 0;
        $failed = 0;

        \App\Models\Organization::where('status', '<>', 'suspended')->each(function ($org) use ($today, &$sent, &$failed) {
            $settings = $org->settings();
            $dates = [
                $today->copy()->addDays($org->reminder_days_before)->toDateString(),
                ...array_map(fn ($d) => $today->copy()->subDays((int) $d)->toDateString(), $settings->get('reminders.days_overdue', [])),
            ];
            $this->sendDueFor($org->id, $dates, $settings->get('reminders.channel'), $today, $sent, $failed);
        });

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function sendDueFor(string $orgId, array $dates, string $channel, $today, int &$sent, int &$failed): void
    {
        Instalment::whereIn('due_date', $dates)
            ->whereHas('plan.parcel.land', fn ($q) => $q->where('org_id', $orgId))
            ->whereColumn('amount_paid', '<', 'amount')
            ->whereDoesntHave('reminders', fn ($q) => $q->where('sent_at', '>=', $today))
            ->with('plan.owner', 'plan.parcel')
            ->lazyById(200)
            ->each(function (Instalment $i) use ($channel, &$sent, &$failed) {
                if ($i->plan->owner->status === 'switched_off') {
                    return;
                }
                try {
                    $this->send($i, $channel);
                    $sent++;
                } catch (\Throwable $e) {
                    $failed++;
                    report($e);
                }
            });
    }

    private function defaultMessage(Instalment $i): string
    {
        $org = $i->plan->parcel->land->organization;
        $settings = $org->settings();
        $due = Carbon::parse($i->due_date)->format('j F Y');
        $when = $settings->message($i->due_date->isBefore(today()) ? 'reminder_overdue' : 'reminder_due', ['due_date' => $due]);

        return $settings->message('reminder', [
            'name' => $i->plan->owner->full_name,
            'payment' => $i->label,
            'amount' => Money::format((float) $i->amount - (float) $i->amount_paid, $org->currency),
            'parcel' => $i->plan->parcel->label,
            'when' => $when,
            'due_date' => $due,
            'company' => $org->name,
        ]);
    }
}
