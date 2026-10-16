<?php

declare(strict_types=1);

namespace App\Setup\Support;

/**
 * The project files setup edits: herd.yml, .mcp.json, composer.json and .claude/settings.local.json (RL-1, g2-07).
 * They are written through StateFile, atomically, so a crash never leaves half a composer.json, and a symlink at the
 * path is replaced, never followed. An existing regular file keeps its mode; a new file, or one that was a symlink,
 * gets 0644.
 */
final class ProjectFile
{
    public static function write(string $path, string $contents): void
    {
        $mode = is_file($path) && ! is_link($path) ? fileperms($path) & 0777 : 0644;

        StateFile::write($path, $contents, $mode);
    }
}
