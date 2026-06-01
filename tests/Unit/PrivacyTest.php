<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Privacy;
use WPFlame\Storage;
use WPFlame\Trace;

class PrivacyTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wp_flame_test_users_by_email']);
        parent::tearDown();
    }

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

    public function test_export_user_data_bounds_page_and_skips_malformed_rows_without_warnings(): void
    {
        $GLOBALS['wp_flame_test_users_by_email'] = [
            'person@example.com' => ['ID' => 123],
        ];

        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['list_traces', 'get_trace'])
            ->getMock();

        $storage->expects($this->once())
            ->method('list_traces')
            ->with([
                'user_id'  => 123,
                'per_page' => 50,
                'page'     => 1,
            ])
            ->willReturn([
                'not-a-row',
                ['trace_id' => ''],
                [
                    'trace_id'   => 'trace-1',
                    'url'        => ['/bad'],
                    'method'     => 'GET',
                    'total_ms'   => 12.5,
                    'created_at' => '2026-06-01 00:00:00',
                    'ip_address' => null,
                ],
            ]);
        $storage->expects($this->once())
            ->method('get_trace')
            ->with('trace-1')
            ->willReturn(null);

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $result = (new Privacy($storage))->export_user_data('person@example.com', -99);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('trace-trace-1', $result['data'][0]['item_id']);
        $this->assertSame('', $result['data'][0]['data'][0]['value']);
        $this->assertSame('12.5', $result['data'][0]['data'][2]['value']);
    }

    public function test_export_user_data_includes_user_agent_when_trace_meta_contains_it(): void
    {
        $GLOBALS['wp_flame_test_users_by_email'] = [
            'person@example.com' => ['ID' => 123],
        ];

        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['list_traces', 'get_trace'])
            ->getMock();

        $storage->method('list_traces')
            ->willReturn([
                [
                    'trace_id'   => 'trace-1',
                    'url'        => '/account',
                    'method'     => 'GET',
                    'total_ms'   => 25,
                    'created_at' => '2026-06-01 00:00:00',
                    'ip_address' => '203.0.113.10',
                ],
            ]);
        $storage->method('get_trace')
            ->with('trace-1')
            ->willReturn(new Trace(
                'trace-1',
                '/account',
                'GET',
                '2026-06-01T00:00:00Z',
                25.0,
                1024,
                PHP_VERSION,
                '6.7',
                [],
                ['user_agent' => 'Mozilla/5.0 Test']
            ));

        $result = (new Privacy($storage))->export_user_data('person@example.com');

        $this->assertContains(
            ['name' => 'User Agent', 'value' => 'Mozilla/5.0 Test'],
            $result['data'][0]['data']
        );
    }

    public function test_export_user_data_bounds_exported_string_values(): void
    {
        $GLOBALS['wp_flame_test_users_by_email'] = [
            'person@example.com' => ['ID' => 123],
        ];

        $long_trace_id = str_repeat('t', 300);
        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['list_traces', 'get_trace'])
            ->getMock();

        $storage->method('list_traces')
            ->willReturn([
                [
                    'trace_id'   => $long_trace_id,
                    'url'        => '/' . str_repeat('u', 3000),
                    'method'     => str_repeat('M', 100),
                    'total_ms'   => str_repeat('9', 100),
                    'created_at' => str_repeat('d', 100),
                    'ip_address' => str_repeat('1', 100),
                ],
            ]);
        $storage->expects($this->once())
            ->method('get_trace')
            ->with(str_repeat('t', 128))
            ->willReturn(new Trace(
                str_repeat('t', 128),
                '/account',
                'GET',
                '2026-06-01T00:00:00Z',
                25.0,
                1024,
                PHP_VERSION,
                '6.7',
                [],
                ['user_agent' => str_repeat('a', 1000)]
            ));

        $result = (new Privacy($storage))->export_user_data('person@example.com');
        $data = $result['data'][0]['data'];

        $this->assertSame('trace-' . str_repeat('t', 128), $result['data'][0]['item_id']);
        $this->assertSame(2048, strlen($data[0]['value']));
        $this->assertSame(20, strlen($data[1]['value']));
        $this->assertSame(64, strlen($data[2]['value']));
        $this->assertSame(64, strlen($data[3]['value']));
        $this->assertSame(45, strlen($data[4]['value']));
        $this->assertSame(500, strlen($data[5]['value']));
    }

    public function test_export_user_data_ignores_malformed_user_id_without_querying_storage(): void
    {
        $GLOBALS['wp_flame_test_users_by_email'] = [
            'person@example.com' => ['ID' => ['bad']],
        ];

        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['list_traces'])
            ->getMock();
        $storage->expects($this->never())->method('list_traces');

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING || $errno === E_NOTICE) {
                $warnings[] = $errstr;
            }

            return true;
        });

        try {
            $result = (new Privacy($storage))->export_user_data('person@example.com');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame(['data' => [], 'done' => true], $result);
    }

    public function test_erase_user_data_uses_integer_user_id(): void
    {
        $GLOBALS['wp_flame_test_users_by_email'] = [
            'person@example.com' => ['ID' => '123'],
        ];

        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['delete_traces_by_user'])
            ->getMock();

        $storage->expects($this->once())
            ->method('delete_traces_by_user')
            ->with(123)
            ->willReturn(4);

        $result = (new Privacy($storage))->erase_user_data('person@example.com');

        $this->assertSame(4, $result['items_removed']);
        $this->assertTrue($result['done']);
    }

    public function test_erase_user_data_ignores_malformed_user_id_and_bounds_delete_count(): void
    {
        $GLOBALS['wp_flame_test_users_by_email'] = [
            'person@example.com' => ['ID' => ['bad']],
        ];

        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['delete_traces_by_user'])
            ->getMock();
        $storage->expects($this->never())->method('delete_traces_by_user');

        $result = (new Privacy($storage))->erase_user_data('person@example.com');

        $this->assertSame(0, $result['items_removed']);
        $this->assertTrue($result['done']);

        $GLOBALS['wp_flame_test_users_by_email'] = [
            'person@example.com' => ['ID' => '123'],
        ];
        $storage = $this->getMockBuilder(Storage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['delete_traces_by_user'])
            ->getMock();
        $storage->method('delete_traces_by_user')->willReturn(-5);

        $result = (new Privacy($storage))->erase_user_data('person@example.com');

        $this->assertSame(0, $result['items_removed']);
    }
}
