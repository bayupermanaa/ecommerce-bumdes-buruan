<?php

namespace App\Http\Requests\Admin;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class RecordCashPaymentReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin_bumdes';
    }

    /**
     * Browser datetime-local has no timezone; the cash counter uses Bali time.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cash_received_by_name' => ['required', 'string', 'max:255'],
            'cash_received_at' => ['required', 'date_format:Y-m-d\TH:i', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }
                try {
                    $receivedAt = Carbon::createFromFormat('!Y-m-d\TH:i', $value, 'Asia/Makassar');
                    if ($receivedAt !== false && $receivedAt->isAfter(Carbon::now('UTC'))) {
                        $fail('Waktu penerimaan tunai tidak boleh berada di masa depan (WITA).');
                    }
                } catch (\Throwable) {
                    // The date_format rule reports malformed dates.
                }
            }],
            'cash_received_confirmation' => ['accepted'],
        ];
    }

    public function receivedAtUtc(): string
    {
        return Carbon::createFromFormat('!Y-m-d\TH:i', $this->validated('cash_received_at'), 'Asia/Makassar')
            ->utc()->format('Y-m-d H:i:s');
    }
}
