<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** 401: a missing, revoked or mistyped key. */
class AuthenticationError extends ApiError
{
}
