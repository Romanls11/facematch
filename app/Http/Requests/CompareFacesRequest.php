<?php

namespace App\Http\Requests;

use App\Models\Comparison;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompareFacesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'target_image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'mode' => ['nullable', Rule::in([Comparison::MODE_COMPARE, Comparison::MODE_VERIFY])],
            'threshold' => ['nullable', 'numeric', 'between:50,99'],
            'forward' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Falta :attribute.',
            'file' => ':attribute debe ser un archivo.',
            'image' => ':attribute debe ser una imagen.',
            'mimes' => ':attribute debe ser JPG o PNG.',
            'max' => ':attribute no puede pesar más de 5 MB.',
            'uploaded' => 'No se pudo subir :attribute (¿pesa más de lo permitido por el servidor?).',
            'mode.in' => 'El modo debe ser «compare» (Comparar) o «verify» (Verificar).',
            'threshold.numeric' => 'El umbral debe ser un número.',
            'threshold.between' => 'El umbral debe estar entre 50 y 99.',
            'forward.boolean' => 'El campo forward debe ser verdadero o falso.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'source_image' => 'la imagen de origen',
            'target_image' => 'la imagen a comparar',
            'mode' => 'el modo',
            'threshold' => 'el umbral',
            'forward' => 'el envío',
        ];
    }

    public function mode(): string
    {
        return $this->input('mode') ?: Comparison::MODE_COMPARE;
    }

    public function threshold(): float
    {
        return $this->filled('threshold')
            ? (float) $this->input('threshold')
            : (float) config('facematch.default_threshold', 80);
    }

    /** null = aplicar FACE_FORWARD_ONLY_MATCHES. */
    public function forward(): ?bool
    {
        return $this->has('forward') && $this->input('forward') !== null && $this->input('forward') !== ''
            ? $this->boolean('forward')
            : null;
    }
}
