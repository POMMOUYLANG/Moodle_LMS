<?php

namespace local_rtcsync\local;

defined('MOODLE_INTERNAL') || die();

/** Stream and verify a supplied source snapshot artifact without exposing its path or contents. */
final class source_snapshot_artifact
{
    /**
     * Compare a regular file's bytes with a declared SHA-256 digest.
     *
     * @return array{checked:bool,verified:bool,sha256:string,reason:string}
     */
    public static function verify(string $path, string $expectedhash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $expectedhash)) {
            return self::result(false, '', 'invalid_declared_digest');
        }
        if (trim($path) === '') {
            return self::result(false, '', 'missing_artifact');
        }

        clearstatcache(true, $path);
        $pathbefore = @lstat($path);
        if (!is_array($pathbefore)) {
            return self::result(false, '', 'missing_or_unreadable_artifact');
        }
        if (is_link($path) || !self::is_regular_file($pathbefore)) {
            return self::result(false, '', 'artifact_is_not_a_regular_file');
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return self::result(false, '', 'artifact_is_unreadable');
        }

        try {
            $filebefore = fstat($stream);
            if (!is_array($filebefore) || !self::is_regular_file($filebefore)
                    || !self::same_file_state($pathbefore, $filebefore)) {
                return self::result(false, '', 'artifact_changed_during_open');
            }

            $hashcontext = hash_init('sha256');
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    return self::result(false, '', 'artifact_read_failed');
                }
                if ($chunk === '') {
                    if (!feof($stream)) {
                        return self::result(false, '', 'artifact_read_failed');
                    }
                    break;
                }
                hash_update($hashcontext, $chunk);
            }
            $actualhash = hash_final($hashcontext);
            $fileafter = fstat($stream);
            clearstatcache(true, $path);
            $pathafter = @lstat($path);
            if (!is_array($fileafter) || !is_array($pathafter) || is_link($path)
                    || !self::is_regular_file($fileafter) || !self::is_regular_file($pathafter)
                    || !self::same_file_state($filebefore, $fileafter)
                    || !self::same_file_state($fileafter, $pathafter)) {
                return self::result(false, $actualhash, 'artifact_changed_during_read');
            }
            if (!hash_equals(strtolower($expectedhash), $actualhash)) {
                return self::result(false, $actualhash, 'digest_mismatch');
            }
            return self::result(true, $actualhash, 'verified');
        } finally {
            fclose($stream);
        }
    }

    /** Return a stable check result that never includes the supplied path or file bytes. */
    private static function result(bool $verified, string $sha256, string $reason): array
    {
        return [
            'checked' => true,
            'verified' => $verified,
            'sha256' => $sha256,
            'reason' => $reason,
        ];
    }

    /** Reject symlinks, directories, devices, sockets, and pipes. */
    private static function is_regular_file(array $stat): bool
    {
        return isset($stat['mode']) && (($stat['mode'] & 0170000) === 0100000);
    }

    /** Compare the immutable file identity and metadata available on this platform. */
    private static function same_file_state(array $first, array $second): bool
    {
        foreach (['size', 'mtime', 'ctime'] as $field) {
            if (!isset($first[$field], $second[$field]) || (int) $first[$field] !== (int) $second[$field]) {
                return false;
            }
        }
        foreach (['dev', 'ino'] as $field) {
            if (isset($first[$field], $second[$field])
                    && (int) $first[$field] !== 0 && (int) $second[$field] !== 0
                    && (int) $first[$field] !== (int) $second[$field]) {
                return false;
            }
        }
        return self::is_regular_file($first) && self::is_regular_file($second);
    }
}
