<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\CaptureSession;

class CaptureSessionTest extends TestCase
{
    public function test_baseline_and_after_sessions_preserve_membership_contract(): void
    {
        foreach (['baseline', 'after'] as $phase) {
            $session = new CaptureSession([
                'id'                   => 'session-' . $phase,
                'route_key'            => 'POST /checkout',
                'request_type'         => 'frontend',
                'capture_policy'       => 'manual_verification',
                'instrumentation_mode' => 'standard',
                'requested_count'      => 10,
                'captured_count'       => 3,
                'expires_at'           => '2026-07-17 00:00:00',
                'phase'                => $phase,
                'status'               => 'active',
            ]);

            $this->assertSame($phase, $session->phase);
            $this->assertSame('POST /checkout', $session->route_key);
            $this->assertTrue($session->accepts_trace(strtotime('2026-07-16 00:00:00 UTC')));
        }
    }

    public function test_expired_full_and_cancelled_sessions_reject_membership(): void
    {
        $expired = new CaptureSession(['expires_at' => '2026-07-15 00:00:00']);
        $full = new CaptureSession(['requested_count' => 2, 'captured_count' => 2]);
        $cancelled = new CaptureSession(['status' => 'cancelled']);

        $this->assertFalse($expired->accepts_trace(strtotime('2026-07-16 00:00:00 UTC')));
        $this->assertFalse($full->accepts_trace(strtotime('2026-07-16 00:00:00 UTC')));
        $this->assertFalse($cancelled->accepts_trace(strtotime('2026-07-16 00:00:00 UTC')));
    }

    public function test_progress_and_remaining_count_are_bounded(): void
    {
        $session = new CaptureSession(['requested_count' => 10, 'captured_count' => 4]);

        $this->assertSame(6, $session->remaining_count());
        $this->assertSame(40, $session->progress_percent());
    }

    public function test_bounds_guided_browser_request_population(): void
    {
        foreach ( CaptureSession::BROWSER_REQUEST_TYPES as $request_type ) {
            $this->assertSame( $request_type, CaptureSession::browser_request_type( $request_type ) );
        }

        $this->assertSame( 'frontend', CaptureSession::browser_request_type( 'cron' ) );
        $this->assertSame( 'frontend', CaptureSession::browser_request_type( [ 'frontend' ] ) );
    }
}
