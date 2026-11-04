<?php

namespace App\Http\Requests;

use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Аутентификация уже проверяется middleware auth:sanctum.
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => [
                'bail',
                'required',
                'integer',
                'exists:rooms,id',
            ],
            'title' => [
                'bail',
                'required',
                'string',
                'max:255',
            ],
            'starts_at' => [
                'bail',
                'required',
                'string',
                'max:40',
                $this->iso8601WithOffsetRule(),
            ],
            'ends_at' => [
                'bail',
                'required',
                'string',
                'max:40',
                $this->iso8601WithOffsetRule(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.required' => 'Необходимо выбрать переговорную.',
            'room_id.integer' => 'Идентификатор переговорной должен быть целым числом.',
            'room_id.exists' => 'Выбранная переговорная не существует.',
            'title.required' => 'Необходимо указать название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи не должно превышать 255 символов.',
            'starts_at.required' => 'Необходимо указать начало встречи.',
            'ends_at.required' => 'Необходимо указать окончание встречи.',
        ];
    }

    private function iso8601WithOffsetRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! $this->isValidIso8601WithOffset($value)) {
                $fail('Поле должно содержать корректную дату ISO 8601 с часовым смещением.');
            }
        };
    }

    private function isValidIso8601WithOffset(string $value): bool
    {
        // Поддерживаются Z, ±HH, ±HHMM и ±HH:MM. Максимальное смещение — ±14:00.
        $pattern = '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-](?:(?:0\d|1[0-3])(?::?[0-5]\d)?|14(?::?00)?))$/D';

        if (preg_match($pattern, $value) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($value);
        } catch (\Throwable) {
            return false;
        }

        $errors = DateTimeImmutable::getLastErrors();

        return $errors === false
            || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
    }
}
