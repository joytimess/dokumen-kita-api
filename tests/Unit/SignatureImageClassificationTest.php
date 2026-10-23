<?php

namespace Tests\Unit;

use App\Contracts\SignatureImageClassifierInterface;
use App\Enums\SignatureImageClassification;
use App\Exceptions\ApiException;
use App\Services\SignatureImageClassificationService;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class SignatureImageClassificationTest extends TestCase
{
    public function test_only_positive_classification_passes(): void
    {
        $classifier = $this->createMock(SignatureImageClassifierInterface::class);
        $classifier->expects($this->once())->method('classify')->with('normalized-png')->willReturn(SignatureImageClassification::Signature);
        (new SignatureImageClassificationService($classifier))->assertSignature('normalized-png');
    }

    public function test_exception_traces_redact_image_arguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $classifier = $this->createMock(SignatureImageClassifierInterface::class);
        $classifier->method('classify')->willReturn(SignatureImageClassification::NonSignature);
        try {
            (new SignatureImageClassificationService($classifier))->assertSignature('private-image-bytes');
            $this->fail('Unverified image must be rejected.');
        } catch (ApiException $e) {
            $this->assertStringNotContainsString('private-image-bytes', $e->getTraceAsString());
            $this->assertInstanceOf(\SensitiveParameterValue::class, $e->getTrace()[0]['args'][0]);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function failures(): array
    {
        return [
            'non signature' => [SignatureImageClassification::NonSignature, 422, 'SIGNATURE_IMAGE_NOT_SIGNATURE'],
            'uncertain' => [SignatureImageClassification::Uncertain, 422, 'SIGNATURE_IMAGE_UNCERTAIN'],
            'timeout' => [new ConnectionException('timeout: secret provider payload'), 503, 'SIGNATURE_CLASSIFIER_UNAVAILABLE'],
            'unavailable' => [new RuntimeException('secret key and image'), 503, 'SIGNATURE_CLASSIFIER_UNAVAILABLE'],
            'invalid provider response' => [new \TypeError('secret malformed response'), 503, 'SIGNATURE_CLASSIFIER_UNAVAILABLE'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failures_are_sanitized_and_cannot_pass(SignatureImageClassification|Throwable $result, int $status, string $code): void
    {
        $classifier = $this->createMock(SignatureImageClassifierInterface::class);
        $method = $classifier->expects($this->once())->method('classify');
        $result instanceof Throwable ? $method->willThrowException($result) : $method->willReturn($result);
        try {
            (new SignatureImageClassificationService($classifier))->assertSignature('normalized-png');
            $this->fail('Unverified image must be rejected.');
        } catch (ApiException $e) {
            $this->assertSame($status, $e->status);
            $this->assertSame($code, $e->errorCode);
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertNull($e->getPrevious());
            if ($status === 422) {
                $this->assertArrayHasKey('file', $e->errors);
            }
        }
    }
}
