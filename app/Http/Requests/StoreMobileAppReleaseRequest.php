<?php

namespace App\Http\Requests;

use App\Rules\AndroidApk;
use Illuminate\Foundation\Http\FormRequest;

class StoreMobileAppReleaseRequest extends FormRequest
{
    public const MAX_FILE_SIZE_KB = 153600;

    protected $redirectRoute = 'filament.admin.pages.mobile-app';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'apk'           => ['bail', 'required', 'file', 'max:' . self::MAX_FILE_SIZE_KB, 'extensions:apk', new AndroidApk],
            'version_name'  => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/'],
            'version_code'  => ['required', 'integer', 'min:1', 'max:2100000000'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'apk.required'          => 'APK faylini tanlang.',
            'apk.file'              => 'Yuklama fayl bo‘lishi kerak.',
            'apk.max'               => 'APK hajmi 150 MB dan oshmasligi kerak.',
            'apk.extensions'        => 'Faqat .apk fayl yuklash mumkin.',
            'apk.uploaded'          => 'Fayl yuklanmadi. Serverning upload limitlarini tekshiring.',
            'version_name.required' => 'Versiya nomini kiriting.',
            'version_name.string'   => 'Versiya nomi matn bo‘lishi kerak.',
            'version_name.max'      => 'Versiya nomi 40 belgidan oshmasligi kerak.',
            'version_name.regex'    => 'Versiya nomida faqat lotin harflari, raqamlar va . + _ - ishlating.',
            'version_code.required' => 'Versiya kodini kiriting.',
            'version_code.integer'  => 'Versiya kodi butun son bo‘lishi kerak.',
            'version_code.min'      => 'Versiya kodi kamida 1 bo‘lishi kerak.',
            'version_code.max'      => 'Versiya kodi 2100000000 dan oshmasligi kerak.',
            'release_notes.string'  => 'Yangiliklar matn bo‘lishi kerak.',
            'release_notes.max'     => 'Yangiliklar 10000 belgidan oshmasligi kerak.',
        ];
    }
}
