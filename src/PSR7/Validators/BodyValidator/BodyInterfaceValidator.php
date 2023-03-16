<?php

declare(strict_types=1);

namespace League\OpenAPIValidation\PSR7\Validators\BodyValidator;

use League\OpenAPIValidation\PSR7\OperationAddress;
use Psr\Http\Message\MessageInterface;

interface BodyInterfaceValidator
{
    public function getBody(OperationAddress $addr, MessageInterface $message);
}
