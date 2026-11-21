<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\LandTracker\Models\PaymentConnection;
use Modules\LandTracker\Models\PaymentNotice;
use Modules\LandTracker\Services\Activity;
use Modules\LandTracker\Services\PaymentNoticeHandler;

class PaymentConnectionController extends Controller
{
    // Staff
    public function index(Request $request)
    {
        $request->validate(['org_id' => ['nullable', 'uuid']]);

        $org = \App\Support\Tenancy::scope($request->user());

        return PaymentConnection::when($org, fn ($q) => $q->where('org_id', $org))
            ->when($request->org_id, fn ($q, $o) => $q->where('org_id', $o))
            ->orderBy('provider')->get()
            ->each(fn ($c) => $c->setAttribute('notice_url', url("/api/v1/webhooks/payments/{$c->id}")));
    }

    public function store(Request $request)
    {
        if ($org = \App\Support\Tenancy::scope($request->user())) {
            $request->merge(['org_id' => $org]);
        }

        $data = $request->validate([
            'org_id' => ['required', 'uuid', 'exists:organizations,id'],
            'provider' => ['required', Rule::in(PaymentConnection::PROVIDERS),
                Rule::unique('payment_connections')->where('org_id', $request->org_id)],
            'merchant_id' => ['required', 'string', 'max:150'],
            'api_key' => ['required', 'string', 'max:500'],
            'callback_secret' => ['nullable', 'string', 'min:16', 'max:500'],
            'mode' => ['required', 'in:test,live'],
        ], ['provider.unique' => 'This company already has that provider connected.']);

        $c = new PaymentConnection([
            'org_id' => $data['org_id'], 'provider' => $data['provider'], 'merchant_id' => $data['merchant_id'],
            'api_key_encrypted' => $data['api_key'], 'callback_secret_encrypted' => $data['callback_secret'] ?? null,
            'mode' => $data['mode'],
        ]);
        $c->connected_at = now();
        $c->save();

        Activity::record('connection_added', $c, $request->user(), ['provider' => $c->provider, 'mode' => $c->mode]);

        return response()->json($c->setAttribute('notice_url', url("/api/v1/webhooks/payments/{$c->id}")), 201);
    }

    // Change mode or swap keys. Keys left out stay as they are.
    public function update(Request $request, PaymentConnection $connection)
    {
        $data = $request->validate([
            'merchant_id' => ['sometimes', 'string', 'max:150'],
            'api_key' => ['sometimes', 'string', 'max:500'],
            'callback_secret' => ['sometimes', 'nullable', 'string', 'min:16', 'max:500'],
            'mode' => ['sometimes', 'in:test,live'],
        ]);

        $before = ['mode' => $connection->mode, 'merchant_id' => $connection->merchant_id];
        if (array_key_exists('api_key', $data)) {
            $connection->api_key_encrypted = $data['api_key'];
        }
        if (array_key_exists('callback_secret', $data)) {
            $connection->callback_secret_encrypted = $data['callback_secret'];
        }
        $connection->fill(array_intersect_key($data, array_flip(['merchant_id', 'mode'])))->save();

        // Never log key values, only that they changed
        Activity::record('connection_updated', $connection, $request->user(), [
            'old' => ['mode' => $before['mode']],
            'attributes' => ['mode' => $connection->mode],
            'keys_changed' => array_values(array_intersect(['api_key', 'callback_secret'], array_keys($data))),
        ]);

        return $connection;
    }

    public function destroy(Request $request, PaymentConnection $connection)
    {
        if ($connection->notices()->exists()) {
            return response()->json(['message' => 'This connection has received payment notices, so it is kept for the record. Switch it to test mode instead.'], 409);
        }

        Activity::record('connection_removed', $connection, $request->user(), ['provider' => $connection->provider]);
        $connection->delete();

        return response()->noContent();
    }

    public function notices(PaymentConnection $connection)
    {
        return $connection->notices()->orderByDesc('received_at')->paginate(\App\Support\Paging::size());
    }

    // Provider → us. No sign-in; the signature proves who sent it.
    public function receive(Request $request, PaymentConnection $connection, PaymentNoticeHandler $handler)
    {
        if (! $handler->verify($connection, $request->getContent(), $request->header('X-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Expected JSON.'], 400);
        }

        $handler->handle($connection, $payload);

        // Always 200 once stored, so the provider stops resending
        return response()->json(['received' => true]);
    }
}
