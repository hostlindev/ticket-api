<?php

namespace App\Services\Tickets\Screenshots;

interface ScreenshotStorageStrategy
{
    /**
     * Determine whether this strategy can handle the given screenshot input.
     */
    public function supports(mixed $input): bool;

    /**
     * Store the screenshot and return its relative public disk path, or null if not stored.
     */
    public function store(mixed $input): ?string;
}
