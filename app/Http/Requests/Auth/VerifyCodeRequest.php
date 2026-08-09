<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** AUTH-3 — exactly the configured number of digits. */
class VerifyCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $length = (int) config('gondal.auth.code_length', 6);

        return [
            'code' => ['required', 'string', 'size:'.$length, 'regex:/^[0-9]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.size' => 'The code is '.config('gondal.auth.code_length').' digits.',
            'code.regex' => 'The code is digits only.',
        ];
    }
}
