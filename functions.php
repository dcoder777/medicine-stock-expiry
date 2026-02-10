<?php

declare(strict_types=1);

function clean(string $value): string
{
    return trim($value);
}

function is_valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

function date_diff_days(string $targetDate): int
{
    $today = new DateTime('today');
    $target = new DateTime($targetDate);
    return (int) $today->diff($target)->format('%r%a');
}

function expiry_badge_class(int $daysLeft): string
{
    if ($daysLeft < 0) {
        return 'expired';
    }

    if ($daysLeft <= 30) {
        return 'warn-30';
    }

    if ($daysLeft <= 60) {
        return 'warn-60';
    }

    if ($daysLeft <= 90) {
        return 'warn-90';
    }

    return 'safe';
}

function expiry_text(int $daysLeft): string
{
    if ($daysLeft < 0) {
        return 'Expired ' . abs($daysLeft) . ' day(s) ago';
    }

    return $daysLeft . ' day(s) left';
}
