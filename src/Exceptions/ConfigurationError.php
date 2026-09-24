<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** No API key, a URL that isn't one, or an id that isn't one. Thrown before any request goes out. */
class ConfigurationError extends FileHutchError
{
}
