<?php

declare(strict_types=1);

namespace League\OpenAPIValidation\PSR7\Validators\BodyValidator;

use cebe\openapi\spec\Encoding;
use cebe\openapi\spec\Header;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Schema;
use cebe\openapi\spec\Type as CebeType;
use InvalidArgumentException;
use League\OpenAPIValidation\PSR7\Exception\NoPath;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidBody;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidHeaders;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\MessageValidator;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\Validators\SerializedParameter;
use League\OpenAPIValidation\PSR7\Validators\ValidationStrategy;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\Exception\TypeMismatch;
use League\OpenAPIValidation\Schema\SchemaValidator;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Riverline\MultiPartParser\Converters\PSR7;
use Riverline\MultiPartParser\StreamedPart;

use function array_diff_assoc;
use function array_map;
use function array_replace;
use function array_shift;
use function explode;
use function in_array;
use function is_array;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function preg_match;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;

use const JSON_ERROR_NONE;

/**
 * Should validate multipart/* body types
 */
class MultipartValidator extends AbstractBodyValidator
{
    use ValidationStrategy;
    use BodyDeserialization;

    private const HEADER_CONTENT_TYPE = 'Content-Type';

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
        if ($message->getBody()->getSize()) {
            $document = PSR7::convert($message);

            return $this->deserializeBody($this->parseMultipartData($addr, $document), $this->mediaTypeSpec->schema);
        }

        if ($message instanceof ServerRequestInterface) {
            $body = (array) $message->getParsedBody();
            $files = $this->normalizeFiles($message->getUploadedFiles());

            return array_replace($body, $files);
        }

