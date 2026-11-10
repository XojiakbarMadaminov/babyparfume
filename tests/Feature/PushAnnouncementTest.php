<?php

use App\Models\User;
use Livewire\Livewire;
use Filament\Facades\Filament;
use App\Models\PushAnnouncement;
use App\Jobs\SendPushAnnouncement;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Client\Request;
use App\Services\FirebasePushSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Filament\Pages\PushAnnouncements;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
});

it('restricts the push page to super admins', function (): void {
    $this->get(PushAnnouncements::getUrl())->assertRedirect();

    $ordinaryAdmin = User::factory()->create();
    $ordinaryAdmin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($ordinaryAdmin)->get(PushAnnouncements::getUrl())->assertForbidden();

    $this->actingAs($this->superAdmin)->get(PushAnnouncements::getUrl())
        ->assertSuccessful()
        ->assertSee('Push xabar yuborish')
        ->assertSee('Bildirishnoma ko‘rinishi');
});

it('blocks sends when the server credentials are missing', function (): void {
    Queue::fake();
    config(['services.firebase_push' => []]);
    $this->actingAs($this->superAdmin);

    Livewire::test(PushAnnouncements::class)
        ->assertSee('Serverda Firebase loyiha ID’si va yuborish kaliti sozlanishi kerak.')
        ->assertActionDisabled('send');

    $this->assertDatabaseCount('push_announcements', 0);
    Queue::assertNothingPushed();
});

it('queues a validated announcement only after the super admin confirms', function (): void {
    Queue::fake();
    $this->mock(FirebasePushSender::class)->shouldReceive('isConfigured')->andReturnTrue();
    $this->actingAs($this->superAdmin);

    $page = Livewire::test(PushAnnouncements::class)
        ->fillForm(['title' => '  Yangi versiya  ', 'body' => 'Ilovani yangilang.'])
        ->mountAction('send')
        ->assertActionMounted('send');

    expect($page->instance()->getMountedAction()->getModalDescription())
        ->toContain('barcha do‘konlardagi umumiy e’lonlarga obuna bo‘lgan Android qurilmalarga');

    Queue::assertNothingPushed();
    $page->callMountedAction()->assertHasNoFormErrors()->assertSee('Navbatda');

    $announcement = PushAnnouncement::query()->sole();
    expect($announcement->title)->toBe('Yangi versiya')
        ->and($announcement->body)->toBe('Ilovani yangilang.')
        ->and($announcement->sent_by)->toBe($this->superAdmin->id)
        ->and($announcement->sender_name)->toBe($this->superAdmin->name)
        ->and($announcement->status)->toBe(PushAnnouncement::QUEUED);

    Queue::assertPushed(SendPushAnnouncement::class, 1);
    Queue::assertPushed(SendPushAnnouncement::class, fn (SendPushAnnouncement $job): bool => $job->announcementId === $announcement->id && $job->connection === 'database');
});

it('stores the real queue job alongside its announcement', function (): void {
    $this->mock(FirebasePushSender::class)->shouldReceive('isConfigured')->andReturnTrue();
    $this->actingAs($this->superAdmin);

    Livewire::test(PushAnnouncements::class)
        ->fillForm(['title' => 'E’lon', 'body' => 'Xabar yuborish sinovi.'])
        ->callAction('send');

    $this->assertDatabaseHas('push_announcements', ['title' => 'E’lon', 'status' => PushAnnouncement::QUEUED]);
    $this->assertDatabaseCount('jobs', 1);
});

it('validates the title and message before queuing a broadcast', function (): void {
    Queue::fake();
    $this->mock(FirebasePushSender::class)->shouldReceive('isConfigured')->andReturnTrue();
    $this->actingAs($this->superAdmin);

    Livewire::test(PushAnnouncements::class)
        ->fillForm(['title' => '  ', 'body' => str_repeat('x', 301)])
        ->callAction('send')
        ->assertHasFormErrors(['title', 'body' => 'max'], 'form');

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('push_announcements', 0);
});

it('blocks an already opened action when the super admin role is revoked', function (): void {
    Queue::fake();
    $this->mock(FirebasePushSender::class)->shouldReceive('isConfigured')->andReturnTrue();
    $this->actingAs($this->superAdmin);

    $page = Livewire::test(PushAnnouncements::class)
        ->fillForm(['title' => 'E’lon', 'body' => 'Sinov'])
        ->mountAction('send');

    $this->superAdmin->removeRole('super_admin');

    $page->callMountedAction()->assertForbidden();
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('push_announcements', 0);
});

it('does not queue the same request twice', function (): void {
    Queue::fake();
    $this->mock(FirebasePushSender::class)->shouldReceive('isConfigured')->andReturnTrue();
    $this->actingAs($this->superAdmin);
    $page = Livewire::test(PushAnnouncements::class);
    PushAnnouncement::factory()->create(['request_id' => $page->get('requestId')]);

    $page->fillForm(['title' => 'E’lon', 'body' => 'Sinov'])->callAction('send');

    $this->assertDatabaseCount('push_announcements', 1);
    Queue::assertNothingPushed();
});

it('records the Firebase acknowledgement and skips replays of the same job', function (): void {
    $announcement = PushAnnouncement::factory()->create();
    $messageId    = 'projects/oson-pos/messages/ann-1';
    $sender       = $this->mock(FirebasePushSender::class);
    $sender->shouldReceive('send')->once()->with($announcement->title, $announcement->body)->andReturn($messageId);

    $job = new SendPushAnnouncement($announcement->id);
    $job->handle($sender);
    $job->handle($sender);

    $announcement->refresh();
    expect($announcement->status)->toBe(PushAnnouncement::ACCEPTED)
        ->and($announcement->firebase_message_id)->toBe($messageId)
        ->and($announcement->finished_at)->not->toBeNull();
});

it('records failure or an uncertain outcome without resending', function (Throwable $error, string $status): void {
    $announcement = PushAnnouncement::factory()->create();
    $sender       = $this->mock(FirebasePushSender::class);
    $sender->shouldReceive('send')->once()->andThrow($error);

    $job = new SendPushAnnouncement($announcement->id);
    $job->handle($sender);
    $job->handle($sender);

    expect($announcement->refresh()->status)->toBe($status)
        ->and($announcement->firebase_message_id)->toBeNull();
})->with([
    'rejected'  => [new RuntimeException('Invalid credentials'), PushAnnouncement::FAILED],
    'timed out' => [new ConnectionException('Timed out'), PushAnnouncement::UNKNOWN],
]);

it('uses FCM HTTP v1 to send a notification to the common Android topic', function (): void {
    config([
        'services.firebase_push.project_id'  => 'oson-pos-test',
        'services.firebase_push.credentials' => __FILE__,
    ]);
    $sender = Mockery::mock(FirebasePushSender::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $sender->shouldReceive('accessToken')->andReturn('fake-access-token');
    $url       = 'https://fcm.googleapis.com/v1/projects/oson-pos-test/messages:send';
    $messageId = 'projects/oson-pos-test/messages/ann-1';
    Http::preventStrayRequests();
    Http::fake([$url => Http::response(['name' => $messageId])]);

    expect($sender->send('Oson POS', 'Ilovani yangilang.'))->toBe($messageId);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === $url
        && $request->hasHeader('Authorization', 'Bearer fake-access-token')
        && $request['message']['topic'] === 'oson_pos_announcements'
        && $request['message']['notification'] === ['title' => 'Oson POS', 'body' => 'Ilovani yangilang.']
        && $request['message']['android']['notification']['channel_id'] === 'oson_pos_announcements');
});
