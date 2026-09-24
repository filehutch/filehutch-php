<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** The request never got an answer: DNS, timeout, reset. */
class ConnectionError extends FileHutchError
{
}
