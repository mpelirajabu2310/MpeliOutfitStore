<?php
declare(strict_types=1);

/**
 * Tanzania time helpers — the single source of truth for the business date.
 *
 * The business date changes at 00:00 in Africa/Dar_es_Salaam (UTC+03:00,
 * no DST). These helpers return PHP's wall clock for that zone and are pure
 * string helpers: they never mutate stored timestamps. DATETIME/TIMESTAMP
 * values are written by MySQL under the +03:00 session time_zone set in
 * get_db(), so they always agree with these helpers.
 *
 * This file is tracked by git and shipped on every deployment, unlike
 * config/database.php (which holds credentials and is intentionally excluded
 * from the cPanel rsync). All API endpoints and services load this file so
 * the business-date helpers are present in every environment.
 */

date_default_timezone_set('Africa/Dar_es_Salaam');

/**
 * Current date-time in Tanzania (real timestamp, e.g. '2026-09-21 14:30:05').
 */
function tz_now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Current Tanzania business date (e.g. '2026-09-21'). Rolls over at 00:00.
 */
function tz_today(): string
{
    return date('Y-m-d');
}

/**
 * Current time of day in Tanzania (e.g. '14:30:05').
 */
function tz_time(): string
{
    return date('H:i:s');
}

/**
 * The day AFTER the given Tanzania business date. Used as the exclusive upper
 * bound of date ranges ([start 00:00:00, tz_day_after(end) 00:00:00)).
 * Returns the input unchanged when it is not a valid Y-m-d date.
 */
function tz_day_after(string $date): string
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if ($d === false) {
        return $date;
    }
    return $d->modify('+1 day')->format('Y-m-d');
}