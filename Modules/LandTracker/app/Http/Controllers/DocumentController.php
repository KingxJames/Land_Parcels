<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\LandTracker\Models\Document;
use Modules\LandTracker\Models\Parcel;

class DocumentController extends Controller
{
    private const WITH = ['parcel:id,label,land_id', 'recipient:id,full_name,email', 'uploader:id,full_name'];

    // Public site: shared-with-everyone files for a company, optionally one parcel
    public function publicIndex(Request $request)
    {
        $request->validate(['org_id' => ['required', 'uuid'], 'parcel_id' => ['nullable', 'uuid']]);

        return Document::where('org_id', $request->org_id)
            ->whereHas('organization', fn ($o) => $o->where('status', '<>', 'suspended'))
            ->whereNull('shared_with')
            ->when($request->parcel_id, fn ($q, $p) => $q->where('parcel_id', $p))
            // hide files about parcels on hidden lands
            ->where(fn ($q) => $q->whereNull('parcel_id')
                ->orWhereHas('parcel.land', fn ($l) => $l->where('is_public', true)))
            ->orderByDesc('created_at')
            ->get(['id', 'org_id', 'parcel_id', 'shared_with', 'file_name', 'size_bytes', 'created_at']);
    }

    // Signed-in owner: files shared with them
    public function mine(Request $request)
    {
        return Document::with('parcel:id,label,land_id')->where('shared_with', $request->user()->id)
            ->orderByDesc('created_at')->get();
    }

    // Anyone allowed to see it (checked here, since public files need no sign-in)
    public function download(Request $request, Document $document)
    {
        abort_unless($document->visibleTo($request->user('sanctum')), 404);
        abort_unless(Storage::disk(\App\Support\Disks::uploads())->exists($document->file_path), 404);

        return Storage::disk(\App\Support\Disks::uploads())->download($document->file_path, $document->file_name, [
            'Cache-Control' => $document->is_public ? 'public, max-age=' . config('platform.cache_seconds.site_plans_and_documents') : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // Staff
    public function index(Request $request)
    {
        $request->validate(['org_id' => ['nullable', 'uuid'], 'parcel_id' => ['nullable', 'uuid'], 'shared_with' => ['nullable', 'uuid']]);
        $org = \App\Support\Tenancy::scope($request->user());

        return Document::with(self::WITH)
            ->when($org, fn ($q) => $q->where('org_id', $org))
            ->when($request->org_id, fn ($q, $v) => $q->where('org_id', $v))
            ->when($request->parcel_id, fn ($q, $v) => $q->where('parcel_id', $v))
            ->when($request->shared_with, fn ($q, $v) => $q->where('shared_with', $v))
            ->orderByDesc('created_at')->paginate(\App\Support\Paging::size());
    }

    public function store(Request $request)
    {
        if ($org = \App\Support\Tenancy::scope($request->user())) {
            $request->merge(['org_id' => $org]); // staff upload to their own company
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:' . config('platform.upload_kb.document'), 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
            'org_id' => ['required', 'uuid', 'exists:organizations,id'],
            'parcel_id' => ['nullable', 'uuid'],
            'shared_with' => ['nullable', 'uuid', function ($attr, $value, $fail) use ($request) {
                $u = User::find($value);
                if (! $u || $u->org_id !== $request->input('org_id') || ! $u->hasCompanyRole('owner')) {
                    $fail('Documents can be shared only with this company’s owners.');
                }
            }],
            'file_name' => ['nullable', 'string', 'max:200'],
        ]);

        if (! empty($data['parcel_id'])) {
            $parcel = Parcel::with('land')->find($data['parcel_id']);
            if (! $parcel || $parcel->land->org_id !== $data['org_id']) {
                throw ValidationException::withMessages(['parcel_id' => 'That parcel isn’t in this company.']);
            }
        }

        $file = $request->file('file');
        $path = $file->store("documents/{$data['org_id']}", \App\Support\Disks::uploads());

        try {
            $doc = Document::forceCreate([
                'org_id' => $data['org_id'],
                'parcel_id' => $data['parcel_id'] ?? null,
                'shared_with' => $data['shared_with'] ?? null,
                'file_name' => $this->cleanName($data['file_name'] ?? $file->getClientOriginalName(), $file->extension()),
                'file_path' => $path,
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk(\App\Support\Disks::uploads())->delete($path);
            throw $e;
        }

        \Modules\LandTracker\Services\Activity::record('document_uploaded', $doc, $request->user(),
            ['file_name' => $doc->file_name, 'shared_with' => $doc->shared_with]);

        return response()->json($doc->load(self::WITH), 201);
    }

    public function destroy(Request $request, Document $document)
    {
        \Modules\LandTracker\Services\Activity::record('document_deleted', $document, $request->user(), ['file_name' => $document->file_name]);
        $path = $document->file_path;
        $document->delete();
        Storage::disk(\App\Support\Disks::uploads())->delete($path);

        return response()->noContent();
    }

    // Plain, safe download name that keeps the real file type's extension
    private function cleanName(string $name, ?string $ext): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^\pL\pN ._()-]+/u', '_', $base), ' ._') ?: 'document';

        return mb_substr($base, 0, 180) . ($ext ? ".$ext" : '');
    }
}
