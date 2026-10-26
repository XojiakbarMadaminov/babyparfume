<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Product;
use App\Models\PrintJob;
use App\Models\PrintAgent;
use Illuminate\Support\Str;
use App\Models\PrintProfile;
use App\Models\PrintDocument;
use App\Models\PrintStoreSetting;
use App\Services\Printing\PrintAccess;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use App\Services\Printing\PrintAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set(['printing.enabled' => true, 'flutter.enabled' => true]);
    Storage::fake('local');
    $this->store = Store::query()->create(['name' => 'Print test', 'address' => 'Test', 'phone' => '+998901234567']);
    $this->other = Store::query()->create(['name' => 'Boshqa', 'address' => 'Test', 'phone' => '+998901234568']);
    $this->user  = User::factory()->create(['current_store_id' => $this->store->id]);
    $this->user->stores()->attach($this->store);
    foreach (PrintAccess::PERMISSIONS as $p) {
        $this->user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }
    $login = $this->postJson('/api/flutter/v1/login', ['email' => $this->user->email, 'password' => 'password',
        'device_uuid'                                          => (string) Str::uuid(), 'device_name' => 'Printer test', 'platform' => 'android'])->assertCreated();
    $this->token      = $login->json('data.token');
    $code             = app(PrintAgentService::class)->issueCode($this->user, $this->store);
    $pair             = $this->postJson('/api/print-agent/v1/pair', ['code' => $code, 'name' => 'Do‘kon PC', 'version' => '1.0'])->assertCreated();
    $this->agentToken = $pair->json('data.token');
    $this->agent      = PrintAgent::query()->findOrFail($pair->json('data.agent_id'));
    $this->printer    = $this->agent->printers()->create(['local_key' => hash('sha256', 'XP365'), 'name' => 'XP365',
        'loaded_media'                                                => 'barcode', 'capabilities' => ['max_width_mm' => 76, 'dpi' => 203]]);
    $this->profile = PrintProfile::query()->create(['store_id' => $this->store->id, 'printer_id' => $this->printer->id,
        'name'                                                 => '30×20', 'document_type' => 'barcode', 'width_mm' => 30, 'height_mm' => 20,
        'layout'                                               => ['margin_mm' => 1, 'show_price' => true], 'is_default' => true]);
    $this->product        = Product::factory()->create(['store_id' => $this->store->id, 'name' => 'Ўзбек атир', 'barcode' => '0012345678905', 'type' => 'package']);
    $this->base           = '/api/flutter/v1/stores/' . $this->store->id;
    $this->previewPayload = ['document_type' => 'barcode', 'paper_profile_id' => $this->profile->id,
        'paper_profile_version'              => 1, 'items' => [['product_id' => $this->product->id, 'copies' => 2]]];
});

function makePrintingJob(object $test): array
{
    $preview = $test->withToken($test->token)->postJson($test->base . '/printing/preview', $test->previewPayload)->assertCreated();
    $intent  = ['client_job_id' => (string) Str::uuid(), 'requested_at' => now()->toIso8601String(), 'document_token' => $preview->json('data.document_token')];
    $job     = $test->withToken($test->token)->postJson($test->base . '/print-jobs', $intent)->assertCreated();

    return [$job->json('data.id'), $intent, $preview->json('data.document_id')];
}

it('creates an immutable barcode preview without printing or changing the product', function (): void {
    $p = $this->withToken($this->token)->postJson($this->base . '/printing/preview', $this->previewPayload)->assertCreated()->assertJsonPath('data.total_labels', 2);
    $this->assertDatabaseCount('print_jobs', 0);
    $d = PrintDocument::query()->findOrFail($p->json('data.document_id'));
    expect($d->payload['labels'][0]['barcode'])->toBe('0012345678905');
    $bytes = Storage::disk('local')->get($d->path);
    if ($target = getenv('PRINTING_PDF_FIXTURE')) {
        file_put_contents($target, $bytes);
    }
    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()->and(hash('sha256', $bytes))->toBe($d->sha256)
        ->and($bytes)->toContain('DejaVuSans');
    $this->product->update(['name' => 'Changed']);
    expect($d->fresh()->payload['labels'][0]['name'])->toBe('Ўзбек атир');
});

