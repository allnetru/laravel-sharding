<?php

namespace Allnetru\Sharding\Tests\Unit\Support\Database;

use Allnetru\Sharding\Support\Database\UniqueConstraintViolationDetector;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Allnetru\Sharding\Support\Database\UniqueConstraintViolationDetector
 */
class UniqueConstraintViolationDetectorTest extends TestCase
{
    /**
     * @param array<int, mixed>|null $errorInfo
     * @param string|int|null $code
     * @param bool $expected
     *
     * @dataProvider detectionDataProvider
     */
    public function testDetectorRecognisesUniqueConstraintViolations(?array $errorInfo, string|int|null $code, bool $expected): void
    {
        $exception = $this->makeException($errorInfo, $code);

        $this->assertSame($expected, UniqueConstraintViolationDetector::causedBy($exception));
    }

    /**
     * @return array<string, array{array<int, mixed>|null, string|int|null, bool}>
     */
    public static function detectionDataProvider(): array
    {
        return [
            'mysql-sqlstate' => [
                ['23000', 1062, 'Duplicate entry'],
                23000,
                true,
            ],
            'postgres-sqlstate' => [
                ['23505', '23505', 'duplicate key value violates unique constraint'],
                0,
                true,
            ],
            'sqlsrv-driver-code' => [
                ['23000', 2627, 'Violation of UNIQUE KEY constraint'],
                'HY000',
                true,
            ],
            'message-fallback' => [
                ['HY000', 500, 'UNIQUE constraint failed: main.users_email_unique'],
                'HY000',
                true,
            ],
            'postgres-foreign-key' => [
                ['23503', '23503', 'insert or update on table violates foreign key constraint'],
                0,
                false,
            ],
            'missing-error-info' => [
                null,
                0,
                false,
            ],
        ];
    }

    /**
     * @param array<int, mixed>|null $errorInfo
     * @param string|int|null $code
     */
    private function makeException(?array $errorInfo, string|int|null $code): QueryException
    {
        $pdoCode = 0;
        if (is_int($code)) {
            $pdoCode = $code;
        } elseif (is_string($code) && ctype_digit($code)) {
            $pdoCode = (int) $code;
        }

        $previous = new PDOException('error', $pdoCode);
        if ($errorInfo !== null) {
            $previous->errorInfo = $errorInfo;
        }

        $exception = new QueryException('sqlite', 'insert', [], $previous);

        if (is_string($code) && !ctype_digit($code)) {
            $this->setQueryExceptionCode($exception, $code);
        }

        return $exception;
    }

    private function setQueryExceptionCode(QueryException $exception, string $code): void
    {
        $reflection = new \ReflectionProperty(QueryException::class, 'code');
        $reflection->setAccessible(true);
        $reflection->setValue($exception, $code);
    }
}
