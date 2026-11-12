<?php
namespace App\Notifications;
use Illuminate\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
class MarketplaceNotification extends Notification {
    public function __construct(public string $title, public string $message, public string $url, public string $eventKey) {}
    public function via(object $notifiable): array { return ['database']; }
    public function toArray(object $notifiable): array { return ['title'=>$this->title,'message'=>$this->message,'url'=>$this->url,'event_key'=>$this->eventKey]; }
    public static function sendOnce(User $recipient, string $title, string $message, string $url, string $eventKey): void
    {
        try {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(), 'type' => static::class, 'notifiable_type' => $recipient->getMorphClass(), 'notifiable_id' => $recipient->getKey(),
                'event_key' => $eventKey, 'data' => json_encode(['title'=>$title,'message'=>$message,'url'=>$url,'event_key'=>$eventKey], JSON_THROW_ON_ERROR), 'created_at'=>now(), 'updated_at'=>now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A retry for the same recipient and event is already recorded.
        } catch (\Throwable $exception) {
            try {
                Log::warning('Marketplace notification could not be stored', [
                    'recipient_id' => $recipient->getKey(),
                    'event_key' => $eventKey,
                    'exception' => $exception::class,
                ]);
            } catch (\Throwable) {
                // Logging must not break the primary business transaction either.
            }
        }
    }
}