it('deduplicates jobs across retries including an expired preview token', function (): void {
    [$id, $intent] = makePrintingJob($this);
    $this->travel(11)->minutes();
    $this->withToken($this->token)->postJson($this->base . '/print-jobs', $intent)->assertOk()->assertJsonPath('data.id', $id);
    $this->assertDatabaseCount('print_jobs', 1);
    $this->withToken($this->token)->postJson($this->base . '/print-jobs', [...$intent, 'document_token' => str_repeat('x', 64)])->assertConflict();
});

it('does not release old jobs automatically after ten minutes', function (): void {
    [$id] = makePrintingJob($this);
    $this->travel(10)->minutes();
    $this->withToken($this->agentToken)->postJson('/api/print-agent/v1/jobs/claim')->assertOk()->assertJsonPath('data', null);
    expect(PrintJob::query()->find($id)->status)->toBe('expired');
});

it('requires matching media and preserves the selected printer', function (): void {
    [$id] = makePrintingJob($this);
    $this->printer->update(['loaded_media' => 'receipt']);
    $this->withToken($this->agentToken)->postJson('/api/print-agent/v1/jobs/claim')->assertOk()->assertJsonPath('data', null);
    expect(PrintJob::query()->find($id)->error_code)->toBe('media_mismatch');
    $this->printer->update(['loaded_media' => 'barcode']);
    $this->withToken($this->agentToken)->postJson('/api/print-agent/v1/jobs/claim')->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.profile.printer_name', 'XP365');
});

it('handles the submission handshake and idempotent acknowledgements', function (): void {
    [$id]     = makePrintingJob($this);
    $c        = $this->withToken($this->agentToken)->postJson('/api/print-agent/v1/jobs/claim')->assertOk();
    $claim    = $c->json('data.claim_token');
    $endpoint = '/api/print-agent/v1/jobs/' . $id . '/events';
    foreach (['rendering', 'submitting', 'spooled'] as $state) {
        $event = ['event_id' => (string) Str::uuid(), 'claim_token' => $claim, 'status' => $state];
        if ($state === 'spooled') {
            $event['windows_job_id'] = 77;
        }
        $this->withToken($this->agentToken)->postJson($endpoint, $event)->assertOk()->assertJsonPath('data.status', $state);
        $this->withToken($this->agentToken)->postJson($endpoint, $event)->assertOk();
    }
    $this->travel(11)->minutes();
    $this->withToken($this->agentToken)->postJson('/api/print-agent/v1/jobs/claim')->assertOk()->assertJsonPath('data', null);
    expect(PrintJob::query()->find($id)->status)->toBe('spooled');
});

it('never retries an abandoned claimed job automatically', function (): void {
    [$id] = makePrintingJob($this);
    $this->withToken($this->agentToken)->postJson('/api/print-agent/v1/jobs/claim')->assertOk();
    $this->travel(91)->seconds();
    $this->withToken($this->agentToken)->postJson('/api/print-agent/v1/jobs/claim')->assertOk()->assertJsonPath('data', null);
    expect(PrintJob::query()->find($id)->status)->toBe('unknown');
});

it('rejects other stores and revoked user permissions', function (): void {
    $this->withToken($this->token)->getJson('/api/flutter/v1/stores/' . $this->other->id . '/printing/options')->assertForbidden();
    $this->user->revokePermissionTo('print_barcodes');
    $this->withToken($this->token)->postJson($this->base . '/printing/preview', $this->previewPayload)->assertForbidden();
});

it('rejects printer widths and expired or reused pairing codes', function (): void {
    $this->profile->update(['width_mm' => 85]);
    $this->withToken($this->token)->postJson($this->base . '/printing/preview', $this->previewPayload)->assertUnprocessable();
    $code = app(PrintAgentService::class)->issueCode($this->user, $this->store);
    $this->travel(11)->minutes();
    $this->postJson('/api/print-agent/v1/pair', ['code' => $code, 'name' => 'PC', 'version' => '1'])->assertUnprocessable();
});

