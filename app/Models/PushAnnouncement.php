<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Database\Factories\PushAnnouncementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PushAnnouncement extends Model
{
    /** @use HasFactory<PushAnnouncementFactory> */
    use HasFactory;

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const ACCEPTED = 'accepted';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    protected $fillable = ['request_id', 'sent_by', 'sender_name', 'title', 'body'];

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::QUEUED   => 'Navbatda',
            self::SENDING  => 'Yuborilmoqda',
            self::ACCEPTED => 'Firebase qabul qildi',
            self::FAILED   => 'Xatolik',
            self::UNKNOWN  => 'Natija noma’lum',
        };
    }
}
