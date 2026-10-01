<?php

namespace App\Avatars;

use App\Enums\AvatarImageFailure;
use RuntimeException;
use Throwable;

/** Un refus connu de {@see AvatarImage::normalize()}, porteur de sa cause. */
final class AvatarImageException extends RuntimeException
{
    public function __construct(public readonly AvatarImageFailure $failure, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
