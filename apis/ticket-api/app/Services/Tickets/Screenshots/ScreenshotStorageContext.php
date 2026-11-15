<?php

namespace App\Services\Tickets\Screenshots;

use Illuminate\Validation\ValidationException;

class ScreenshotStorageContext
{
    /**
     * Ordered list of available screenshot storage strategies.
     *
     * @var list<ScreenshotStorageStrategy>
     */
    private array $strategies;

    public function __construct(
        UploadedFileScreenshotStrategy $uploadedFileStrategy = new UploadedFileScreenshotStrategy,
        Base64ScreenshotStrategy $base64Strategy = new Base64ScreenshotStrategy,
    ) {
        $this->strategies = [$uploadedFileStrategy, $base64Strategy];
    }

    /**
     * Store an optional screenshot using the first matching strategy.
     *
     * @throws ValidationException
     */
    public function store(mixed $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($input)) {
                return $strategy->store($input);
            }
        }

        if (is_string($input)) {
            throw ValidationException::withMessages([
                'screenshot' => ['The screenshot must be a valid image file or base64 image.'],
            ]);
        }

        return null;
    }
}
