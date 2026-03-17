<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Privacy;
use WPFlame\Storage;

class PrivacyTest extends TestCase
{
    private function make_privacy(): Privacy
    {
        $storage = $this->createMock(Storage::class);
        return new Privacy($storage);
    }

    public function test_exporter_returns_correct_structure(): void
    {
        $result = $this->make_privacy()->get_exporter();

        $this->assertArrayHasKey('exporter_friendly_name', $result);
        $this->assertArrayHasKey('callback', $result);
        $this->assertSame('WP Flame Performance Data', $result['exporter_friendly_name']);
        $this->assertIsCallable($result['callback']);
    }

    public function test_eraser_returns_correct_structure(): void
    {
        $result = $this->make_privacy()->get_eraser();

        $this->assertArrayHasKey('eraser_friendly_name', $result);
        $this->assertArrayHasKey('callback', $result);
        $this->assertSame('WP Flame Performance Data', $result['eraser_friendly_name']);
        $this->assertIsCallable($result['callback']);
    }
}
