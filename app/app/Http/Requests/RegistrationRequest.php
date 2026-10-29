<?php

namespace App\Http\Requests;

use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;

class RegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Normalize::name($this->input('name')),
            'email' => Normalize::email($this->input('email')),
            'phone' => Normalize::phone($this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'privacy' => ['required', 'accepted'],
            'referral_code' => ['nullable', 'string', 'regex:/^[A-Z0-9]{12}$/'],
            'landing_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('marketing_landings', 'id')->where('status', 'published')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe tu nombre.',
            'name.max' => 'Usa un nombre de hasta 120 caracteres.',
            'email.*' => 'Escribe un correo electrónico válido.',
            'phone.*' => 'Incluye el prefijo de tu país, por ejemplo +59170000000.',
            'privacy.*' => 'Debes aceptar el aviso de privacidad para continuar.',
            'referral_code.*' => 'El código de invitación no es válido. Vuelve a abrir tu enlace.',
        ];
    }
}
