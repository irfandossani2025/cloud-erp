<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(string $name): User
    {
        $agentId = (string) Str::uuid();
        DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);

        return User::create([
            'name' => $name, 'email' => Str::slug($name).'@test.invalid',
            'password' => 'password', 'is_admin' => false, 'agent_id' => $agentId,
        ]);
    }

    private function makeProduct(): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $id, 'sku' => 'SKU-'.$id, 'name' => 'Test Product',
            'warehouse_stock' => 10, 'cost_baisa' => 500,
        ]);

        return $id;
    }

    public function test_agent_can_upload_a_product_photo(): void
    {
        Storage::fake('local');
        $agent = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $photo = UploadedFile::fake()->image('mug.png', 200, 200);
        $response = $this->actingAs($agent)->post("/api/products/{$productId}/photo", ['photo' => $photo])->assertOk();
        $image = $response->json('image');
        $this->assertStringStartsWith("/api/product-assets/{$productId}?v=1", $image);
        $this->assertDatabaseHas('products', ['id' => $productId, 'photo_version' => 1]);
        Storage::disk('local')->assertExists("products/{$productId}");
    }

    public function test_uploading_a_second_photo_bumps_the_version(): void
    {
        Storage::fake('local');
        $agent = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $this->actingAs($agent)->post("/api/products/{$productId}/photo", [
            'photo' => UploadedFile::fake()->image('one.png', 200, 200),
        ])->assertOk();
        $response = $this->actingAs($agent)->post("/api/products/{$productId}/photo", [
            'photo' => UploadedFile::fake()->image('two.png', 200, 200),
        ])->assertOk();
        $this->assertStringStartsWith("/api/product-assets/{$productId}?v=2", $response->json('image'));
        $this->assertDatabaseHas('products', ['id' => $productId, 'photo_version' => 2]);
    }

    public function test_rejects_a_file_that_is_not_really_an_image(): void
    {
        Storage::fake('local');
        $agent = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $fake = UploadedFile::fake()->createWithContent('not-a-photo.png', 'this is definitely not png bytes');
        $this->actingAs($agent)->post("/api/products/{$productId}/photo", ['photo' => $fake])
            ->assertStatus(422);
        $this->assertDatabaseHas('products', ['id' => $productId, 'photo_version' => 0]);
    }

    public function test_upload_requires_an_existing_product(): void
    {
        Storage::fake('local');
        $agent = $this->makeAgent('Agent One');
        $missingId = (string) Str::uuid();
        $this->actingAs($agent)->post("/api/products/{$missingId}/photo", [
            'photo' => UploadedFile::fake()->image('mug.png', 200, 200),
        ])->assertStatus(404);
    }

    public function test_guest_cannot_upload_a_product_photo(): void
    {
        Storage::fake('local');
        $productId = $this->makeProduct();
        $this->post("/api/products/{$productId}/photo", [
            'photo' => UploadedFile::fake()->image('mug.png', 200, 200),
        ])->assertStatus(401);
    }

    public function test_asset_endpoint_serves_the_uploaded_photo(): void
    {
        Storage::fake('local');
        $agent = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $this->actingAs($agent)->post("/api/products/{$productId}/photo", [
            'photo' => UploadedFile::fake()->image('mug.jpg', 200, 200),
        ])->assertOk();
        $this->actingAs($agent)->get("/api/product-assets/{$productId}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_asset_endpoint_404s_for_a_product_with_no_photo(): void
    {
        $agent = $this->makeAgent('Agent One');
        $productId = $this->makeProduct();
        $this->actingAs($agent)->get("/api/product-assets/{$productId}")->assertStatus(404);
    }
}
