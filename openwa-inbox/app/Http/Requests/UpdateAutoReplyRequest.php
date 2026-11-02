<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAutoReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // protected by the RequireAdminPassword middleware
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['enabled' => $this->boolean('enabled')]);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'message' => ['nullable', 'required_if_accepted:enabled', 'string', 'max:'.config('openwa.auto_reply.max_length')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['message.required_if_accepted' => 'Write the reply message before turning automatic replies on.'];
    }
}
