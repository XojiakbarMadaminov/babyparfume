<?php

use App\Models\User;
use App\Models\Store;
use App\Models\GeneralSetting;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Filament\Pages\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->store = Store::query()->create(['name' => 'Asosiy do‘kon', 'address' => 'Toshkent', 'phone' => '+998901234567']);

    $this->superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->sellerRole     = Role::firstOrCreate(['name' => 'seller', 'guard_name' => 'web']);

    $this->superAdmin = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->superAdmin->assignRole($this->superAdminRole);
    $this->superAdmin->stores()->attach($this->store);

    $this->seller = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->seller->assignRole($this->sellerRole);
    $this->seller->stores()->attach($this->store);
});

it('returns subscription status in mobile session and view', function (): void {
    GeneralSetting::query()->delete();
    $settings = GeneralSetting::current();
    $settings->update([
        'subscription_warning_active' => true,
        'subscription_warning_text'   => 'Iltimos, obuna to‘lovini amalga oshiring',
    ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->seller);

    // Test API session
    $this->getJson('/mobile/api/v1/session')
        ->assertOk()
        ->assertJsonPath('subscription.warning_active', true)
        ->assertJsonPath('subscription.warning_text', 'Iltimos, obuna to‘lovini amalga oshiring');

    // Test mobile blade view data attributes
    $this->withoutVite();
    $this->get('/mobile')
        ->assertOk()
        ->assertSee('data-sub-warning="1"', false)
        ->assertSee('data-sub-text="Iltimos, obuna to‘lovini amalga oshiring"', false);
});

it('allows only super admin to toggle subscription warning in GeneralSettings page', function (): void {
    GeneralSetting::query()->delete();
    $settings = GeneralSetting::current();
    $settings->update([
        'subscription_warning_active' => false,
        'subscription_warning_text'   => 'Oylik obuna to‘lovini to‘lashingiz talab qilinadi',
    ]);

    // Give seller page access to GeneralSettings if shield permission exists
    $permission = Permission::findOrCreate('View:GeneralSettings', 'web');
    $this->seller->givePermissionTo($permission);

    // Seller submits form trying to enable subscription warning
    $this->actingAs($this->seller);
    Livewire::test(GeneralSettings::class)
        ->set('data.barcode_show_price', true)
        ->set('data.subscription_warning_active', true)
        ->set('data.subscription_warning_text', 'Hacked text')
        ->call('submit')
        ->assertHasNoErrors();

    // Verify database: barcode_show_price changed, but subscription settings DID NOT change
    $settings->refresh();
    expect($settings->barcode_show_price)->toBeTrue()
        ->and($settings->subscription_warning_active)->toBeFalse()
        ->and($settings->subscription_warning_text)->toBe('Oylik obuna to‘lovini to‘lashingiz talab qilinadi');

    // Super Admin submits form
    $this->superAdmin->givePermissionTo($permission);
    $this->actingAs($this->superAdmin);
    Livewire::test(GeneralSettings::class)
        ->set('data.barcode_show_price', true)
        ->set('data.subscription_warning_active', true)
        ->set('data.subscription_warning_text', 'Yangi xabarnoma matni')
        ->call('submit')
        ->assertHasNoErrors();

    $settings->refresh();
    expect($settings->subscription_warning_active)->toBeTrue()
        ->and($settings->subscription_warning_text)->toBe('Yangi xabarnoma matni');
});

it('toggles subscription warning via artisan command', function (): void {
    GeneralSetting::query()->delete();

    $this->artisan('subscription:warning on --text="Diqqat, obuna muddati tugadi"')
        ->assertSuccessful()
        ->expectsOutputToContain('Subscription warning is now: ON');

    expect(GeneralSetting::isSubscriptionWarningActive())->toBeTrue()
        ->and(GeneralSetting::getSubscriptionWarningText())->toBe('Diqqat, obuna muddati tugadi');

    $this->artisan('subscription:warning off')
        ->assertSuccessful()
        ->expectsOutputToContain('Subscription warning is now: OFF');

    expect(GeneralSetting::isSubscriptionWarningActive())->toBeFalse();

    $this->artisan('subscription:warning toggle')
        ->assertSuccessful()
        ->expectsOutputToContain('Subscription warning is now: ON');

    expect(GeneralSetting::isSubscriptionWarningActive())->toBeTrue();
});
