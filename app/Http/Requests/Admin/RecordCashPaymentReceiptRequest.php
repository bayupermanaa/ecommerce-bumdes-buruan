<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RecordCashPaymentReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin_bumdes';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'cash_received_by_name' => ['required', 'string', 'max:255'],
            'cash_received_at' => ['required', 'date', 'before_or_equal:now'],
            'cash_received_confirmation' => ['accepted'],
        ];
    }
}
