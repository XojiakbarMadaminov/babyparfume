<?php

namespace App\Services;

use Throwable;
use App\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductPhotoService
{
    /**
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024', 'dimensions:max_width=2048,max_height=2048'];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'photo.image'      => 'JPG, PNG yoki WebP rasm yuklang.',
            'photo.mimes'      => 'Faqat JPG, PNG yoki WebP rasm yuklang.',
            'photo.max'        => 'Rasm 1 MB dan oshmasin. Kichikroq rasm tanlang.',
            'photo.dimensions' => 'Rasm o‘lchami 2048 × 2048 dan oshmasin.',
        ];
    }

    public function replace(Product $product, UploadedFile $photo): void
    {
        $fileName = Str::uuid() . '.' . $photo->extension();

        try {
            $product->addMedia($photo)
                ->usingFileName($fileName)
                ->toMediaCollection(Product::IMAGE_COLLECTION);
        } catch (Throwable $exception) {
            $product->media()
                ->where('collection_name', Product::IMAGE_COLLECTION)
                ->where('file_name', $fileName)
                ->get()
                ->each(fn (Media $media): ?bool => $media->delete());

            throw $exception;
        }
    }
}
