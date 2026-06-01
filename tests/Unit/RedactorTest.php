<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\Redactor;

class RedactorTest extends TestCase
{
    public function test_request_uri_redacts_sensitive_query_values(): void
    {
        $uri = '/checkout/order-received/123?key=wc_order_secret&token=abc123&page=2';

        $redacted = Redactor::redact_request_uri($uri);

        $this->assertSame('/checkout/order-received/123?key=[redacted]&token=[redacted]&page=2', $redacted);
    }

    public function test_request_uri_redacts_arrays(): void
    {
        $uri = '/shop?filter[email]=customer@example.com&filter[page]=2';

        $redacted = Redactor::redact_request_uri($uri);

        $this->assertSame('/shop?filter%5Bemail%5D=[redacted]&filter%5Bpage%5D=[redacted]', $redacted);
    }

    public function test_request_uri_redacts_unsafe_query_key_names(): void
    {
        $uri = '/account?customer@example.com=1&filter[secret@example.com]=abc123';

        $redacted = Redactor::redact_request_uri($uri);

        $this->assertSame(
            '/account?_wp_flame_redacted_key_1=[redacted]&filter%5B_wp_flame_redacted_key_1%5D=[redacted]',
            $redacted
        );
    }

    public function test_request_uri_redaction_bounds_query_parameter_count(): void
    {
        $pairs = [];
        for ($i = 0; $i < 55; $i++) {
            $pairs[] = 'token' . $i . '=secret';
        }

        $redacted = Redactor::redact_request_uri('/shop?' . implode('&', $pairs));

        $this->assertStringContainsString('token49=[redacted]', $redacted);
        $this->assertStringNotContainsString('token50=', $redacted);
        $this->assertStringContainsString('_wp_flame_query_truncated=1', $redacted);
    }

    public function test_request_uri_redaction_bounds_query_bytes(): void
    {
        $redacted = Redactor::redact_request_uri('/shop?token=' . str_repeat('a', 5000));

        $this->assertSame('/shop?token=[redacted]&_wp_flame_query_truncated=1', $redacted);
    }

    public function test_sql_normalization_replaces_literals(): void
    {
        $sql = "SELECT * FROM wp_wc_orders WHERE billing_email = 'customer@example.com' AND id IN (1, 2, 3)";

        $normalized = Redactor::normalize_sql($sql);

        $this->assertSame('SELECT * FROM wp_wc_orders WHERE billing_email = ? AND id IN (?)', $normalized);
    }

    public function test_sql_label_uses_normalized_query_by_default(): void
    {
        $sql = "SELECT * FROM wp_users WHERE user_email = 'admin@example.com'";

        $this->assertSame('SELECT * FROM wp_users WHERE user_email = ?', Redactor::sql_label($sql, false));
    }

    public function test_sql_label_keeps_full_query_only_when_enabled(): void
    {
        $sql = "SELECT * FROM wp_users WHERE user_email = 'admin@example.com'";

        $this->assertSame($sql, Redactor::sql_label($sql, true));
    }

    public function test_full_sql_label_is_bounded_even_when_enabled(): void
    {
        $sql = 'SELECT * FROM wp_posts WHERE post_content = "' . str_repeat('x', 10000) . '"';

        $label = Redactor::sql_label($sql, true);

        $this->assertSame(Redactor::MAX_SQL_LABEL_BYTES, strlen($label));
    }

    public function test_sql_normalization_bounds_very_large_queries(): void
    {
        $sql = 'SELECT * FROM wp_posts WHERE post_content = "' . str_repeat('x', 20000) . '"';

        $normalized = Redactor::normalize_sql($sql);

        $this->assertLessThanOrEqual(8192, strlen($normalized));
    }
}
