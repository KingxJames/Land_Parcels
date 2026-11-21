<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\Document;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Land $land;

    private Parcel $parcel;

    private User $staff;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'Faber Realty']);
        $this->land = Land::create(['org_id' => $this->org->id, 'name' => 'Cayo Ridge', 'place' => 'Cayo', 'is_public' => true]);
        $this->parcel = $this->land->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 5000, 'price' => 3000]);
        $this->staff = $this->makeUser(['full_name' => 'S', 'email' => 's@example.test', 'role' => 'office_admin', 'status' => 'active']);
        $this->owner = $this->makeUser(['full_name' => 'O', 'email' => 'o@example.test', 'role' => 'owner', 'status' => 'active']);
    }

    private function upload(array $over = []): string
    {
        Sanctum::actingAs($this->staff);

        return $this->post('/api/v1/documents', array_merge([
            'file' => UploadedFile::fake()->create('Title Deed.pdf', 120, 'application/pdf'),
            'org_id' => $this->org->id,
        ], $over), ['Accept' => 'application/json'])->assertCreated()->json('id');
    }

    public function test_staff_upload_public_document_anyone_can_download(): void
    {
        $id = $this->upload(['parcel_id' => $this->parcel->id]);
        $doc = Document::find($id);
        $this->assertSame('Title Deed.pdf', $doc->file_name);
        $this->assertTrue($doc->is_public);
        $this->assertSame($this->staff->id, $doc->uploaded_by);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/documents?org_id={$this->org->id}")->assertJsonCount(1)->assertJsonMissingPath('0.file_path');
        $this->get("/api/v1/documents/{$id}/download")->assertOk()->assertDownload('Title Deed.pdf');
    }

    public function test_private_document_only_for_its_owner_and_staff(): void
    {
        $id = $this->upload(['shared_with' => $this->owner->id]);
        $this->getJson("/api/v1/public/documents?org_id={$this->org->id}")->assertJsonCount(0);
        $this->get("/api/v1/documents/{$id}/download")->assertOk(); // staff

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/my/documents')->assertJsonCount(1);
        $this->get("/api/v1/documents/{$id}/download")->assertOk();

        Sanctum::actingAs($this->makeUser(['full_name' => 'X', 'email' => 'x@example.test', 'role' => 'owner', 'status' => 'active']));
        $this->get("/api/v1/documents/{$id}/download")->assertNotFound();
    }

    public function test_public_document_on_hidden_land_stays_hidden(): void
    {
        $id = $this->upload(['parcel_id' => $this->parcel->id]);
        $this->land->update(['is_public' => false]);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/documents?org_id={$this->org->id}")->assertJsonCount(0);
        $this->get("/api/v1/documents/{$id}/download")->assertNotFound();
    }

    public function test_bad_uploads_are_rejected(): void
    {
        Sanctum::actingAs($this->staff);
        $base = ['org_id' => $this->org->id];

        $this->post('/api/v1/documents', [...$base, 'file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')],
            ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/v1/documents', [...$base, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'shared_with' => $this->staff->id],
            ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('shared_with');

        // Staff can't file into another company: their own is always used
        $other = Organization::create(['name' => 'Other']);
        $id = $this->post('/api/v1/documents', ['org_id' => $other->id, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->assertSame($this->org->id, Document::find($id)->org_id);

        // ...and can't attach another company's parcel
        $otherLand = Land::create(['org_id' => $other->id, 'name' => 'Elsewhere', 'place' => 'X']);
        $otherParcel = $otherLand->parcels()->create(['label' => 'Lot 9', 'kind' => 'land', 'size_sqft' => 1, 'price' => 1]);
        $this->post('/api/v1/documents', ['parcel_id' => $otherParcel->id, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('parcel_id');
    }

    public function test_download_name_is_cleaned(): void
    {
        $id = $this->upload(['file_name' => '../../etc/passwd<script>.exe']);
        $this->assertSame('passwd_script.pdf', Document::find($id)->file_name);
    }

    public function test_owners_cannot_upload_and_staff_delete_removes_file(): void
    {
        Sanctum::actingAs($this->owner);
        $this->post('/api/v1/documents', ['org_id' => $this->org->id, 'file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')],
            ['Accept' => 'application/json'])->assertForbidden();

        $id = $this->upload();
        $path = Document::find($id)->file_path;
        $this->deleteJson("/api/v1/documents/{$id}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
    }
}
