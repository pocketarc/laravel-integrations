<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Support\BinaryGuard;
use Integrations\Tests\TestCase;

class BinaryGuardTest extends TestCase
{
    public function test_sanitize_passes_null_through(): void
    {
        $this->assertNull(BinaryGuard::sanitize(null));
    }

    public function test_sanitize_passes_empty_string_through(): void
    {
        $this->assertSame('', BinaryGuard::sanitize(''));
    }

    public function test_sanitize_passes_ascii_through(): void
    {
        $this->assertSame('{"ok": true}', BinaryGuard::sanitize('{"ok": true}'));
    }

    public function test_sanitize_passes_multibyte_utf8_through(): void
    {
        $this->assertSame('héllo wörld 你好', BinaryGuard::sanitize('héllo wörld 你好'));
    }

    public function test_sanitize_replaces_png_signature_with_marker(): void
    {
        $bytes = "\x89PNG\r\n\x1A\n\0\0\0\rIHDR".random_bytes(64);

        $result = BinaryGuard::sanitize($bytes);

        $this->assertNotNull($result);
        $this->assertSame(
            sprintf('[BINARY %d bytes sha256=%s]', strlen($bytes), hash('sha256', $bytes)),
            $result,
        );
    }

    public function test_sanitize_marker_is_valid_utf8(): void
    {
        $bytes = "\xFF\xFE\xFD".random_bytes(32);

        $result = BinaryGuard::sanitize($bytes);

        $this->assertNotNull($result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function test_is_binary_returns_false_for_null_empty_and_utf8(): void
    {
        $this->assertFalse(BinaryGuard::isBinary(null));
        $this->assertFalse(BinaryGuard::isBinary(''));
        $this->assertFalse(BinaryGuard::isBinary('plain ascii'));
        $this->assertFalse(BinaryGuard::isBinary('héllo wörld 你好'));
    }

    public function test_is_binary_returns_true_for_raw_bytes(): void
    {
        $this->assertTrue(BinaryGuard::isBinary("\x89PNG\r\n\x1A\n"));
        $this->assertTrue(BinaryGuard::isBinary("\xFF\xFE\xFD"));
    }
}
