<?php

namespace typedef\contentreview\helpers;

use Craft;
use craft\helpers\DateTimeHelper;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Calendar
{
    public const DATE_FORMAT = 'Y-m-d';

    public static function todayYmd(): string
    {
        return self::ymdFromDateTime(new DateTimeImmutable('now', new DateTimeZone(Craft::$app->getTimeZone())));
    }

    public static function addDays(string $ymd, int $days): string
    {
        $parsedDate = self::parseYmd($ymd);
        $parsedDate = $parsedDate->modify(sprintf('%+d days', $days));

        return self::ymdFromDateTime($parsedDate);
    }

    public static function subtractDays(string $ymd, int $days): string
    {
        return self::addDays($ymd, -$days);
    }

    /**
     * Returns the number of days between 2 dates
     */
    public static function daysBetween(string $fromYmd, string $toYmd): int
    {
        $fromDate = self::parseYmd($fromYmd);
        $toDate = self::parseYmd($toYmd);

        return (int) $fromDate->diff($toDate)->format('%r%a');
    }

    public static function dayOfWeek(string $ymd): int
    {
        return (int) self::parseYmd($ymd)->format('N');
    }

    public static function isPast(string $ymd): bool
    {
        return $ymd < self::todayYmd();
    }

    public static function isToday(string $ymd): bool
    {
        return $ymd === self::todayYmd();
    }

    public static function isFuture(string $ymd): bool
    {
        return $ymd > self::todayYmd();
    }

    public static function daysUntil(string $date): int
    {
        return self::daysBetween(self::todayYmd(), $date);
    }

    public static function daysSince(string $date): int
    {
        return self::daysBetween($date, self::todayYmd());
    }

    public static function isValidYmd(string $ymd): bool
    {
        try {
            self::parseYmd($ymd);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Controlled helper for parsing dates passed from Craft's UI and converting to our standardised 'Y-m-d' format
     *
     * @throws \Exception
     */
    public static function parseUiDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $dateValue = $value['date'] ?? null;

            if ($dateValue === null || trim((string) $dateValue) === '') {
                return null;
            }
        }

        $dateTime = DateTimeHelper::toDateTime($value);

        if (!$dateTime instanceof DateTimeInterface) {
            throw new InvalidArgumentException('Invalid date');
        }

        $dateStr = self::ymdFromDateTime($dateTime);

        if (!self::isValidYmd($dateStr)) {
            throw new InvalidArgumentException('Invalid date');
        }

        return $dateStr;
    }

    /**
     * Return a date string in "Y-m-d" format for a given DateTime
     *
     * @throws RuntimeException
     */
    public static function ymdFromDateTime(DateTimeInterface $datetime): string
    {
        return $datetime->format(self::DATE_FORMAT);
    }

    /**
     * Normalises a datestring to "Y-m-d" format
     *
     * @throws \Exception
     */
    public static function ymdFromDateTimeString(string $date): string
    {
        return self::ymdFromDateTime(new DateTimeImmutable($date));
    }

    private static function parseYmd(string $ymd): DateTimeImmutable
    {
        $parsedDate = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $ymd);

        if (!$parsedDate instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s". Expected Y-m-d.', $ymd));
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s". Expected Y-m-d.', $ymd));
        }

        if (self::ymdFromDateTime($parsedDate) !== $ymd) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s". Expected Y-m-d.', $ymd));
        }

        return $parsedDate;
    }
}
