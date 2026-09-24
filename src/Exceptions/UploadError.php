<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** Storage refused the PUT, the upload expired or is incomplete, or the bytes are not what was declared. */
class UploadError extends ApiError
{
}
