<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * Создаёт бронирование после проверки бизнес-правил.
     *
     * @param  array{room_id: int|string, title: string, starts_at: string, ends_at: string}  $data
     */
    public function createBooking(User $user, array $data): Booking
    {
        // ISO 8601 с явным смещением уже проверен в StoreBookingRequest.
        // Carbon сохраняет момент времени, учитывая смещение из входной строки.
        $startsAt = CarbonImmutable::parse($data['starts_at']);
        $endsAt = CarbonImmutable::parse($data['ends_at']);

        $this->validateIntervalOrder($startsAt, $endsAt);
        $this->validateDuration($startsAt, $endsAt);

        $booking = DB::transaction(function () use ($user, $data, $startsAt, $endsAt): Booking {
            // Блокируем строку комнаты. Все запросы на бронирование одной комнаты
            // должны брать эту блокировку до проверки пересечений.
            $room = Room::query()
                ->lockForUpdate()
                ->findOrFail($data['room_id']);

            $room->load('office');
            $officeTimezone = $room->office->timezone;

            // Рабочее время проверяется по локальному времени офиса,
            // но сами моменты времени перед сравнением и сохранением приводятся к UTC.
            $startsAtLocal = $startsAt->setTimezone($officeTimezone);
            $endsAtLocal = $endsAt->setTimezone($officeTimezone);

            $this->validateQuarterHourGrid($startsAtLocal, $endsAtLocal);
            $this->validateOfficeHours($startsAtLocal, $endsAtLocal);

            $startsAtUtc = $startsAt->setTimezone('UTC');
            $endsAtUtc = $endsAt->setTimezone('UTC');

            $this->ensureRoomIsAvailable($room->id, $startsAtUtc, $endsAtUtc);

            // Связь выставляет user_id из текущего пользователя.
            // Клиент не может выбрать автора через входной параметр.
            return $user->bookings()->create([
                'room_id' => $room->id,
                'title' => $data['title'],
                'starts_at' => $startsAtUtc,
                'ends_at' => $endsAtUtc,
            ]);
        });

        // Логируем только после успешного commit транзакции.
        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
        ]);

        return $booking;
    }

    public function cancelBooking(User $user, int $bookingId): void
    {
        // Ищем только среди бронирований текущего пользователя.
        // Чужая бронь для него не существует и вернёт 404.
        $booking = $user->bookings()->findOrFail($bookingId);
        $booking->delete();

        Log::channel('bookings')->info('Booking cancelled', [
            'booking_id' => $booking->id,
        ]);
    }

    private function validateIntervalOrder(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): void {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => ['Время окончания должно быть позже времени начала.'],
            ]);
        }
    }

    private function validateDuration(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): void {
        // Сравниваем реальные моменты времени, а не локальные часы.
        $durationInSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();
        $minimumDuration = 15 * 60;
        $maximumDuration = 4 * 60 * 60;

        if ($durationInSeconds < $minimumDuration || $durationInSeconds > $maximumDuration) {
            throw ValidationException::withMessages([
                'ends_at' => ['Продолжительность встречи должна быть от 15 минут до 4 часов.'],
            ]);
        }
    }

    private function validateQuarterHourGrid(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): void {
        $errors = [];

        if (! $this->isOnQuarterHourGrid($startsAt)) {
            $errors['starts_at'] = ['Начало встречи должно соответствовать шагу 15 минут.'];
        }

        if (! $this->isOnQuarterHourGrid($endsAt)) {
            $errors['ends_at'] = ['Окончание встречи должно соответствовать шагу 15 минут.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isOnQuarterHourGrid(CarbonImmutable $date): bool
    {
        return ((int) $date->format('i')) % 15 === 0
            && $date->format('s.u') === '00.000000';
    }

    private function validateOfficeHours(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): void {
        $officeOpensAt = $startsAt->startOfDay()->setTime(9, 0);
        $officeClosesAt = $startsAt->startOfDay()->setTime(20, 0);
        $errors = [];

        if ($startsAt->lessThan($officeOpensAt)) {
            $errors['starts_at'] = ['Встреча не может начинаться раньше 09:00 по местному времени офиса.'];
        }

        if ($endsAt->greaterThan($officeClosesAt)) {
            $errors['ends_at'] = ['Встреча должна завершаться не позже 20:00 по местному времени офиса.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function ensureRoomIsAvailable(
        int $roomId,
        CarbonImmutable $startsAtUtc,
        CarbonImmutable $endsAtUtc
    ): void {
        // В таблице PostgreSQL timestamp without time zone, поэтому сравниваем
        // UTC-значения в едином формате без сохранения смещения клиента.
        $startsAtForDatabase = $startsAtUtc->format('Y-m-d H:i:s');
        $endsAtForDatabase = $endsAtUtc->format('Y-m-d H:i:s');

        $hasConflict = Booking::query()
            ->where('room_id', $roomId)
            ->where('starts_at', '<', $endsAtForDatabase)
            ->where('ends_at', '>', $startsAtForDatabase)
            ->exists();

        if ($hasConflict) {
            throw ValidationException::withMessages([
                'starts_at' => ['Выбранное время уже занято в этой переговорной.'],
            ]);
        }
    }
}
