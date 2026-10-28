<?php

namespace App\Http\Requests\Tickets;

use App\Files\Rules\AllowedAttachment;
use App\Models\Tickets\TicketMessage;
use App\Tickets\TicketMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A staff text for a ticket (reply, internal note, question, resolution or reopen reason). Authorization is the
 * Action's P-59 check on the ticket; here only the shape of the input.
 */
class TicketMessageRequest extends FormRequest
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
            'visibility' => ['sometimes', Rule::in([TicketMessage::PUBLIC, TicketMessage::INTERNAL])],
            'body' => ['required', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:'.TicketMessages::MAX_FILES],
            'files.*' => ['file', new AllowedAttachment],
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploads(): array
    {
        return array_values($this->file('files', []));
    }
}
