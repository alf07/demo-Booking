<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_booking_and_time_is_stored_in_utc(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();
        $room = $this->createRoom('Europe/Moscow');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'user_id' => $anotherUser->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-12-02T11:00:00+04:00',
            'ends_at' => '2026-12-02T12:00:00+04:00',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('room_id', $room->id);

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'starts_at' => '2026-12-02 07:00:00',
            'ends_at' => '2026-12-02 08:00:00',
        ]);

        $this->assertDatabaseMissing('bookings', [
            'room_id' => $room->id,
            'user_id' => $anotherUser->id,
        ]);
    }

    public function test_office_hours_are_checked_in_the_office_timezone(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom('Asia/Novosibirsk');

        Sanctum::actingAs($user);

        // 02:00 UTC — это 09:00 в Новосибирске.
        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Утренняя встреча',
            'starts_at' => '2026-12-02T02:00:00Z',
            'ends_at' => '2026-12-02T02:15:00Z',
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-12-02 02:00:00',
            'ends_at' => '2026-12-02 02:15:00',
        ]);
    }

    public function test_booking_outside_local_office_hours_is_rejected_even_if_client_uses_utc(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom('Asia/Novosibirsk');

        Sanctum::actingAs($user);

        // 01:00 UTC — это 08:00 в Новосибирске, до открытия офиса.
        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Слишком ранняя встреча',
            'starts_at' => '2026-12-02T01:00:00Z',
            'ends_at' => '2026-12-02T01:15:00Z',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_booking_can_start_at_nine_and_end_at_eight_pm(): void
    {
        $user = User::factory()->create();
        $morningRoom = $this->createRoom();
        $eveningRoom = $this->createRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $morningRoom->id,
            'title' => 'Начало рабочего дня',
            'starts_at' => '2026-12-02T09:00:00+03:00',
            'ends_at' => '2026-12-02T09:15:00+03:00',
        ])->assertCreated();

        $this->postJson('/api/bookings', [
            'room_id' => $eveningRoom->id,
            'title' => 'До закрытия',
            'starts_at' => '2026-12-02T16:00:00+03:00',
            'ends_at' => '2026-12-02T20:00:00+03:00',
        ])->assertCreated();
    }

    public function test_fifteen_minute_and_four_hour_durations_are_allowed(): void
    {
        $user = User::factory()->create();
        $shortMeetingRoom = $this->createRoom();
        $longMeetingRoom = $this->createRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $shortMeetingRoom->id,
            'title' => 'Минимальная встреча',
            'starts_at' => '2026-12-02T10:00:00+03:00',
            'ends_at' => '2026-12-02T10:15:00+03:00',
        ])->assertCreated();

        $this->postJson('/api/bookings', [
            'room_id' => $longMeetingRoom->id,
            'title' => 'Максимальная встреча',
            'starts_at' => '2026-12-02T10:00:00+03:00',
            'ends_at' => '2026-12-02T14:00:00+03:00',
        ])->assertCreated();
    }

    public function test_bookings_that_touch_at_the_boundary_are_allowed(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Первая встреча',
            'starts_at' => '2026-12-02T10:00:00+03:00',
            'ends_at' => '2026-12-02T11:00:00+03:00',
        ])->assertCreated();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Вторая встреча',
            'starts_at' => '2026-12-02T11:00:00+03:00',
            'ends_at' => '2026-12-02T12:00:00+03:00',
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    #[DataProvider('overlappingIntervals')]
    public function test_all_types_of_overlapping_intervals_are_rejected(
        string $newStartsAt,
        string $newEndsAt
    ): void {
        $user = User::factory()->create();
        $room = $this->createRoom();

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-12-02 07:00:00',
            'ends_at' => '2026-12-02 08:00:00',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Пересекающаяся встреча',
            'starts_at' => $newStartsAt,
            'ends_at' => $newEndsAt,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function overlappingIntervals(): array
    {
        return [
            'overlap from the left' => [
                '2026-12-02T09:30:00+03:00',
                '2026-12-02T10:30:00+03:00',
            ],
            'overlap from the right' => [
                '2026-12-02T10:30:00+03:00',
                '2026-12-02T11:30:00+03:00',
            ],
            'new interval inside existing' => [
                '2026-12-02T10:15:00+03:00',
                '2026-12-02T10:45:00+03:00',
            ],
            'new interval contains existing' => [
                '2026-12-02T09:00:00+03:00',
                '2026-12-02T12:00:00+03:00',
            ],
        ];
    }

    public function test_booking_in_another_room_does_not_conflict(): void
    {
        $user = User::factory()->create();
        $firstRoom = $this->createRoom();
        $secondRoom = $this->createRoom();

        Booking::factory()->for($firstRoom)->create([
            'starts_at' => '2026-12-02 07:00:00',
            'ends_at' => '2026-12-02 08:00:00',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $secondRoom->id,
            'title' => 'Та же дата, другая комната',
            'starts_at' => '2026-12-02T10:00:00+03:00',
            'ends_at' => '2026-12-02T11:00:00+03:00',
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_past_dates_are_allowed_because_the_task_has_no_date_restrictions(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Бронирование прошлой даты',
            'starts_at' => '2020-12-02T10:00:00+03:00',
            'ends_at' => '2020-12-02T10:15:00+03:00',
        ])->assertCreated();
    }

    public function test_store_requires_authentication(): void
    {
        $room = $this->createRoom();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-12-02T10:00:00+03:00',
            'ends_at' => '2026-12-02T11:00:00+03:00',
        ])->assertUnauthorized();
    }

    #[DataProvider('invalidBookingIntervals')]
    public function test_invalid_booking_intervals_are_rejected(
        string $startsAt,
        string $endsAt,
        string $errorField
    ): void {
        $user = User::factory()->create();
        $room = $this->createRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Некорректная встреча',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors($errorField);
    }

    public static function invalidBookingIntervals(): array
    {
        return [
            'end before start' => [
                '2026-12-02T11:00:00+03:00',
                '2026-12-02T10:00:00+03:00',
                'ends_at',
            ],
            'zero duration' => [
                '2026-12-02T10:00:00+03:00',
                '2026-12-02T10:00:00+03:00',
                'ends_at',
            ],
            'less than fifteen minutes' => [
                '2026-12-02T10:00:00+03:00',
                '2026-12-02T10:10:00+03:00',
                'ends_at',
            ],
            'more than four hours' => [
                '2026-12-02T10:00:00+03:00',
                '2026-12-02T14:15:00+03:00',
                'ends_at',
            ],
            'start is not on a fifteen-minute boundary' => [
                '2026-12-02T10:07:00+03:00',
                '2026-12-02T10:22:00+03:00',
                'starts_at',
            ],
            'end is not on a fifteen-minute boundary' => [
                '2026-12-02T10:00:00+03:00',
                '2026-12-02T10:17:00+03:00',
                'ends_at',
            ],
            'starts before office opens' => [
                '2026-12-02T08:45:00+03:00',
                '2026-12-02T09:00:00+03:00',
                'starts_at',
            ],
            'ends after office closes' => [
                '2026-12-02T19:45:00+03:00',
                '2026-12-02T20:15:00+03:00',
                'ends_at',
            ],
        ];
    }

    #[DataProvider('invalidIso8601Values')]
    public function test_invalid_iso8601_values_are_rejected(string $startsAt): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Некорректная дата',
            'starts_at' => $startsAt,
            'ends_at' => '2026-12-02T11:00:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public static function invalidIso8601Values(): array
    {
        return [
            'missing timezone' => ['2026-12-02T10:00:00'],
            'invalid calendar date' => ['2026-02-30T10:00:00+03:00'],
            'offset greater than fourteen hours' => ['2026-12-02T10:00:00+15:00'],
            'not ISO date-time' => ['2026-12-02 10:00:00+03:00'],
        ];
    }

    private function createRoom(string $timezone = 'Europe/Moscow'): Room
    {
        $office = Office::factory()->create([
            'timezone' => $timezone,
        ]);

        return Room::factory()->for($office)->create();
    }
}
