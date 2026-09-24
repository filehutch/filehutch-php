<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** A webhook body was not signed by FileHutch with your endpoint's secret. */
class SignatureVerificationError extends FileHutchError
{
}
