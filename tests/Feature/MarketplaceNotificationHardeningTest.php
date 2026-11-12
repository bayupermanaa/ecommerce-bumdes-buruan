<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\MarketplaceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MarketplaceNotificationHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_retries_distinct_events_and_recipients(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        foreach ([$first, $first] as $recipient) {
            MarketplaceNotification::sendOnce($recipient, 'Title', 'Message', '/notifikasi', 'order.1.created');
        }
        MarketplaceNotification::sendOnce($first, 'Title', 'Message', '/notifikasi', 'order.1.cancelled');
        MarketplaceNotification::sendOnce($second, 'Title', 'Message', '/notifikasi', 'order.1.created');

        $this->assertSame(2, $first->notifications()->count());
        $this->assertSame(1, $second->notifications()->count());
    }

    public function test_rollback_does_not_leave_notification_and_retry_creates_one(): void
    {
        $recipient = User::factory()->create();

        try {
            DB::transaction(function () use ($recipient): void {
                MarketplaceNotification::sendOnce($recipient, 'Title', 'Message', '/notifikasi', 'retry.1');
                throw new \RuntimeException('Rollback fixture');
            });
        } catch (\RuntimeException) {
            // Expected rollback.
        }

        $this->assertSame(0, $recipient->notifications()->count());
        DB::transaction(function () use ($recipient): void {
            MarketplaceNotification::sendOnce($recipient, 'Title', 'Message', '/notifikasi', 'retry.1');
        });
        MarketplaceNotification::sendOnce($recipient, 'Title', 'Message', '/notifikasi', 'retry.1');
        $this->assertSame(1, $recipient->notifications()->count());
    }

    public function test_notification_failure_does_not_rollback_business_write(): void
    {
        $recipient = User::factory()->create();
        Log::spy();

        DB::transaction(function () use ($recipient): void {
            DB::table('notifications')->insert([
                'id' => 'fixture', 'type' => 'fixture', 'notifiable_type' => $recipient->getMorphClass(),
                'notifiable_id' => $recipient->id, 'event_key' => 'fixture', 'data' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            MarketplaceNotification::sendOnce($recipient, 'Title', 'Message', '/notifikasi', "\xB1");
        });

        $this->assertDatabaseHas('notifications', ['id' => 'fixture']);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_notification_routes_only_expose_owners_records(): void
    {
        $owner = User::factory()->create(['role' => 'pelanggan']);
        $other = User::factory()->create(['role' => 'pelanggan']);
        MarketplaceNotification::sendOnce($owner, 'Private title', 'Private message', route('notifications.index'), 'private.1');
        $notification = $owner->notifications()->sole();

        $this->actingAs($other)->get(route('notifications.index'))->assertOk()->assertDontSee('Private title');
        $this->actingAs($other)->post(route('notifications.read', $notification->id))->assertNotFound();
        $this->actingAs($owner)->get(route('notifications.read', $notification->id))->assertStatus(405);
        $this->assertNull($notification->fresh()->read_at);
    }
}
