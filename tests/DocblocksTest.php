<?php
/**
 * Tests for the docblock coverage report.
 *
 * @package   SugarCommerce\DevTools
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\DevTools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\DevTools\Docblocks;

/**
 * Class DocblocksTest
 */
final class DocblocksTest extends TestCase {

	/* ─── What counts as a declaration ─────────────────────────────── */

	/**
	 * Every kind of declaration, with the name it is reported under.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function declarations(): array {
		return array(
			'class'     => array( 'class Thing {}', 'Thing' ),
			'final'     => array( 'final readonly class Thing {}', 'Thing' ),
			'interface' => array( 'interface Thing {}', 'Thing' ),
			'trait'     => array( 'trait Thing {}', 'Thing' ),
			'enum'      => array( 'enum Thing: string {}', 'Thing' ),
			'function'  => array( 'function thing(): void {}', 'thing' ),
		);
	}

	#[DataProvider( 'declarations' )]
	public function test_an_undocumented_declaration_is_reported( string $code, string $name ): void {
		$this->assertSame( array( array( 'line' => 2, 'name' => $name ) ), Docblocks::in( "<?php\n" . $code ) );
	}

	#[DataProvider( 'declarations' )]
	public function test_a_documented_declaration_is_not( string $code, string $name ): void {
		$this->assertSame( array(), Docblocks::in( "<?php\n/** Doc. */\n" . $code ), $name );
	}

	public function test_a_method_is_reported_and_its_modifiers_are_stepped_over(): void {
		$source = "<?php\n/** C. */\nclass C {\n\t/** Doc. */\n\tpublic static function a() {}\n\n\tprivate function b() {}\n}";

		$this->assertSame( array( array( 'line' => 7, 'name' => 'b' ) ), Docblocks::in( $source ) );
	}

	public function test_a_plain_comment_is_not_a_docblock(): void {
		$this->assertCount( 1, Docblocks::in( "<?php\n// Not one.\nfunction a() {}" ) );
	}

	/* ─── What is not a declaration ────────────────────────────────── */

	public function test_class_constant_access_declares_nothing(): void {
		$this->assertSame( array(), Docblocks::in( "<?php\n\$x = Foo::class;" ) );
	}

	public function test_closures_and_arrow_functions_are_ignored(): void {
		$this->assertSame( array(), Docblocks::in( "<?php\n\$a = function () {};\n\$b = static function ( \$x ) use ( \$a ) {};\n\$c = fn () => 1;" ) );
	}

	public function test_an_anonymous_class_is_ignored(): void {
		$this->assertSame( array(), Docblocks::in( "<?php\n\$a = new class {};\n\$b = new class extends Foo {};\n\$c = new readonly class () {};" ) );
	}

	public function test_use_function_declares_nothing_and_keeps_line_numbers(): void {
		$source = "<?php\nuse function Foo\\{\n\tbar,\n\tbaz\n};\nfunction a() {}";

		$this->assertSame( array( array( 'line' => 6, 'name' => 'a' ) ), Docblocks::in( $source ) );
	}

	/* ─── Attributes ───────────────────────────────────────────────── */

	public function test_an_attribute_between_docblock_and_method_is_stepped_over(): void {
		$source = "<?php\n/** C. */\nclass C {\n\t/** Doc. */\n\t#[\\Override]\n\t#[\\NoDiscard( 'a [bracket] inside' )]\n\tpublic function a() {}\n}";

		$this->assertSame( array(), Docblocks::in( $source ) );
	}

	public function test_an_attribute_alone_is_not_a_docblock(): void {
		$this->assertCount( 1, Docblocks::in( "<?php\n#[\\Attribute]\nfinal class A {}" ) );
	}

	/* ─── Scanning and reporting ───────────────────────────────────── */

	public function test_scan_reads_php_files_recursively_and_sorts(): void {
		$dir = sys_get_temp_dir() . '/docblocks-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/deep', 0777, true );
		file_put_contents( $dir . '/b.php', "<?php\nfunction b() {}" );
		file_put_contents( $dir . '/deep/a.php', "<?php\nfunction a() {}" );
		file_put_contents( $dir . '/notes.txt', 'function c() {}' );

		$missing = Docblocks::scan( array( $dir, $dir . '/missing' ) );

		$this->assertSame( array( 'b', 'a' ), array_column( $missing, 'name' ) );
		$this->assertSame( $dir . '/b.php', $missing[0]['file'] );

		array_map( 'unlink', array( $dir . '/b.php', $dir . '/deep/a.php', $dir . '/notes.txt' ) );
		rmdir( $dir . '/deep' );
		rmdir( $dir );
	}

	public function test_scan_accepts_a_single_file(): void {
		$file = sys_get_temp_dir() . '/docblocks-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $file, "<?php\nfunction a() {}" );

		$this->assertCount( 1, Docblocks::scan( array( $file ) ) );

		unlink( $file );
	}

	public function test_the_report_matches_the_original_script(): void {
		$report = Docblocks::report( array(
			array( 'file' => 'src/A.php', 'line' => 3, 'name' => 'a' ),
			array( 'file' => 'src/B.php', 'line' => 9, 'name' => 'b' ),
		) );

		$this->assertSame( "src/A.php:3  a\nsrc/B.php:9  b\n\n2 missing\n", $report );
		$this->assertSame( "0 missing\n", Docblocks::report( array() ) );
	}
}
