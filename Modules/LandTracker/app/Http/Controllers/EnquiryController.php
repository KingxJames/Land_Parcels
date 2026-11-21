<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\LandTracker\Enums\EnquiryStatus;
use Modules\LandTracker\Models\Enquiry;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Services\ParcelSales;

class EnquiryController extends Controller
{
    private const WITH = ['parcel:id,label,land_id,status', 'parcel.land:id,name', 'assignee:id,full_name', 'buyer:id,full_name,email'];

    // Public website form. Says nothing back beyond "received".
    public function submit(Request $request)
    {
        // Hidden "website" field: people leave it empty, spam bots fill it in
        if ($request->filled('website')) {
            return response()->json(['message' => 'Thank you, we’ll be in touch.'], 201);
        }

        $data = $request->validate([
            'parcel_id' => ['nullable', 'uuid'],
            'org_id' => ['required_without:parcel_id', 'nullable', 'uuid'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{7,40}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        // About a parcel: it must be on a public land, and the company comes from the land
        if (! empty($data['parcel_id'])) {
            $parcel = Parcel::with('land')->find($data['parcel_id']);
            if (! $parcel || ! $parcel->land->isListedPublicly()) {
                throw ValidationException::withMessages(['parcel_id' => 'That parcel isn’t available.']);
            }
            $data['org_id'] = $parcel->land->org_id;
        } elseif (! Organization::whereKey($data['org_id'])->where('status', '<>', 'suspended')->exists()) {
            throw ValidationException::withMessages(['org_id' => 'Unknown company.']);
        }

        $data['email'] = isset($data['email']) ? strtolower($data['email']) : null;
        Enquiry::create($data);

        return response()->json(['message' => 'Thank you, we’ll be in touch.'], 201);
    }

    // Staff: the follow-up list. Open ones by default.
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(EnquiryStatus::class)],
            'org_id' => ['nullable', 'uuid'],
            'assigned_to' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $org = \App\Support\Tenancy::scope($request->user());

        return Enquiry::with(self::WITH)
            ->when($org, fn ($q) => $q->where('org_id', $org))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s),
                fn ($q) => $q->where('status', '<>', EnquiryStatus::Closed->value))
            ->when($request->org_id, fn ($q, $o) => $q->where('org_id', $o))
            ->when($request->assigned_to, fn ($q, $a) => $q->where('assigned_to', $a))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->orderByDesc('created_at')
            ->paginate(\App\Support\Paging::size());
    }

    public function show(Enquiry $enquiry)
    {
        return $enquiry->load(self::WITH);
    }

    // Staff: move it along or hand it to someone. "reserved" comes only from reserve().
    public function update(Request $request, Enquiry $enquiry)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(EnquiryStatus::class)->except([EnquiryStatus::Reserved])],
            'assigned_to' => ['sometimes', 'nullable', 'uuid', function ($attr, $value, $fail) use ($enquiry) {
                $u = $value ? User::find($value) : null;
                if ($value && (! $u || $u->org_id !== $enquiry->org_id || $u->status !== 'active' || ! $u->isStaff())) {
                    $fail('Choose an active staff member.');
                }
            }],
        ]);

        if (isset($data['status']) && $enquiry->status === EnquiryStatus::Reserved && $data['status'] !== EnquiryStatus::Closed->value) {
            throw ValidationException::withMessages(['status' => 'A reserved enquiry can only be closed.']);
        }

        $enquiry->forceFill($data)->save();

        return $enquiry->load(self::WITH);
    }

    // Staff: the person wants the parcel. Makes (or finds) their owner account, reserves
    // the parcel for them and marks the enquiry reserved, all or nothing.
    public function reserve(Request $request, Enquiry $enquiry, ParcelSales $sales)
    {
        $data = $request->validate([
            'parcel_id' => ['nullable', 'uuid', 'exists:parcels,id'], // defaults to the enquiry's parcel
            'email' => ['nullable', 'email:rfc', 'max:255'],           // needed if the enquiry has none
            'hold_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:' . now()->addDays(\App\Support\CompanySettings::for($enquiry->org_id)->get('limits.max_hold_days'))->toDateString()],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ]);

        if ($enquiry->status === EnquiryStatus::Reserved || $enquiry->status === EnquiryStatus::Closed) {
            throw ValidationException::withMessages(['status' => 'This enquiry is already ' . $enquiry->status->value . '.']);
        }

        $parcel = Parcel::with('land')->find($data['parcel_id'] ?? $enquiry->parcel_id);
        if (! $parcel) {
            throw ValidationException::withMessages(['parcel_id' => 'Choose which parcel to reserve.']);
        }
        if ($parcel->land->org_id !== $enquiry->org_id) {
            throw ValidationException::withMessages(['parcel_id' => 'That parcel belongs to another company.']);
        }

        // Sign-in is with Google, matched on email, so an email is required
        $email = strtolower($data['email'] ?? $enquiry->email ?? '');
        if ($email === '') {
            throw ValidationException::withMessages(['email' => 'Add the buyer’s email so they can sign in.']);
        }

        $buyer = DB::transaction(function () use ($enquiry, $parcel, $email, $data, $sales, $request) {
            // Accounts are per company: look only within this enquiry's company
            $buyer = User::where('org_id', $enquiry->org_id)->where('email', $email)->first();
            if ($buyer && $buyer->isStaff()) {
                throw ValidationException::withMessages(['email' => 'That email belongs to a staff account.']);
            }
            if (! $buyer) {
                $buyer = User::create([
                    'org_id' => $enquiry->org_id, 'full_name' => $enquiry->name, 'email' => $email, 'phone' => $enquiry->phone,
                    'status' => 'invited',
                ]);
                \App\Support\DefaultRoles::assign($buyer, 'owner');
            }

            $sales->reserve($parcel, $buyer, Carbon::parse($data['hold_until']), $request->user(),
                isset($data['price']) ? (string) $data['price'] : null);

            $enquiry->forceFill([
                'status' => EnquiryStatus::Reserved, 'buyer_user_id' => $buyer->id, 'parcel_id' => $parcel->id,
                'assigned_to' => $enquiry->assigned_to ?? $request->user()->id,
            ])->save();

            return $buyer;
        });

        \Modules\LandTracker\Services\Activity::record('enquiry_reserved', $enquiry, $request->user(), ['parcel_id' => $parcel->id, 'buyer_id' => $buyer->id]);
        Log::info('enquiry.reserved', ['enquiry_id' => $enquiry->id, 'parcel_id' => $parcel->id, 'buyer_id' => $buyer->id, 'staff_id' => $request->user()->id]);

        return $enquiry->load(self::WITH);
    }
}
