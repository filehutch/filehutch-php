<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** A declarative config the API refused: unknown key, bad size, bad name. */
class ConfigError extends InvalidRequestError
{
}
