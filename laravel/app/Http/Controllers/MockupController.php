<?php

namespace App\Http\Controllers;

use App\Services\ErpAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MockupController extends Controller
{
    public function __construct(private ErpAccess $access) {}

    public function index(Request $request)
    {
        $agent = (string) $request->query('agent', '');
        $this->access->agent($request, $agent);
        $rows = DB::table('generations')
            ->where('agent', $agent)->where('kind', 'mockup')
            ->orderByDesc('created')->limit(30)->get(['id', 'result', 'created']);
        return response()->json($rows->map(fn ($r) => [...(array) $r, ...json_decode($r->result, true)]));
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'agent' => 'required|uuid',
            'productId' => 'required|uuid',
            'logo' => 'required|file|max:8192',
            'photo' => 'nullable|file|max:8192',
            'instruction' => 'nullable|string|max:1500',
        ]);
        $this->access->agent($request, $v['agent']);
        $product = DB::table('products')->where('id', $v['productId'])->first();
        abort_unless($product, 422, 'Select an inventory product.');
        $logoBytes = file_get_contents($request->file('logo')->getRealPath());
        $this->checkImageBytes($logoBytes, $request->file('logo')->getMimeType());
        $photoFile = $request->file('photo');
        if ($photoFile && $photoFile->getSize()) {
            $photoBytes = file_get_contents($photoFile->getRealPath());
            $photoMime = $photoFile->getMimeType();
        } else {
            abort_unless($product->image, 422, 'This product has no photograph. Upload one to continue.');
            $parsed = parse_url($product->image);
            abort_unless(
                ($parsed['scheme'] ?? null) === 'https' && in_array($parsed['host'] ?? '', ['luxurytrd.com', 'www.luxurytrd.com'], true),
                422,
                'Upload a product photograph for this product.'
            );
            $response = Http::withOptions(['allow_redirects' => false])->connectTimeout(10)->timeout(20)->get($product->image);
            abort_unless($response->successful(), 422, 'Could not load the product photograph. Upload it instead.');
            abort_if(strlen($response->body()) > 8 * 1024 * 1024, 422, 'Product photograph is too large.');
            $photoBytes = $response->body();
            $photoMime = strtok($response->header('Content-Type') ?: 'image/jpeg', ';');
        }
        $this->checkImageBytes($photoBytes, $photoMime);
        $key = config('erp.gemini_key');
        abort_unless($key, 422, 'AI mockups need a Gemini API key in the server environment.');
        $instruction = mb_substr((string) ($v['instruction'] ?? ''), 0, 1500);
        $prompt = "Create one realistic product branding mockup. First image is the actual {$product->name}, second is the customer's logo. Preserve the exact product shape, material and colour. Place the supplied logo naturally on the product, following its surface perspective, lighting and texture. Preserve the logo's lettering, colours and proportions as faithfully as possible. Use a clean studio background. Do not add extra branding or unrelated objects. Requested placement and printing finish: ".($instruction ?: 'Centred on the front, professional printed finish').'.';
        $model = config('erp.image_model');
        $response = Http::withHeaders(['x-goog-api-key' => $key])
            ->timeout(180)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                        ['inlineData' => ['mimeType' => $photoMime, 'data' => base64_encode($photoBytes)]],
                        ['inlineData' => ['mimeType' => $request->file('logo')->getMimeType(), 'data' => base64_encode($logoBytes)]],
                    ],
                ]],
                'generationConfig' => ['responseModalities' => ['IMAGE']],
            ]);
        abort_unless($response->successful(), 502, "Mockup generation failed ({$response->status()}). You can retry with the same images.");
        $base64 = collect($response->json('candidates.0.content.parts', []))
            ->pluck('inlineData.data')
            ->filter()
            ->first();
        abort_unless($base64, 502, 'No mockup image was returned.');
        $id = (string) Str::uuid();
        $path = "mockups/{$id}.png";
        Storage::disk('local')->put($path, base64_decode($base64));
        $record = ['productId' => $product->id, 'productName' => $product->name, 'path' => $path];
        DB::table('generations')->insert([
            'id' => $id, 'agent' => $v['agent'], 'kind' => 'mockup', 'prompt' => $instruction,
            'result' => json_encode($record, JSON_THROW_ON_ERROR), 'created' => now()->toIso8601String(),
        ]);
        return response()->json(['id' => $id, ...$record]);
    }

    public function asset(Request $request)
    {
        $path = (string) $request->query('path', '');
        abort_unless((bool) preg_match('#^mockups/([a-f0-9-]+)\.png$#', $path, $m), 404, 'Not found');
        $row = DB::table('generations')->where('id', $m[1])->where('kind', 'mockup')->first();
        abort_unless($row, 404, 'Not found');
        $this->access->agent($request, $row->agent);
        abort_unless(Storage::disk('local')->exists($path), 404, 'Not found');
        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function checkImageBytes(string $bytes, ?string $mime): void
    {
        $allowed = ['image/png', 'image/jpeg', 'image/webp'];
        if (!in_array($mime, $allowed, true) || strlen($bytes) > 8 * 1024 * 1024 || strlen($bytes) < 12) {
            throw ValidationException::withMessages(['image' => 'Use PNG, JPG or WebP images under 8 MB.']);
        }
        $head = substr($bytes, 0, 12);
        $valid = match ($mime) {
            'image/png' => substr($head, 0, 4) === "\x89PNG",
            'image/jpeg' => substr($head, 0, 3) === "\xFF\xD8\xFF",
            'image/webp' => substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP',
            default => false,
        };
        if (!$valid) {
            throw ValidationException::withMessages(['image' => 'The image file type does not match its content.']);
        }
    }
}
