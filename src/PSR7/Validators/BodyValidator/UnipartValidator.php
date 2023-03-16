<?php

declare(strict_types=1);

namespace League\OpenAPIValidation\PSR7\Validators\BodyValidator;

use cebe\openapi\spec\MediaType;
use League\OpenAPIValidation\PSR7\Exception\NoPath;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidBody;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\Validators\ValidationStrategy;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\SchemaValidator;
use Psr\Http\Message\MessageInterface;

use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function preg_match;

use const JSON_ERROR_NONE;

class UnipartValidator extends AbstractBodyValidator
{
    use ValidationStrategy;

    /** @var MediaType */
    protected $mediaTypeSpec;
    /** @var string */
    protected $contentType;

    public function __construct(MediaType $mediaTypeSpec, string $contentType)
    {
        $this->mediaTypeSpec = $mediaTypeSpec;
        $this->contentType   = $contentType;
    }

    public function getBody(OperationAddress $addr, MessageInterface $message)
    {
        if (preg_match('#^application/.*json$#', $this->contentType)) {
            $body = json_decode((string) $message->getBody(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw InvalidBody::becauseBodyIsNotValidJson(json_last_error_msg(), $addr);
            }
        } else {
            $body = (string) $message->getBody();
        }

        return $body;
    }

    /**
     * @throws NoPath
     * @throws ValidationFailed
     */
    public function validate(OperationAddress $addr, MessageInterface $message): void
    {
        $validator = new SchemaValidator($this->detectValidationStrategy($message));
        $body = $this->getBody($addr, $message);
        $schema = $this->mediaTypeSpec->schema;

        try {
            $validator->validate($body, $schema);
        } catch (SchemaMismatch $e) {
            throw InvalidBody::becauseBodyDoesNotMatchSchema($this->contentType, $addr, $e);
        }
    }
}
