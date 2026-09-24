<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** Unknown transform name, or a file that isn't an image. */
class TransformError extends InvalidRequestError
{
}
