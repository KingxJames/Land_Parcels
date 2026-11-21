<?php

namespace Modules\LandTracker\Services;

use App\Models\User;
use Illuminate\Support\Facades\Mail;

// Sends a message to a person on one channel. Email works through Laravel's mailer.
// WhatsApp and SMS need a provider (for example Twilio or the WhatsApp Cloud API);
// until one is added here they refuse rather than pretend to send.
class MessageSender
{
    public function send(User $to, string $channel, string $subject, string $body): void
    {
        match ($channel) {
            'email' => $this->email($to, $subject, $body),
            'whatsapp', 'sms' => throw new ChannelNotAvailable(ucfirst($channel) . ' sending isn’t set up yet. Use email for now.'),
            default => throw new ChannelNotAvailable("Unknown channel: $channel"),
        };
    }

    private function email(User $to, string $subject, string $body): void
    {
        if (! $to->email) {
            throw new ChannelNotAvailable('This person has no email address.');
        }

        Mail::raw($body, fn ($m) => $m->to($to->email, $to->full_name)->subject($subject));
    }
}
