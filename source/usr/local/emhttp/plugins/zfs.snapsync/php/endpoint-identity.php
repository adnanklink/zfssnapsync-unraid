<?php
/** Captured storage identity, separate from a connection's host name or alias. */
final class ZfsasEndpointIdentity
{
    public const VERSION = 1;

    public static function validate(string $key): string
    {
        if ($key !== 'local' && !preg_match('/^ssh:[a-f0-9]{64}$/D', $key)) {
            throw new InvalidArgumentException('A verified storage endpoint identity is required.');
        }
        return $key;
    }

    /** Called only after strict host-key verification and receiver pool inspection. */
    public static function receiver(string $hostKey, string $poolGuid, array $localPoolGuids = []): string
    {
        if (!preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $hostKey)
            || !preg_match('/^[0-9]{1,20}$/D', $poolGuid)) {
            throw new InvalidArgumentException('Incomplete verified receiver identity.');
        }
        // SSH back into a locally imported pool must share the local gates.
        if (in_array($poolGuid, $localPoolGuids, true)) { return 'local'; }
        return 'ssh:' . hash('sha256', $hostKey . "\0" . $poolGuid);
    }

    public static function known(?string $key): bool
    {
        return $key === 'local' || (is_string($key) && preg_match('/^ssh:[a-f0-9]{64}$/D', $key) === 1);
    }

    /** Legacy/unknown identities must never weaken an existing exclusion. */
    public static function mayOverlap(?string $left, ?string $right): bool
    {
        return !self::known($left) || !self::known($right) || $left === $right;
    }

    public static function deletionEndpoint(array $parameters): ?string
    {
        if (isset($parameters['endpoint'])) { return self::validate($parameters['endpoint']); }
        if (!empty($parameters['nativeSchedule'])
            || str_starts_with($parameters['phase'] ?? '', 'source_retention_')
            || str_starts_with($parameters['deleteJob']['JOB_ID'] ?? '', 'sm-')) { return 'local'; }
        return null;
    }

    /** Hash inputs for endpoint-qualified locks/reservations; never a ZFS path. */
    public static function resource(string $endpoint, string $dataset): string
    {
        self::validate($endpoint);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:+-]*(?:\/[A-Za-z0-9_.:+-]+)*$/D', $dataset)) {
            throw new InvalidArgumentException('Invalid resource dataset.');
        }
        // Retain existing local lock names for migration and legacy compatibility.
        return $endpoint === 'local' ? $dataset : $endpoint . "\0" . $dataset;
    }
}
