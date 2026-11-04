<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(
        StoreBookingRequest $request,
        BookingService $bookingService
    ): JsonResponse {
        $booking = $bookingService->createBooking(
            $request->user(),
            $request->validated(),
        );

        return response()->json($booking, 201);
    }

    public function destroy(
        Request $request,
        BookingService $bookingService,
        int $id
    ): Response {
        $bookingService->cancelBooking($request->user(), $id);

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
