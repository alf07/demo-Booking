<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_cancel_their_own_booking(): void
    {
        $booking = Booking::factory()->create();

        Sanctum::actingAs($booking->user);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('bookings', [
            'id' => $booking->id,
        ]);
    }

    public function test_user_cannot_cancel_another_users_booking(): void
    {
        $booking = Booking::factory()->create();
        $anotherUser = User::factory()->create();

        Sanctum::actingAs($anotherUser);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertNotFound();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
        ]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertUnauthorized();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
        ]);
    }
}
