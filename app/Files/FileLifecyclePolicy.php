<?php

namespace App\Files;

final class FileLifecyclePolicy
{
    public const QUARANTINED = 'QUARANTINED';

    public const SCANNING = 'SCANNING';

    public const AVAILABLE = 'AVAILABLE';

    public const REJECTED = 'REJECTED';

    public const SCAN_FAILED = 'SCAN_FAILED';

    public const DELETED = 'DELETED';

    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::QUARANTINED => [self::SCANNING, self::DELETED],
        self::SCANNING => [self::AVAILABLE, self::REJECTED, self::SCAN_FAILED, self::DELETED],
        self::AVAILABLE => [self::DELETED],
        self::REJECTED => [self::DELETED],
        self::SCAN_FAILED => [self::DELETED],
        self::DELETED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isScanTerminal(string $state): bool
    {
        return in_array($state, [self::AVAILABLE, self::REJECTED, self::SCAN_FAILED, self::DELETED], true);
    }

    public static function canScan(string $state): bool
    {
        return in_array($state, [self::QUARANTINED, self::SCANNING], true);
    }

    public static function canIssueDownload(string $state): bool
    {
        return $state === self::AVAILABLE;
    }
}
