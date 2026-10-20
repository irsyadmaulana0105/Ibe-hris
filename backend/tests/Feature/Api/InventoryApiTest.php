<?php

use App\Warehouse\Models\Inventory;
use App\Warehouse\Models\InventoryCategory;
use App\Models\JobTitle;
use App\Models\OfficeLocation;
use Illuminate\Support\Facades\DB;

uses()->group('api', 'master-data');

it('lists inventory items for the authenticated company only', function () {
    [$admin, $token] = adminWithToken();

    DB::table('companies')->insert([
        'id' => 2,
        'name' => 'Other Company',
        'slug' => 'other-company-inventory',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Inventory::create([
        'company_id' => 1,
        'item_code' => 'INV-001',
        'item_name' => 'Laptop Operasional',
        'stock' => 5,
    ]);

    Inventory::withoutGlobalScopes()->create([
        'company_id' => 2,
        'item_code' => 'INV-999',
        'item_name' => 'Other Inventory',
        'stock' => 1,
    ]);

    $this->getJson('/api/v1/admin/master-data/inventories', bearerHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item_code', 'INV-001');
});

it('creates an inventory item with validated company-safe references', function () {
    [$admin, $token] = adminWithToken();

    $category = InventoryCategory::withoutGlobalScopes()->create([
        'company_id' => 1,
        'name' => 'Elektronik',
        'is_active' => true,
    ]);

    $office = OfficeLocation::withoutGlobalScopes()->create([
        'company_id' => 1,
        'name' => 'HQ',
        'is_active' => true,
    ]);

    $jobTitle = JobTitle::factory()->create([
        'company_id' => 1,
        'name' => 'Supervisor Gudang',
    ]);

    $this->postJson('/api/v1/admin/master-data/inventories', [
        'item_code' => 'INV-002',
        'item_name' => 'Printer Gudang',
        'category_id' => $category->id,
        'stock' => 2,
        'unit' => 'unit',
        'description' => 'Printer untuk kebutuhan operasional gudang',
        'office_location_id' => $office->id,
        'job_title_id' => $jobTitle->id,
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.item_code', 'INV-002')
        ->assertJsonPath('data.category.id', $category->id)
        ->assertJsonPath('data.office_location.id', $office->id)
        ->assertJsonPath('data.job_title.id', $jobTitle->id);
});

it('updates an inventory item inside the authenticated company', function () {
    [$admin, $token] = adminWithToken();

    $inventory = Inventory::create([
        'company_id' => 1,
        'item_code' => 'INV-003',
        'item_name' => 'Kursi Office',
        'stock' => 10,
    ]);

    $this->putJson("/api/v1/admin/master-data/inventories/{$inventory->id}", [
        'item_code' => 'INV-003',
        'item_name' => 'Kursi Meeting',
        'stock' => 12,
        'unit' => 'pcs',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.item_name', 'Kursi Meeting')
        ->assertJsonPath('data.stock', '12.00');
});

it('rejects inventory item creation with references from another company', function () {
    [$admin, $token] = adminWithToken();

    DB::table('companies')->insert([
        'id' => 2,
        'name' => 'Other Company 2',
        'slug' => 'other-company-inventory-2',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $foreignCategory = InventoryCategory::withoutGlobalScopes()->create([
        'company_id' => 2,
        'name' => 'Foreign Category',
        'is_active' => true,
    ]);

    $this->postJson('/api/v1/admin/master-data/inventories', [
        'item_name' => 'Router',
        'category_id' => $foreignCategory->id,
        'stock' => 1,
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');
});

it('forbids regular employees from accessing inventory master endpoints', function () {
    [$employee, $token] = employeeWithToken();

    $this->getJson('/api/v1/admin/master-data/inventories', bearerHeaders($token))
        ->assertForbidden();
});
