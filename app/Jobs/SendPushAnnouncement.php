<?php

namespace App\Jobs;

use Throwable;
use App\Models\PushAnnouncement;
use Illuminate\Support\Facades\Log;
use App\Services\FirebasePushSender;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;

class SendPushAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public int $announcementId) {}

    public function handle(FirebasePushSender $sender): void
    {
        $claimed = PushAnnouncement::query()
            ->whereKey($this->announcementId)
            ->where('status', PushAnnouncement::QUEUED)
            ->update(['status' => PushAnnouncement::SENDING]);

        if ($claimed === 0) {
            return;
        }

        $announcement = PushAnnouncement::query()->findOrFail($this->announcementId);

        try {
            $messageId = $sender->send($announcement->title, $announcement->body);
        } catch (ConnectionException) {
            $this->finish(PushAnnouncement::UNKNOWN);

            return;
        } catch (Throwable $exception) {
            Log::warning('Push announcement was rejected.', [
                'announcement_id' => $this->announcementId,
                'error_type'      => $exception::class,
                'http_status'     => $exception instanceof RequestException ? $exception->response->status() : null,
            ]);
            $this->finish(PushAnnouncement::FAILED);

            return;
        }

        $this->finish($messageId === null ? PushAnnouncement::UNKNOWN : PushAnnouncement::ACCEPTED, $messageId);
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish(PushAnnouncement::UNKNOWN);

        PushAnnouncement::query()
            ->whereKey($this->announcementId)
            ->where('status', PushAnnouncement::QUEUED)
            ->update(['status' => PushAnnouncement::FAILED, 'finished_at' => now()]);
    }

    private function finish(string $status, ?string $messageId = null): void
    {
        PushAnnouncement::query()
            ->whereKey($this->announcementId)
            ->where('status', PushAnnouncement::SENDING)
            ->update([
                'status'              => $status,
                'firebase_message_id' => $messageId,
                'finished_at'         => now(),
            ]);
    }
}
