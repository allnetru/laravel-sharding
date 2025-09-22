<?php

namespace Allnetru\Sharding\Support\Database;

use Illuminate\Database\QueryException;
use PDOException;

/**
 * Determines whether a query failure was caused by a duplicate key constraint.
 */
final class UniqueConstraintViolationDetector
{
    /**
     * SQLSTATE codes that indicate a unique constraint violation.
     *
     * @var array<int, string>
     */
    private const SQLSTATE_CODES = [
        '23000', // MySQL, SQLite, SQL Server
        '23505', // PostgreSQL
    ];

    /**
     * Driver specific error codes for unique constraint violations.
     *
     * @var array<int, int>
     */
    private const DRIVER_ERROR_CODES = [
        1062, // MySQL duplicate entry
        2601, // SQL Server duplicate key
        2627, // SQL Server unique constraint violation
    ];

    /**
     * Check if the given query exception resulted from a unique constraint violation.
     *
     * @param QueryException $exception
     *
     * @return bool
     */
    public static function causedBy(QueryException $exception): bool
    {
        if (self::matchesQueryException($exception)) {
            return true;
        }

        $previous = $exception->getPrevious();
        if ($previous instanceof PDOException) {
            return self::matchesErrorSignature($previous->errorInfo ?? null, $previous->getCode());
        }

        return false;
    }

    /**
     * Inspect the QueryException payload for markers of a duplicate key violation.
     *
     * @param QueryException $exception
     *
     * @return bool
     */
    private static function matchesQueryException(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? null;

        if (self::matchesErrorSignature($errorInfo, $exception->getCode())) {
            return true;
        }

        if ($errorInfo !== null && isset($errorInfo[2]) && is_string($errorInfo[2])) {
            $message = strtolower($errorInfo[2]);
            if (str_contains($message, 'duplicate') || str_contains($message, 'unique constraint')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare SQLSTATE and driver-specific codes to a known duplicate key signature.
     *
     * @param array<int, mixed>|null $errorInfo
     * @param string|int|null $code
     *
     * @return bool
     */
    private static function matchesErrorSignature(?array $errorInfo, string|int|null $code): bool
    {
        if ($code !== null && self::matchesCode($code)) {
            return true;
        }

        if (!$errorInfo) {
            return false;
        }

        $sqlState = $errorInfo[0] ?? null;
        if (is_string($sqlState) && self::matchesSqlState($sqlState)) {
            return true;
        }

        $driverCode = $errorInfo[1] ?? null;
        if (is_int($driverCode) && self::matchesDriverCode($driverCode)) {
            return true;
        }
        if (is_string($driverCode) && self::matchesCode($driverCode)) {
            return true;
        }

        return false;
    }

    /**
     * Normalise an SQLSTATE or driver error code for comparison.
     *
     * @param string|int $code
     *
     * @return bool
     */
    private static function matchesCode(string|int $code): bool
    {
        if (is_int($code)) {
            return self::matchesDriverCode($code) || self::matchesSqlState((string) $code);
        }

        $normalized = trim($code);

        if (self::matchesSqlState($normalized)) {
            return true;
        }

        if (ctype_digit($normalized) && self::matchesDriverCode((int) $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the provided code belongs to the SQLSTATE class of unique violations.
     *
     * @param string $code
     *
     * @return bool
     */
    private static function matchesSqlState(string $code): bool
    {
        return in_array(strtoupper($code), self::SQLSTATE_CODES, true);
    }

    /**
     * Determine whether the provided driver error code represents a duplicate entry violation.
     *
     * @param int $code
     *
     * @return bool
     */
    private static function matchesDriverCode(int $code): bool
    {
        return in_array($code, self::DRIVER_ERROR_CODES, true);
    }
}
