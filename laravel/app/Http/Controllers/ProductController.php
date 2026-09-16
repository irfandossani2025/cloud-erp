<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function storePhoto(Request $request, string $id)
    {
        $product = DB::table('products')->where('id', $id)->first();
        abort_unless($product, 404, 'Product not found.');
        $request->validate(['photo' => 'required|file|max:8192']);
        $file = $request->file('photo');
        $bytes = file_get_contents($file->getRealPath());
        $mime = $file->getMimeType();
        $this->checkImageBytes($bytes, $mime);
        Storage::disk('local')->put("products/{$id}", $bytes);
        $version = $product->photo_version + 1;
        $image = "/api/product-assets/{$id}?v={$version}";
        DB::table('products')->where('id', $id)->update([
            'image' => $image, 'photo_mime' => $mime, 'photo_version' => $version,
        ]);
        return response()->json(['image' => $image]);
    }

    public function asset(string $id)
    {
        $product = DB::table('products')->where('id', $id)->first();
        abort_unless($product && Storage::disk('local')->exists("products/{$id}"), 404, 'Not found');
        return response(Storage::disk('local')->get("products/{$id}"), 200, [
            'Content-Type' => $product->photo_mime ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function checkImageBytes(string $bytes, ?string $mime): void
    {
        $allowed = ['image/png', 'image/jpeg', 'image/webp'];
        if (!in_array($mime, $allowed, true) || strlen($bytes) > 8 * 1024 * 1024 || strlen($bytes) < 12) {
            throw ValidationException::withMessages(['photo' => 'Use PNG, JPG or WebP images under 8 MB.']);
        }
        $head = substr($bytes, 0, 12);
        $valid = match ($mime) {
            'image/png' => substr($head, 0, 4) === "\x89PNG",
            'image/jpeg' => substr($head, 0, 3) === "\xFF\xD8\xFF",
            'image/webp' => substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP',
            default => false,
        };
        if (!$valid) {
            throw ValidationException::withMessages(['photo' => 'The image file type does not match its content.']);
        }
    }
}
