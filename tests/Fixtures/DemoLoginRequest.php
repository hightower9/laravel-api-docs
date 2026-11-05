<?php

namespace Hightower9\ApiDocs\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest fixture whose rules become the login request body.
 */
class DemoLoginRequest extends FormRequest
{
    /**
     * Get the validation rules.
     */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