        return null;
    }

    /**
     * @throws NoPath
     * @throws ValidationFailed
     */
    public function validate(OperationAddress $addr, MessageInterface $message): void
    {
        /** @var Schema $schema */
        $schema = $this->mediaTypeSpec->schema;

        // 0. Multipart body message MUST be described with a set of object properties
        if ($schema->type !== CebeType::OBJECT) {
            throw TypeMismatch::becauseTypeDoesNotMatch(['object'], $schema->type);
        }

        if ($message->getBody()->getSize()) {
            $this->validatePlainBodyMultipart($addr, $message, $schema);
        } elseif ($message instanceof ServerRequestInterface) {
            $this->validateServerRequestMultipart($addr, $message, $schema);
        }
    }

    private function validatePlainBodyMultipart(
        OperationAddress $addr,
        MessageInterface $message,
        Schema $schema
    ): void {
        $validator = new SchemaValidator($this->detectValidationStrategy($message));
        try {
            // 1. Parse message body
            $body = $this->getBody($addr, $message);
            $validator->validate($body, $schema);
        } catch (SchemaMismatch $e) {
            throw InvalidBody::becauseBodyDoesNotMatchSchema($this->contentType, $addr, $e);
        }

        // 2. Validate specified part encodings and headers
        // @see https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.2.md#encoding-object
        // The encoding object SHALL only apply to requestBody objects when the media type is multipart or application/x-www-form-urlencoded.
        // An encoding attribute is introduced to give you control over the serialization of parts of multipart request bodies.
        // This attribute is only applicable to "multipart" and "application/x-www-form-urlencoded" request bodies.
        $encodings = $this->mediaTypeSpec->encoding;

        foreach ($encodings as $partName => $encoding) {
            $parts = $document->getPartsByName($partName); // multiple parts share a name?

            foreach ($parts as $part) {
                // 2.1 parts encoding
                $partContentType   = $part->getHeader(self::HEADER_CONTENT_TYPE);
                $validContentTypes = $this->detectEncodingContentTypes($encoding, $part, $schema->properties[$partName]);
                $match             = false;

                foreach ($validContentTypes as $contentType) {
                    $match = $match || $this->contentTypeMatches($contentType, $partContentType);
                }

                if (! $match) {
                    throw InvalidBody::becauseBodyDoesNotMatchSchemaMultipart(
                        $partName,
                        $partContentType,
                        $addr
                    );
                }

                // 2.2. parts headers
                $validator = new SchemaValidator($this->detectValidationStrategy($message));
                foreach ($encoding->headers as $headerName => $headerSpec) {
                    /** @var Header $headerSpec */
                    $headerSchema = $headerSpec->schema;
                    $headerValue  = $part->getHeader($headerName);

                    if ($headerValue === null) {
                        throw InvalidHeaders::becauseOfMissingRequiredHeaderMupripart($partName, $headerName, $addr);
                    }

                    $header = SerializedParameter::fromSpec($headerSpec);
                    try {
                        $validator->validate($header->deserialize($headerValue), $headerSchema);
                    } catch (SchemaMismatch $e) {
                        throw InvalidHeaders::becauseValueDoesNotMatchSchemaMultipart($partName, $headerName, $headerValue, $addr, $e);
                    }
                }
            }
        }
    }

    /**
     * Prepare a Multipart message body (a set of normal parts) for validation against a schema
     *
     * @see https://www.w3.org/Protocols/rfc1341/7_2_Multipart.html
     * @see https://swagger.io/docs/specification/describing-request-body/multipart-requests/
     *
     * @return mixed[]
     *
     * @throws InvalidBody
     * @throws TypeMismatch
     */
    private function parseMultipartData(OperationAddress $addr, StreamedPart $document): array
    {
        $multipartData = []; // a buffer to fill up with message parts
        foreach ($document->getParts() as $i => $part) {
            $partContentType = $part->getHeader('Content-Type');

            if (! empty($partContentType) && preg_match('#^application/.*json$#', $partContentType)) {
                $partBody = json_decode($part->getBody(), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw InvalidBody::becauseBodyIsNotValidJson(json_last_error_msg(), $addr);
                }
            } else {
                $partBody = $part->getBody();
            }

            // if name is not set, it should be validated with "additionalProperties" keyword
            $multipartData[$part->getName() ?? '____' . $i] = $partBody;
        }

        return $multipartData;
    }

    /**
     * @return string[]
     */
    private function detectEncodingContentTypes(Encoding $encoding, StreamedPart $part, Schema $partSchema): array
    {
        $contentType = $encoding->contentType;

        if (! $contentType) {
            // fallback strategy:
            // @see https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.2.md#encoding-object
            // @see https://swagger.io/docs/specification/describing-request-body/multipart-requests/
            //
            // Default value depends on the property type: for string with format being binary – application/octet-stream;
            // for other primitive types – text/plain; for object - application/json; for array – the default is defined based on the inner type.
            // The value can be a specific media type (e.g. application/json), a wildcard media type (e.g. image/*),
            // or a comma-separated list of the two types.
            if ($partSchema->type === 'string') {
                if (in_array($partSchema->format, ['binary', 'base64'])) {
                    $contentType = 'application/octet-stream';
                } else {
                    $contentType = 'text/plain';
                }
            } elseif (in_array($partSchema->type, ['object', 'array'])) {
                $contentType = 'application/json';
            }
        }

        return array_map('trim', explode(',', $contentType));
    }

    private function contentTypeMatches(string $expected, string $match): bool
    {
        $expectedNormalized = $this->normalizedContentTypeParts($expected);
        $matchNormalized    = $this->normalizedContentTypeParts($match);
        $expectedType       = array_shift($expectedNormalized);
        $matchType          = array_shift($matchNormalized);

        if (strpos($expectedType, '*') === false) {
            // strict comparison (ie "image/jpeg")
            if ($expectedType !== $matchType) {
                return false;
            }
        } else {
            // loose comparison (ie "image/*")
            $expectedType = str_replace('*', '.*', $expectedType);
            if (! preg_match('#' . $expectedType . '#', $matchType)) {
                return false;
            }
        }

        // Any expected parameter values must also match
        return ! array_diff_assoc($expectedNormalized, $matchNormalized);
    }

    /**
     * Per RFC-7231 Section 3.1.1.1:
     * "The type, subtype, and parameter name tokens are case-insensitive. Parameter values might or might not be case-sensitive..."
     *
     * And section 3.1.1.2: "A charset is identified by a case-insensitive token."
     *
     * The following are equivalent:
     *
     * text/html;charset=utf-8
     * text/html;charset=UTF-8
     * Text/HTML;Charset="utf-8"
     * text/html; charset="utf-8"
     *
     * @return array<int|string, string>
     */
    private function normalizedContentTypeParts(string $contentType): array
    {
        $parts  = array_map('trim', explode(';', $contentType));
        $result = [strtolower(array_shift($parts))];

        foreach ($parts as $part) {
            [$parameter, $value] = explode('=', $part, 2);
            $parameter           = strtolower($parameter);

            if ($value[0] === '"') { // quoted-string
                $value = str_replace('\\', '', substr($value, 1, -1));
            }

            if ($parameter === 'charset') {
                $value = strtolower($value);
            }

            $result[$parameter] = $value;
        }

        return $result;
    }

    /**
     * ServerRequest does not have a plain HTTP body which we can parse. Instead, it has a parsed values in
     * getParsedBody() (POST data) and getUploadedFiles (FILES data)
     */
    private function validateServerRequestMultipart(
        OperationAddress $addr,
        ServerRequestInterface $message,
        Schema $schema
    ): void {
        $body = $this->getBody($addr, $message);

        $validator = new SchemaValidator($this->detectValidationStrategy($message));
        try {
            $validator->validate($body, $schema);
        } catch (SchemaMismatch $e) {
            throw InvalidBody::becauseBodyDoesNotMatchSchema($this->contentType, $addr, $e);
        }

        // 2. Validate specified part encodings and headers
        // @see https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.2.md#encoding-object
        // The encoding object SHALL only apply to requestBody objects when the media type is multipart or application/x-www-form-urlencoded.
        // An encoding attribute is introduced to give you control over the serialization of parts of multipart request bodies.
        // This attribute is only applicable to "multipart" and "application/x-www-form-urlencoded" request bodies.
        $encodings = $this->mediaTypeSpec->encoding;

        foreach ($encodings as $partName => $encoding) {
            if (! isset($body[$partName])) {
                continue;
            }

            $part = $body[$partName];

            // 2.1 parts encoding
            // ...values are parsed already by php core...

            // 2.2. parts headers
            // ...headers are parsed already by webserver...
        }
    }

    /**
     * @param UploadedFileInterface[]|array<int, UploadedFileInterface[]> $files
     *
     * @return mixed[]
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $name => $file) {
            if ($file instanceof UploadedFileInterface) {
                $normalized[$name] = '~~~binary~~~';
            } elseif (is_array($file)) {
                $normalized[$name] = $this->normalizeFiles($file);
            } else {
                throw new InvalidArgumentException('Invalid file tree in request');
            }
        }

        return $normalized;
    }
}
