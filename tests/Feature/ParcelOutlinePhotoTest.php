<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\ParcelPhoto;
use Tests\TestCase;

class ParcelOutlinePhotoTest extends TestCase
{
    use RefreshDatabase;

    private Land $land;

    private Parcel $parcel;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $org = Organization::create(['name' => 'Faber Realty']);
        $this->land = Land::create(['org_id' => $org->id, 'name' => 'Cayo Ridge', 'place' => 'Cayo',
            'is_public' => true, 'plan_width_ft' => 1000, 'plan_height_ft' => 500]);
        $this->parcel = $this->land->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 5000, 'price' => 1]);
        $this->staff = $this->makeUser(['full_name' => 'S', 'email' => 's@example.test', 'role' => 'office_admin', 'status' => 'active']);
    }

    private function square(): array
    {
        return [['x' => 10, 'y' => 10], ['x' => 20, 'y' => 10], ['x' => 20, 'y' => 20], ['x' => 10, 'y' => 20]];
    }

    public function test_staff_saves_outline_with_size_estimate_and_replaces_it(): void
    {
        Sanctum::actingAs($this->staff);

        // 10% x 10% of a 1000 x 500 ft plan = 100 ft x 50 ft = 5000 sq ft
        $this->putJson("/api/v1/parcels/{$this->parcel->id}/outline", ['points' => $this->square()])
            ->assertCreated()
            ->assertJsonPath('estimated_sqft', 5000)
            ->assertJsonPath('updated_by', $this->staff->id)
            ->assertJsonPath('editor.full_name', 'S');

        $tri = [['x' => 0, 'y' => 0], ['x' => 50, 'y' => 0], ['x' => 0, 'y' => 50]];
        $this->putJson("/api/v1/parcels/{$this->parcel->id}/outline", ['points' => $tri])
            ->assertOk()->assertJsonCount(3, 'points');

        $this->deleteJson("/api/v1/parcels/{$this->parcel->id}/outline")->assertNoContent();
        $this->getJson("/api/v1/parcels/{$this->parcel->id}/outline")->assertNotFound();
    }

    public function test_bad_outlines_are_rejected(): void
    {
        Sanctum::actingAs($this->staff);
        $url = "/api/v1/parcels/{$this->parcel->id}/outline";

        $this->putJson($url, ['points' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]]])->assertUnprocessable();          // too few
        $this->putJson($url, ['points' => [['x' => 1, 'y' => 1], ['x' => 101, 'y' => 2], ['x' => 3, 'y' => 3]]])->assertUnprocessable(); // off the photo
        $this->putJson($url, ['points' => [['x' => 1, 'y' => 1, 'evil' => 1], ['x' => 2, 'y' => 2], ['x' => 3, 'y' => 1]]])->assertUnprocessable(); // extra keys
        $this->putJson($url, ['points' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2], ['x' => 3, 'y' => 3]]])->assertUnprocessable();  // a straight line
    }

    public function test_owner_cannot_edit_outline(): void
    {
        Sanctum::actingAs($this->makeUser(['full_name' => 'O', 'email' => 'o@example.test', 'role' => 'owner', 'status' => 'active']));
        $this->putJson("/api/v1/parcels/{$this->parcel->id}/outline", ['points' => $this->square()])->assertForbidden();
    }

    public function test_photos_upload_order_and_delete(): void
    {
        Sanctum::actingAs($this->staff);

        $res = $this->post("/api/v1/parcels/{$this->parcel->id}/photos", [
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2)->assertJsonMissingPath('0.file_path');

        [$a, $b] = [$res->json('0.id'), $res->json('1.id')];
        $this->assertSame([1, 2], [$res->json('0.sort_order'), $res->json('1.sort_order')]);

        $this->putJson("/api/v1/parcels/{$this->parcel->id}/photos/order", ['ids' => [$b, $a]])
            ->assertOk()->assertJsonPath('0.id', $b)->assertJsonPath('1.id', $a);
        $this->putJson("/api/v1/parcels/{$this->parcel->id}/photos/order", ['ids' => [$b]])->assertUnprocessable();

        $path = ParcelPhoto::find($a)->file_path;
        $this->deleteJson("/api/v1/parcel-photos/{$a}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_non_images_are_rejected(): void
    {
        Sanctum::actingAs($this->staff);
        $this->post("/api/v1/parcels/{$this->parcel->id}/photos", [
            'photos' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_public_sees_photos_and_outline_only_for_public_land(): void
    {
        $photo = $this->parcel->photos()->create(['file_path' => 'parcel-photos/p.jpg', 'sort_order' => 1]);
        Storage::disk('local')->put('parcel-photos/p.jpg', 'img');
        $this->parcel->outline()->create(['points' => $this->square()]);

        $this->getJson("/api/v1/public/parcels/{$this->parcel->id}")
            ->assertOk()->assertJsonPath('outline.0.x', 10)->assertJsonPath('photos.0.id', $photo->id)
            ->assertJsonMissingPath('owner_id');
        $this->getJson("/api/v1/public/lands/{$this->land->id}/parcels")->assertJsonPath('0.photos.0.id', $photo->id);
        $this->get("/api/v1/parcel-photos/{$photo->id}")->assertOk();

        $this->land->update(['is_public' => false]);
        $this->getJson("/api/v1/public/parcels/{$this->parcel->id}")->assertNotFound();
        $this->get("/api/v1/parcel-photos/{$photo->id}")->assertNotFound();

        Sanctum::actingAs($this->staff);
        $this->get("/api/v1/parcel-photos/{$photo->id}")->assertOk();
    }

    public function test_deleting_parcel_removes_outline_photos_and_files(): void
    {
        $this->parcel->photos()->create(['file_path' => 'parcel-photos/q.jpg']);
        Storage::disk('local')->put('parcel-photos/q.jpg', 'img');
        $this->parcel->outline()->create(['points' => $this->square()]);
        Sanctum::actingAs($this->staff);

        $this->deleteJson("/api/v1/parcels/{$this->parcel->id}")->assertNoContent();

        $this->assertSame(0, ParcelPhoto::count());
        $this->assertDatabaseCount('parcel_outlines', 0);
        Storage::disk('local')->assertMissing('parcel-photos/q.jpg');
    }
}