it('cancels unsubmitted jobs and revokes agents without moving their jobs', function (): void {
    [$id] = makePrintingJob($this);
    $this->withToken($this->token)->postJson($this->base . '/print-jobs/' . $id . '/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    app(PrintAgentService::class)->revoke($this->user, $this->store, $this->agent->id);
    $this->withToken($this->agentToken)->postJson('/api/print-agent/v1/jobs/claim')->assertUnauthorized();
});
it('preserves local receipt discounts and deduplicates automatic printing by sale identity', function (): void {
    $this->profile->update(['document_type' => 'receipt', 'width_mm' => 58, 'height_mm' => null, 'default_copies' => 1]);
    $this->printer->update(['loaded_media' => 'receipt']);
    PrintStoreSetting::query()->create(['store_id' => $this->store->id, 'auto_print_receipt' => true]);
    $body = ['client_job_id' => (string) Str::uuid(), 'requested_at' => now()->toIso8601String(), 'document_type' => 'receipt',
        'paper_profile_id'   => $this->profile->id, 'paper_profile_version' => 1, 'copies' => 1, 'trigger' => 'automatic',
        'source'             => ['kind' => 'local_sale', 'local_sale_id' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid(),
            'snapshot'                  => ['date' => now()->toIso8601String(), 'items' => [['name' => 'Атир', 'qty' => 1, 'price' => '15000', 'discount' => '1000']],
                'total_amount'                     => '12000', 'paid_amount' => '5000', 'remaining_amount' => '7000', 'receipt_discount' => '2000', 'payment_type' => 'partial', 'cash' => '5000', 'card' => '0']]];
    $first = $this->withToken($this->token)->postJson($this->base . '/print-jobs', $body)->assertCreated();
    $this->withToken($this->token)->postJson($this->base . '/print-jobs', [...$body, 'client_job_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    $d = PrintJob::query()->first()->document;
    expect(Storage::disk('local')->get($d->path))->toContain('DejaVuSans-Bold');
    if ($target = getenv('PRINTING_PDF_FIXTURE')) {
        file_put_contents(dirname($target) . '/printing-receipt-smoke.pdf', Storage::disk('local')->get($d->path));
    }
    expect($d->payload['status_label'])->toBe('Server tasdig‘i kutilmoqda')->and($d->payload['totals']['amount'])->toBe('12000');
    $this->assertDatabaseCount('print_jobs', 1);
    $this->assertDatabaseCount('print_documents', 1);
    $this->assertDatabaseCount('sales', 0);
});

it('requires a fresh confirmation and records reprinted receipt copies', function (): void {
    [$id] = $result = makePrintingJob($this);
    $this->travel(11)->minutes();
    $preview = $this->withToken($this->token)->postJson($this->base . '/printing/preview', $this->previewPayload)->assertCreated();
    $this->withToken($this->token)->postJson($this->base . '/print-jobs', [
        'client_job_id'  => (string) Str::uuid(), 'requested_at' => now()->subMinutes(11)->toIso8601String(),
        'document_token' => $preview->json('data.document_token')])->assertUnprocessable();
    $this->withToken($this->token)->postJson($this->base . '/print-jobs/' . $id . '/reprint', [
        'client_job_id' => (string) Str::uuid(), 'requested_at' => now()->toIso8601String(), 'reason' => 'Yangi tasdiq'])->assertCreated()->assertJsonPath('data.reprint_of', $id);
    $this->assertDatabaseCount('print_jobs', 2);
    $this->profile->update(['max_copies' => 1]);
    $this->withToken($this->token)->postJson($this->base . '/print-jobs/' . $id . '/reprint', [
        'client_job_id' => (string) Str::uuid(), 'requested_at' => now()->toIso8601String(), 'reason' => 'Limit yangilangan'])->assertUnprocessable();
});

it('does not reveal documents or claims to a different agent', function (): void {
    [$id]  = makePrintingJob($this);
    $token = str_repeat('a', 64);
    PrintAgent::query()->create(['store_id' => $this->other->id, 'active_store_id' => $this->other->id, 'name' => 'Boshqa PC', 'token_hash' => hash('sha256', $token)]);
    $this->withToken($token)->getJson('/api/print-agent/v1/jobs/' . $id . '/document')->assertNotFound();
    $this->withToken($token)->postJson('/api/print-agent/v1/jobs/claim')->assertOk()->assertJsonPath('data', null);
});

it('renders the admin printing page and validates route ownership', function (): void {
    $this->actingAs($this->user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(App\Filament\Pages\PrintingSettings::class)
        ->assertSee('Chop etish sozlamalari')->assertSee('XP365')
        ->assertSeeHtml('value="' . $this->printer->id . '" selected')
        ->call('save')->assertHasNoErrors();
    expect($this->profile->fresh()->store_id)->toBe($this->store->id);
});

it('creates admin test documents without creating a sale or product', function (): void {
    $this->actingAs($this->user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(App\Filament\Pages\PrintingSettings::class)->call('testProfile', $this->profile->id)->assertHasNoErrors();
    $this->assertDatabaseCount('sales', 0);
    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseCount('print_jobs', 1);
});

it('shows stale printer bindings as unbound and validates them before saving', function (): void {
    $oldAgent   = PrintAgent::factory()->create(['store_id' => $this->store->id, 'active_store_id' => null, 'revoked_at' => now()]);
    $oldPrinter = $oldAgent->printers()->create(['local_key' => hash('sha256', 'old printer'), 'name' => 'Old XP365']);
    $this->profile->update(['printer_id' => $oldPrinter->id]);
    $this->actingAs($this->user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));

    $page = Livewire\Livewire::test(App\Filament\Pages\PrintingSettings::class)
        ->assertSet('profiles.0.printer_id', null)
        ->assertSee('avvalgi printeri joriy kompyuterda mavjud emas')
        ->set('profiles.0.printer_id', $oldPrinter->id)
        ->call('save')
        ->assertHasErrors(['profiles.0.printer_id']);
    expect($this->profile->fresh()->printer_id)->toBe($oldPrinter->id);

    $page->set('profiles.0.printer_id', $this->printer->id)->call('save')->assertHasNoErrors()
        ->assertSet('disconnectedProfiles', []);
    expect($this->profile->fresh()->printer_id)->toBe($this->printer->id);
});

it('returns validation errors for stale media controls and rolls back profile changes', function (): void {
    $this->actingAs($this->user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));

    Livewire\Livewire::test(App\Filament\Pages\PrintingSettings::class)
        ->set('profiles.0.name', 'Must be rolled back')
        ->set('media.' . Str::uuid(), 'receipt')
        ->call('save')->assertHasErrors(['media']);
    expect($this->profile->fresh()->name)->toBe('30×20');
});

it('detaches revoked computer printers and disables automatic receipts without affecting other stores', function (): void {
    PrintStoreSetting::query()->create(['store_id' => $this->store->id, 'auto_print_receipt' => true]);
    PrintStoreSetting::query()->create(['store_id' => $this->other->id, 'auto_print_receipt' => true]);
    [$id] = makePrintingJob($this);
    PrintJob::query()->findOrFail($id)->update(['status' => 'failed_before_submission']);

    app(PrintAgentService::class)->revoke($this->user, $this->store, $this->agent->id);

    expect($this->profile->fresh()->printer_id)->toBeNull()
        ->and($this->profile->fresh()->version)->toBe(2)
        ->and(PrintStoreSetting::query()->find($this->store->id)->auto_print_receipt)->toBeFalse()
        ->and(PrintStoreSetting::query()->find($this->other->id)->auto_print_receipt)->toBeTrue()
        ->and(PrintJob::query()->findOrFail($id)->status)->toBe('cancelled');
});

it('prunes completed PDF files but preserves unknown print outcomes', function (): void {
    [$id,,$docId] = makePrintingJob($this);
    $job          = PrintJob::query()->findOrFail($id);
    $doc          = $job->document;
    $doc->update(['created_at' => now()->subDays(8)]);
    $job->update(['status' => 'unknown']);
    $this->artisan('printing:prune')->assertSuccessful();
    Storage::disk('local')->assertExists($doc->path);
    $job->update(['status' => 'spooled']);
    $this->artisan('printing:prune')->assertSuccessful();
    Storage::disk('local')->assertMissing($doc->path);
    expect(PrintJob::query()->find($id))->not->toBeNull();
});

it('prevents posting an invented claim state or altered profile', function (): void {
    [$id]  = makePrintingJob($this);
    $claim = $this->withToken($this->agentToken)->postJson('/api/print-agent/v1/jobs/claim')->assertOk();
    expect($claim->json('data.server_time'))->not->toBeNull();
    $endpoint = '/api/print-agent/v1/jobs/' . $id . '/events';
    $this->withToken($this->agentToken)->postJson($endpoint, ['event_id' => (string) Str::uuid(), 'claim_token' => (string) Str::uuid(), 'status' => 'submitting'])->assertConflict();
    $token = $claim->json('data.claim_token');
    $this->withToken($this->agentToken)->postJson($endpoint, ['event_id' => (string) Str::uuid(), 'claim_token' => $token, 'status' => 'rendering'])->assertOk();
    $this->profile->increment('version');
    $this->withToken($this->agentToken)->postJson($endpoint, ['event_id' => (string) Str::uuid(), 'claim_token' => $token, 'status' => 'submitting'])->assertConflict();
});
