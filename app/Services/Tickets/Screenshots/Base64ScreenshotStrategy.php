<?php

namespace App\Services\Tickets\Screenshots;

use finfo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Base64ScreenshotStrategy implements ScreenshotStorageStrategy
{
    /**
     * Determine whether the input is a valid base64-encoded image string.
     */
    public function supports(mixed $input): bool
    {
        return is_string($input) && self::parse($input) !== null;
    }

    /**
     * Decode and store a base64 image string onto the public disk.
     */
    public function store(mixed $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }

        $decoded = self::parse($input);

        if ($decoded === null) {
            return null;
        }

        $path = 'screenshots/'.Str::uuid()->toString().'.'.$decoded['extension'];
        Storage::disk('public')->put($path, $decoded['contents']);

        return $path;
    }

    /**
     * Parse a base64-encoded image string (data URI or raw base64) into binary contents and extension.
     *
     * @return array{contents: string, extension: string}|null
     */
    public static function parse(string $value): ?array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $extensionFromHeader = null;
        $payload = $trimmed;

        if (preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,(.+)$/is', $trimmed, $matches) === 1) {
            $rawExtension = Str::lower($matches[1]);
            $extensionFromHeader = $rawExtension === 'jpeg' ? 'jpg' : $rawExtension;
            $payload = $matches[2];
        }

        $normalizedPayload = preg_replace('/\s+/', '', $payload) ?? '';
        $binary = base64_decode($normalizedPayload, true);

        if ($binary === false || $binary === '' || strlen($binary) > 5 * 1024 * 1024) {
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($binary);

        $mimeToExtension = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        if (is_string($detectedMime) && isset($mimeToExtension[$detectedMime])) {
            return [
                'contents' => $binary,
                'extension' => $mimeToExtension[$detectedMime],
            ];
        }

        if ($extensionFromHeader !== null) {
            return [
                'contents' => $binary,
                'extension' => $extensionFromHeader,
            ];
        }

        return null;
    }
}
