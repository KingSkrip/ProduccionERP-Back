<?php

namespace App\Helpers;

class ValidationMessages
{
    public static function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string'   => 'El campo :attribute debe ser una cadena de texto.',
            'max'      => 'El campo :attribute no puede superar :max caracteres.',
            'email'    => 'El campo :attribute debe ser un correo válido.',
            'image'    => 'El campo :attribute debe ser una imagen válida.',
            'mimes'    => 'El campo :attribute debe ser un archivo de tipo: :values.',
            'unique'   => 'El :attribute ya está en uso.',
        ];
    }
}
