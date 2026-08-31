<?php

declare(strict_types=1);

namespace App\Service;

// The only untrusted input that lands on disk (docs-initial-build/PLAN.md § Risk notes). The
// client's Content-Type header and the filename extension are never trusted:
// finfo reads the real bytes, and every upload is re-encoded through GD before
// it touches the filesystem, which also strips EXIF and neutralises polyglot
// payloads like `evil.php.jpg`.
final class AvatarService
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const SIZE = 256;

    public function __construct(private readonly string $avatarDir)
    {
    }

    /**
     * Validates, re-encodes and stores the upload, deletes the player's
     * previous avatar file (if any), and returns the new path relative to the
     * document root, e.g. 'avatars/7-a1b2c3d4.webp'.
     *
     * @param array{tmp_name?:string, size?:int, error?:int, name?:string} $file
     */
    public function replace(int $playerId, array $file, ?string $currentAvatarPath): string
    {
        $this->validateUpload($file);
        $mime = $this->detectMime((string) $file['tmp_name']);
        $source = $this->loadImage($mime, (string) $file['tmp_name']);
        $resized = $this->cropAndResize($source);
        // No imagedestroy(): GdImage instances are refcounted and freed
        // automatically since PHP 8.0; the function itself is a deprecated
        // no-op as of PHP 8.5.

        $useWebp = function_exists('imagewebp');
        $extension = $useWebp ? 'webp' : 'jpg';
        $filename = sprintf('%d-%s.%s', $playerId, bin2hex(random_bytes(4)), $extension);

        if (!is_dir($this->avatarDir) && !mkdir($this->avatarDir, 0775, true) && !is_dir($this->avatarDir)) {
            throw new AvatarException('Could not prepare avatar storage.');
        }

        $fullPath = rtrim($this->avatarDir, '/') . '/' . $filename;
        $saved = $useWebp ? imagewebp($resized, $fullPath, 85) : imagejpeg($resized, $fullPath, 85);

        if (!$saved) {
            throw new AvatarException('Could not save the re-encoded avatar.');
        }

        if ($currentAvatarPath !== null) {
            $oldFullPath = rtrim($this->avatarDir, '/') . '/' . basename($currentAvatarPath);
            if (is_file($oldFullPath)) {
                @unlink($oldFullPath);
            }
        }

        return 'avatars/' . $filename;
    }

    public function delete(?string $avatarPath): void
    {
        if ($avatarPath === null) {
            return;
        }

        $fullPath = rtrim($this->avatarDir, '/') . '/' . basename($avatarPath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    /** @param array{tmp_name?:string, size?:int, error?:int, name?:string} $file */
    private function validateUpload(array $file): void
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new AvatarException('An avatar file is required.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new AvatarException('The upload failed.');
        }

        $tmpName = $file['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new AvatarException('The upload failed.');
        }

        if (((int) ($file['size'] ?? 0)) > self::MAX_BYTES) {
            throw new AvatarException('The file is larger than the 8 MB limit.');
        }
    }

    // Never trust the client Content-Type or the filename extension - a file
    // named evil.php.jpg is rejected here because finfo reads its real bytes.
    private function detectMime(string $tmpName): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);

        if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME, true)) {
            throw new AvatarException('The file must be a JPEG, PNG, WebP or GIF image.');
        }

        return $mime;
    }

    private function loadImage(string $mime, string $tmpName): \GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpName),
            'image/png' => @imagecreatefrompng($tmpName),
            'image/webp' => @imagecreatefromwebp($tmpName),
            'image/gif' => @imagecreatefromgif($tmpName),
            default => false,
        };

        if (!$image instanceof \GdImage) {
            throw new AvatarException('The file is not a readable image.');
        }

        return $image;
    }

    // Centre-crop to square, then resize to 256x256. Re-encoding through GD
    // (rather than copying bytes) is what strips EXIF and any payload hidden
    // past the image data.
    private function cropAndResize(\GdImage $source): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $srcX = intdiv($width - $side, 2);
        $srcY = intdiv($height - $side, 2);

        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefilledrectangle($target, 0, 0, self::SIZE, self::SIZE, $white);
        imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, self::SIZE, self::SIZE, $side, $side);

        return $target;
    }
}
