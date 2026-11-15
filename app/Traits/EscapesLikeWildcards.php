<?php

namespace App\Traits;

trait EscapesLikeWildcards
{
    /**
     * Escape SQL LIKE wildcard characters in a raw search string.
     */
    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Build a trimmed wildcard LIKE pattern with escaped special characters.
     */
    protected function toLikePattern(string $value): string
    {
        return '%'.$this->escapeLike(trim($value)).'%';
    }
}
