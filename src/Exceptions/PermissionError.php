<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** The key is valid but may not do this: a read-only key calling a write endpoint. */
class PermissionError extends AuthenticationError
{
}
