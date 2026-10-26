<?php

use App\Models\User;
use Filament\Facades\Filament;
use App\Models\MobileAppRelease;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->user  = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->apkPaths = [];
    $this->makeApk  = function (string $name = 'release.apk', array $entries = ['AndroidManifest.xml' => 'manifest', 'classes.dex' => 'dex']): UploadedFile {
        $path             = tempnam(sys_get_temp_dir(), 'apk-test-');
        $this->apkPaths[] = $path;
        $archive          = new ZipArchive;
        $archive->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $contents) {
            $archive->addFromString($entry, $contents);
        }
        $archive->close();

        return new UploadedFile($path, $name, 'application/vnd.android.package-archive', null, true);
    };
    $this->payload = fn (int $code = 8): array => [
        'apk'           => ($this->makeApk)(),
        'version_name'  => '0.1.6',
        'version_code'  => $code,
        'release_notes' => 'Rasmlar yaxshilandi.',
    ];
});

afterEach(function (): void {
    foreach ($this->apkPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('requires authentication for viewing uploading and downloading', function (): void {
    $this->get('/admin/mobile-app')->assertRedirect();
    $this->get(route('mobile-app.download'))->assertRedirect('/admin/login');
    $this->post(route('mobile-app.releases.store'), ($this->payload)())->assertRedirect('/admin/login');
    $this->assertDatabaseCount('mobile_app_releases', 0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('shows download information to users but only shows upload controls to super admins', function (): void {
    $this->actingAs($this->user)->get('/admin/mobile-app')
        ->assertSuccessful()->assertSee('Hozircha APK yuklanmagan.')
        ->assertDontSee('Yangi APK yuklash')->assertDontSee('name="apk"', false);
    $this->actingAs($this->admin)->get('/admin/mobile-app')
        ->assertSuccessful()->assertSee('Yangi APK yuklash')->assertSee('name="apk"', false);
});

it('rejects forged upload requests from ordinary users', function (): void {
    $this->actingAs($this->user)->post(route('mobile-app.releases.store'), ($this->payload)())->assertForbidden();
    $this->assertDatabaseCount('mobile_app_releases', 0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('publishes a private apk and lets another authenticated user download it', function (): void {
    $payload  = ($this->payload)();
    $contents = file_get_contents($payload['apk']->getRealPath());
    $this->actingAs($this->admin)->post(route('mobile-app.releases.store'), $payload)
        ->assertRedirect('/admin/mobile-app')->assertSessionHasNoErrors()->assertSessionHas('status');
    $release = MobileAppRelease::latestRelease();
    expect($release->version_code)->toBe(8)
        ->and($release->uploaded_by)->toBe($this->admin->id)
        ->and($release->file_size)->toBe(strlen($contents))
        ->and(Storage::disk('local')->getVisibility($release->file_path))->toBe('private');
    Storage::disk('local')->assertExists($release->file_path);
    $this->actingAs($this->user)->get('/admin/mobile-app')->assertSuccessful()
        ->assertSee('0.1.6')->assertSee('APK yuklab olish')->assertSee('Rasmlar yaxshilandi.')
        ->assertDontSee('Yangi APK yuklash');
    $response = $this->get(route('mobile-app.download'))->assertSuccessful()
        ->assertDownload('oson-pos-0.1.6.apk')
        ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe($contents)
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

it('serves only the newest version and preserves previous release files', function (): void {
    $this->actingAs($this->admin)->post(route('mobile-app.releases.store'), ($this->payload)(8))->assertSessionHasNoErrors();
    $old                     = MobileAppRelease::latestRelease();
    $payload                 = ($this->payload)(9);
    $payload['version_name'] = '0.1.7';
    $this->post(route('mobile-app.releases.store'), $payload)->assertSessionHasNoErrors();
    expect(MobileAppRelease::latestRelease()->version_code)->toBe(9);
    Storage::disk('local')->assertExists($old->file_path);
    $this->actingAs($this->user)->get(route('mobile-app.download'))->assertDownload('oson-pos-0.1.7.apk');
});

it('rejects a version code that is not newer without leaving staged files', function (int $code): void {
    $this->actingAs($this->admin)->post(route('mobile-app.releases.store'), ($this->payload)(8))->assertSessionHasNoErrors();
    $old = MobileAppRelease::latestRelease();
    $this->post(route('mobile-app.releases.store'), ($this->payload)($code))->assertSessionHasErrors('version_code');
    expect(MobileAppRelease::latestRelease()->id)->toBe($old->id)
        ->and(Storage::disk('local')->allFiles('mobile-apk'))->toBe([$old->file_path]);
    $this->assertDatabaseCount('mobile_app_releases', 1);
})->with([8, 7]);

it('rejects invalid apk files', function (string $kind): void {
    $payload        = ($this->payload)();
    $payload['apk'] = match ($kind) {
        'extension' => ($this->makeApk)('release.zip'),
        'text'      => UploadedFile::fake()->createWithContent('release.apk', 'not an apk'),
        'manifest'  => ($this->makeApk)('release.apk', ['classes.dex' => 'dex']),
        'dex'       => ($this->makeApk)('release.apk', ['AndroidManifest.xml' => 'manifest']),
        'empty'     => ($this->makeApk)('release.apk', ['AndroidManifest.xml' => '', 'classes.dex' => 'dex']),
        'oversize'  => UploadedFile::fake()->create('release.apk', 153601),
    };
    $this->actingAs($this->admin)->post(route('mobile-app.releases.store'), $payload)->assertSessionHasErrors('apk');
    $this->assertDatabaseCount('mobile_app_releases', 0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with(['extension', 'text', 'manifest', 'dex', 'empty', 'oversize']);

it('validates release metadata before saving', function (string $field, mixed $value): void {
    $payload         = ($this->payload)();
    $payload[$field] = $value;
    $this->actingAs($this->admin)->post(route('mobile-app.releases.store'), $payload)->assertSessionHasErrors($field);
    $this->assertDatabaseCount('mobile_app_releases', 0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with([
    ['version_name', '../unsafe'],
    ['version_name', str_repeat('a', 41)],
    ['version_name', ''],
    ['version_code', 0],
    ['version_code', 'not-a-number'],
    ['version_code', 2100000001],
    ['release_notes', str_repeat('a', 10001)],
]);

it('returns not found when no release or no file is available', function (): void {
    $this->actingAs($this->user)->get(route('mobile-app.download'))->assertNotFound();
    MobileAppRelease::factory()->create();
    $this->get(route('mobile-app.download'))->assertNotFound();
    $this->get('/admin/mobile-app')->assertSuccessful()->assertSee('APK fayli topilmadi.')->assertDontSee('APK yuklab olish');
});

it('escapes release notes instead of rendering uploaded html', function (): void {
    MobileAppRelease::factory()->create(['release_notes' => '<script>alert(1)</script>']);
    $this->actingAs($this->user)->get('/admin/mobile-app')->assertSuccessful()
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
});

it('keeps the old release when storing the new file fails', function (): void {
    $old = MobileAppRelease::factory()->create(['version_code' => 7]);
    Storage::disk('local')->put($old->file_path, 'old apk');
    $file = Mockery::mock(UploadedFile::class);
    $file->shouldReceive('storeAs')->once()->andReturn(false);
    expect(fn () => app(App\Services\MobileAppReleaseService::class)->publish($this->admin, $file, ['version_name' => '0.1.6', 'version_code' => 8]))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(MobileAppRelease::latestRelease()->id)->toBe($old->id)
        ->and(Storage::disk('local')->get($old->file_path))->toBe('old apk');
});

it('accepts apk uploads above the unrelated livewire temporary upload limit', function (): void {
    $payload        = ($this->payload)();
    $payload['apk'] = UploadedFile::fake()->createWithContent('release.apk', file_get_contents($payload['apk']->getRealPath()))->size(45000);
    $this->actingAs($this->admin)->post(route('mobile-app.releases.store'), $payload)->assertSessionHasNoErrors();
    expect(MobileAppRelease::latestRelease()->file_size)->toBe(Storage::disk('local')->size(MobileAppRelease::latestRelease()->file_path));
});

it('cleans up the staged file if publishing metadata fails', function (): void {
    $old = MobileAppRelease::factory()->create(['version_code' => 7]);
    Storage::disk('local')->put($old->file_path, 'old apk');
    $dispatcher = MobileAppRelease::getEventDispatcher();
    MobileAppRelease::setEventDispatcher(clone $dispatcher);
    MobileAppRelease::creating(function (): void {
        throw new RuntimeException('Simulated database failure');
    });

    try {
        expect(fn () => app(App\Services\MobileAppReleaseService::class)->publish($this->admin, ($this->makeApk)(), ['version_name' => '0.1.6', 'version_code' => 8]))
            ->toThrow(RuntimeException::class, 'Simulated database failure');
    } finally {
        MobileAppRelease::setEventDispatcher($dispatcher);
    }

    expect(MobileAppRelease::latestRelease()->id)->toBe($old->id)
        ->and(Storage::disk('local')->allFiles('mobile-apk'))->toBe([$old->file_path]);
    $this->assertDatabaseCount('mobile_app_releases', 1);
});
