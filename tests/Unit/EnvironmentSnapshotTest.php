<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\EnvironmentSnapshot;

class EnvironmentSnapshotTest extends TestCase
{
    public function test_fingerprint_is_order_independent_but_changes_with_versions(): void
    {
        $first = new EnvironmentSnapshot([
            'wp_version' => '6.8',
            'plugins'    => [ 'woocommerce' => '10.0', 'seo' => '2.0' ],
        ]);
        $reordered = new EnvironmentSnapshot([
            'plugins'    => [ 'seo' => '2.0', 'woocommerce' => '10.0' ],
            'wp_version' => '6.8',
        ]);
        $changed = new EnvironmentSnapshot([
            'wp_version' => '6.8',
            'plugins'    => [ 'woocommerce' => '10.1', 'seo' => '2.0' ],
        ]);

        $this->assertSame($first->fingerprint, $reordered->fingerprint);
        $this->assertNotSame($first->fingerprint, $changed->fingerprint);
        $this->assertSame(64, strlen($first->fingerprint));
    }

    public function test_snapshot_inventory_is_bounded(): void
    {
        $plugins = [];
        for ($i = 0; $i < 600; $i++) {
            $plugins['plugin-' . $i . str_repeat('x', 200)] = str_repeat('v', 300);
        }

        $snapshot = new EnvironmentSnapshot(['plugins' => $plugins]);

        $this->assertCount(500, $snapshot->data['plugins']);
        $key = array_key_first($snapshot->data['plugins']);
        $this->assertIsString($key);
        $this->assertLessThanOrEqual(160, strlen($key));
        $this->assertSame(200, strlen($snapshot->data['plugins'][$key]));
    }
}
