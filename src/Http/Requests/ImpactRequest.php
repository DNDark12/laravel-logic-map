<?php

namespace DNDark\LogicMap\Http\Requests;

use DNDark\LogicMap\Services\Query\ApiResult;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ImpactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $ref = ['nullable', 'string', 'max:255', 'regex:/^(?!-)[A-Za-z0-9._\/@{}~^:+-]+$/'];

        return [
            'symbol' => ['nullable', 'string', 'max:2048'],
            'base' => $ref,
            'head' => $ref,
            'format' => ['nullable', 'string', 'in:json,markdown'],
            'runtime_sessions' => ['nullable', 'array', 'max:100'],
            'runtime_sessions.*' => ['string', 'max:128'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ApiResult::failure(
            'The impact request is invalid.',
            ['code' => 'validation_failed', 'fields' => $validator->errors()->toArray()],
            422,
        )->toResponse());
    }
}
