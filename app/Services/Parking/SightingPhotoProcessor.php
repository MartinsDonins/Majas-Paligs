<?php

declare(strict_types=1);

namespace App\Services\Parking;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Sighting photo hardening (PARK-003, MVP-SPEC C.5): the browser already converts to JPEG; the server still trusts
 * nothing — MIME by finfo (jpeg / png / webp only; HEIC is rejected because GD cannot decode it), ≤ 8 MB, a
 * ≤ 40 MP pre-decode check against decompression bombs, then decode → auto-orient → scale down to 1600 px →
 * re-encode as JPEG with all metadata stripped (EXIF / GPS gone).
 */
final class SightingPhotoProcessor
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const MAX_PIXELS = 40_000_000;

    public const MAX_EDGE = 1600;

    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return string re-encoded JPEG bytes
     *
     * @throws PhotoRejected format | size | pixels
     */
    public function process(string $path): string
    {
        $size = @filesize($path);
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES) {
            throw new PhotoRejected('size');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new PhotoRejected('format');
        }

        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new PhotoRejected('format');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new PhotoRejected('pixels');
        }

        try {
            $image = (new ImageManager(new Driver))->decodePath($path);
            $image->orient();
            $image->scaleDown(self::MAX_EDGE, self::MAX_EDGE);

            return (string) $image->encode(new JpegEncoder(quality: 82, strip: true));
        } catch (Throwable) {
            throw new PhotoRejected('format');
        }
    }
}
