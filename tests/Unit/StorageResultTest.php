<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\StorageResult;

class StorageResultTest extends TestCase
{
    public function test_stored_result_exposes_bytes_and_truncation(): void
    {
        $result = StorageResult::stored(1234, true);

        $this->assertTrue($result->success);
        $this->assertSame(StorageResult::STORED, $result->status);
        $this->assertSame(1234, $result->stored_bytes);
        $this->assertTrue($result->trace_truncated);
    }

    public function test_failure_result_is_bounded_to_known_status(): void
    {
        $result = StorageResult::failed('unexpected');

        $this->assertFalse($result->success);
        $this->assertSame(StorageResult::INSERT_FAILED, $result->status);
        $this->assertSame(0, $result->stored_bytes);
        $this->assertFalse($result->trace_truncated);
    }

    public function test_quota_failure_is_an_explicit_supported_status(): void
    {
        $result = StorageResult::failed(StorageResult::QUOTA_REACHED);

        $this->assertFalse($result->success);
        $this->assertSame(StorageResult::QUOTA_REACHED, $result->status);
    }
}
