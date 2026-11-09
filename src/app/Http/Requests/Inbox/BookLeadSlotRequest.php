<?php

namespace App\Http\Requests\Inbox;

use Illuminate\Foundation\Http\FormRequest;

class BookLeadSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'member_id' => ['required', 'integer'],
            'address' => ['required', 'string', 'max:500'],
        ];
    }
}
