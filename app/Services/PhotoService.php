<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Stores and serves employee photographs.
 *
 * Two rules, both learned the hard way on the ordering system:
 *
 *  1. Photographs live on the `local` disk, which roots at `storage/app/private`,
 *     OUTSIDE the web root. A photo of a staff member must not be fetchable by
 *     anyone who guesses a URL.
 *
 *  2. They are served through SIGNED TEMPORARY URLs, not a public route. An
 *     `<img src="...">` cannot send an Authorization header, so a photo behind
 *     auth would simply fail to render — the ordering system hit exactly this with
 *     QR images. A signed URL carries its own authorisation and works in an `img`
 *     tag while still expiring.
 */
class PhotoService
{
    /** Photos are private; this disk roots outside the web root. */
    private const DISK = 'local';

    /** Long enough to load a page, short enough that a copied URL goes stale. */
    public const URL_TTL_MINUTES = 30;

    /**
     * Store an uploaded image, returning the path to persist.
     *
     * Random filenames: an employee's name in a path is itself personal data, and a
     * predictable name would invite enumeration even with signed URLs.
     */
    public function store(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, self::DISK);
    }

    /**
     * Store raw image bytes, for a photo captured in the browser.
     *
     * The punch flow sends a canvas blob rather than a file input, so it arrives as
     * bytes rather than an UploadedFile.
     */
    public function storeBytes(string $bytes, string $folder, string $extension = 'jpg'): string
    {
        $path = $folder.'/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk(self::DISK)->put($path, $bytes);

        return $path;
    }

    public function delete(?string $path): void
    {
        if (filled($path) && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return filled($path) && Storage::disk(self::DISK)->exists($path);
    }

    /**
     * A temporary signed URL for a stored photo, or null when there is none.
     *
     * Returns null rather than an empty string so the client renders a placeholder
     * instead of a broken image.
     */
    public function temporaryUrl(?string $path, ?int $ttlMinutes = null): ?string
    {
        if (! $this->exists($path)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'photos.show',
            now()->addMinutes($ttlMinutes ?? self::URL_TTL_MINUTES),
            ['path' => $path],
        );
    }

    /**
     * Resolve a signed path to its bytes for the serving controller.
     *
     * Guarded against traversal: the stored path is database-controlled, but
     * treating it as trusted just because it came from the database is how a single
     * bad row becomes an arbitrary file read.
     */
    public function read(string $path): ?string
    {
        $normalised = str_replace('\\', '/', $path);

        if (str_contains($normalised, '..') || str_starts_with($normalised, '/')) {
            return null;
        }

        if (! Storage::disk(self::DISK)->exists($normalised)) {
            return null;
        }

        return Storage::disk(self::DISK)->get($normalised);
    }

    /**
     * Purge photos older than the retention window.
     *
     * PDPA minimisation, run by cron rather than done by hand — a manual retention
     * process is one that stops happening.
     */
    public function purgeFolderOlderThan(string $folder, int $days): int
    {
        $cutoff = now()->subDays($days)->getTimestamp();
        $purged = 0;

        foreach (Storage::disk(self::DISK)->files($folder) as $file) {
            if (Storage::disk(self::DISK)->lastModified($file) < $cutoff) {
                Storage::disk(self::DISK)->delete($file);
                $purged++;
            }
        }

        return $purged;
    }
}
