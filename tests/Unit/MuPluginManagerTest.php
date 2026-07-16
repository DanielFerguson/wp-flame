<?php

declare(strict_types=1);

namespace WPFlame\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPFlame\MuPluginManager;

class MuPluginManagerTest extends TestCase
{
    private string $directory;
    private string $source;
    private string $destination;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wp-flame-mu-' . bin2hex( random_bytes( 8 ) );
        mkdir( $this->directory, 0700, true );
        $this->source = $this->directory . '/source.php';
        $this->destination = $this->directory . '/wp-flame-early-hooks.php';
        $this->write_owned( $this->source, 'version-one' );
    }

    protected function tearDown(): void
    {
        foreach ( glob( $this->directory . '/*' ) ?: [] as $file ) {
            unlink( $file );
        }
        rmdir( $this->directory );
    }

    public function test_install_refuses_to_overwrite_unowned_collision(): void
    {
        file_put_contents( $this->destination, "<?php\n// another plugin\n" );

        $result = MuPluginManager::install( $this->source, $this->destination );

        $this->assertSame( MuPluginManager::UNOWNED_COLLISION, $result['status'] );
        $this->assertSame( "<?php\n// another plugin\n", file_get_contents( $this->destination ) );
    }

    public function test_install_atomically_adopts_only_an_exact_allowed_legacy_file(): void
    {
        file_put_contents( $this->destination, "<?php\n// known legacy WP Flame loader\n" );
        $legacy_hash = MuPluginManager::file_hash( $this->destination );

        $result = MuPluginManager::install( $this->source, $this->destination, '', [ $legacy_hash ] );

        $this->assertSame( MuPluginManager::INSTALLED, $result['status'] );
        $this->assertSame( MuPluginManager::file_hash( $this->source ), $result['hash'] );
        $this->assertSame( file_get_contents( $this->source ), file_get_contents( $this->destination ) );
    }

    public function test_install_does_not_adopt_legacy_file_when_allowlisted_hash_differs(): void
    {
        $legacy = "<?php\n// unknown legacy-shaped collision\n";
        file_put_contents( $this->destination, $legacy );

        $result = MuPluginManager::install( $this->source, $this->destination, '', [ hash( 'sha256', 'different' ) ] );

        $this->assertSame( MuPluginManager::UNOWNED_COLLISION, $result['status'] );
        $this->assertSame( $legacy, file_get_contents( $this->destination ) );
    }

    public function test_install_is_verified_and_leaves_no_temporary_file(): void
    {
        $result = MuPluginManager::install( $this->source, $this->destination );

        $this->assertSame( MuPluginManager::INSTALLED, $result['status'] );
        $this->assertSame( MuPluginManager::file_hash( $this->source ), $result['hash'] );
        $this->assertSame( file_get_contents( $this->source ), file_get_contents( $this->destination ) );
        $this->assertSame( [ $this->source, $this->destination ], glob( $this->directory . '/*' ) );
    }

    public function test_verified_owned_file_can_upgrade_atomically(): void
    {
        $first = MuPluginManager::install( $this->source, $this->destination );
        $this->write_owned( $this->source, 'version-two' );

        $second = MuPluginManager::install( $this->source, $this->destination, $first['hash'] );

        $this->assertSame( MuPluginManager::INSTALLED, $second['status'] );
        $this->assertStringContainsString( 'version-two', (string) file_get_contents( $this->destination ) );
    }

    public function test_changed_owned_file_is_not_overwritten_or_removed(): void
    {
        $installed = MuPluginManager::install( $this->source, $this->destination );
        file_put_contents( $this->destination, "\n// host modification\n", FILE_APPEND );
        $changed = file_get_contents( $this->destination );

        $upgrade = MuPluginManager::install( $this->source, $this->destination, $installed['hash'] );
        $remove = MuPluginManager::remove( $this->destination, $installed['hash'] );

        $this->assertSame( MuPluginManager::MODIFIED, $upgrade['status'] );
        $this->assertSame( MuPluginManager::MODIFIED, $remove['status'] );
        $this->assertSame( $changed, file_get_contents( $this->destination ) );
    }

    public function test_remove_deletes_only_the_exact_verified_owned_file(): void
    {
        $installed = MuPluginManager::install( $this->source, $this->destination );

        $removed = MuPluginManager::remove( $this->destination, $installed['hash'] );

        $this->assertSame( MuPluginManager::REMOVED, $removed['status'] );
        $this->assertFileDoesNotExist( $this->destination );
    }

    private function write_owned( string $path, string $body ): void
    {
        file_put_contents(
            $path,
            "<?php\n/** " . MuPluginManager::OWNER_MARKER . " */\n// {$body}\n"
        );
    }
}
