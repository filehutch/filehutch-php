<?php

declare(strict_types=1);

namespace FileHutch\Exceptions;

/** Content type or size the policy refuses, or a policy that doesn't exist. */
class PolicyError extends InvalidRequestError
{
}
