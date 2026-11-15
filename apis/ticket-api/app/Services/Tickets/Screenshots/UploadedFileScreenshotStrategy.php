<?php

namespace App\Services\Tickets\Screenshots;

use Illuminate\Http\UploadedFile;

class UploadedFileScreenshotStrategy implements ScreenshotStorageStrategy
{
    /**
     * Determine whether the input is an uploaded file.
     */
    public function supports(mixed $input): bool
    {
        return $input instanceof UploadedFile;
    }

    /**
     * Store the uploaded screenshot on the public disk.
     */
    public function store(mixed $input): ?string
    {
        if (! $input instanceof UploadedFile) {
            return null;
        }

        $path = $input->store('screenshots', 'public');

        return $path !== false ? $path : null;
    }
}
