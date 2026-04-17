<?php
declare(strict_types=1);

use Erikr\Chrome\Avatar;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `Erikr\Chrome\Avatar::detectMime()`.
 *
 * The shared avatar endpoint serves `auth_accounts.img_blob` directly.
 * Legacy world4you blobs pre-date migration 09 (avatar_simplify) and may
 * still be PNG/GIF/WEBP — detectMime sniffs leading magic bytes so the
 * endpoint can emit an accurate Content-Type. Unknown payloads fall back
 * to the canonical JPEG (what AvatarUpload always produces today).
 */
final class AvatarMimeTest extends TestCase
{
    public function test_jpeg_magic_bytes(): void
    {
        $blob = "\xFF\xD8\xFF\xE0" . str_repeat('x', 16);
        $this->assertSame('image/jpeg', Avatar::detectMime($blob));
    }

    public function test_png_magic_bytes(): void
    {
        $blob = "\x89PNG\r\n\x1A\n" . str_repeat('x', 16);
        $this->assertSame('image/png', Avatar::detectMime($blob));
    }

    public function test_gif87a_magic_bytes(): void
    {
        $this->assertSame('image/gif', Avatar::detectMime('GIF87a' . str_repeat('x', 16)));
    }

    public function test_gif89a_magic_bytes(): void
    {
        $this->assertSame('image/gif', Avatar::detectMime('GIF89a' . str_repeat('x', 16)));
    }

    public function test_webp_magic_bytes(): void
    {
        // RIFF + 4 bytes length + WEBP
        $blob = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . str_repeat('x', 16);
        $this->assertSame('image/webp', Avatar::detectMime($blob));
    }

    public function test_unknown_bytes_fall_back_to_canonical_jpeg(): void
    {
        $this->assertSame('image/jpeg', Avatar::detectMime(str_repeat("\x00", 20)));
    }

    public function test_empty_blob_falls_back_to_canonical_jpeg(): void
    {
        $this->assertSame('image/jpeg', Avatar::detectMime(''));
    }

    public function test_riff_without_webp_is_not_misdetected(): void
    {
        // RIFF+WAVE (common non-image RIFF): fall back to canonical, never image/webp.
        $blob = 'RIFF' . "\x00\x00\x00\x00" . 'WAVE' . str_repeat('x', 16);
        $this->assertSame('image/jpeg', Avatar::detectMime($blob));
    }
}
