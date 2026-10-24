<?php
/**
 * Tests for the demo media generators.
 *
 * @package   SugarCommerce\DevTools
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\DevTools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\DevTools\DemoMedia;

/**
 * Class DemoMediaTest
 *
 * Each kind is checked the way a player or a viewer would see it: by its
 * header, not its name.
 */
final class DemoMediaTest extends TestCase {

	/* ─── WAV ───────────────────────────────────────────────────────── */

	/**
	 * A RIFF/WAVE header that agrees with its own length: three seconds of
	 * 16-bit mono at 22,050 Hz.
	 */
	public function test_a_wav_is_three_seconds_of_pcm(): void {
		$wav = new DemoMedia()->for( 'Midnight Drive - Master.wav' );

		$this->assertSame( 'RIFF', substr( $wav, 0, 4 ) );
		$this->assertSame( 'WAVE', substr( $wav, 8, 4 ) );
		$this->assertSame( strlen( $wav ) - 8, self::unpack( 'V', $wav, 4 ) );
		$this->assertSame( 'fmt ', substr( $wav, 12, 4 ) );

		$format = unpack( 'vformat/vchannels/Vrate/Vbytes/valign/vbits', substr( $wav, 20, 16 ) );

		$this->assertSame( array( 'format' => 1, 'channels' => 1, 'rate' => 22_050, 'bytes' => 44_100, 'align' => 2, 'bits' => 16 ), $format );
		$this->assertSame( 'data', substr( $wav, 36, 4 ) );
		$this->assertSame( 3 * 44_100, self::unpack( 'V', $wav, 40 ) );
		$this->assertSame( 44 + ( 3 * 44_100 ), strlen( $wav ) );
		$this->assertNotSame( str_repeat( "\0", 200 ), substr( $wav, 44 + 22_050, 200 ), 'Not silence.' );
	}

	public function test_a_wav_is_as_long_as_asked(): void {
		$this->assertSame( 44 + 44_100, strlen( DemoMedia::wav( 440.0, 1.0 ) ) );
		$this->assertSame( 44 + 11_026, strlen( DemoMedia::wav( 440.0, 0.25 ) ) );
	}

	/**
	 * It fades in and out, so the first and last samples are silent and the
	 * player does not click.
	 */
	public function test_a_wav_starts_and_ends_silent(): void {
		$wav = DemoMedia::wav( 440.0, 1.0 );

		$this->assertSame( "\0\0", substr( $wav, 44, 2 ) );
		$this->assertSame( "\0\0", substr( $wav, -2 ) );
	}

	public function test_a_name_with_beat_in_it_is_pulsed(): void {
		$this->assertNotSame( DemoMedia::wav( 440.0, 1.0 ), DemoMedia::wav( 440.0, 1.0, beat: true ) );
		$this->assertNotSame( new DemoMedia()->for( 'Neon Rain - Beat.wav' ), DemoMedia::wav( self::pitch( 'Neon Rain - Beat.wav' ), 3.0 ) );
		$this->assertSame( new DemoMedia()->for( 'Neon Rain - Beat.wav' ), DemoMedia::wav( self::pitch( 'Neon Rain - Beat.wav' ), 3.0, beat: true ) );
	}

	/* ─── PDF ───────────────────────────────────────────────────────── */

	public function test_a_pdf_is_one_page(): void {
		$pdf = new DemoMedia()->for( 'Licence Agreement.pdf' );

		$this->assertStringStartsWith( '%PDF-', $pdf );
		$this->assertStringContainsString( '/Type /Page', $pdf );
		$this->assertStringContainsString( '/Count 1', $pdf );
		$this->assertStringEndsWith( '%%EOF', rtrim( $pdf ) );
	}

	public function test_a_pdf_is_titled_by_its_name_and_dated_at_the_fixed_point(): void {
		$pdf = new DemoMedia()->for( 'Guide.pdf' );

		$this->assertStringContainsString( '/Title (Guide)', $pdf );
		$this->assertStringContainsString( '/Creator (sugarcommerce/dev-tools)', $pdf );
		$this->assertStringContainsString( '/CreationDate (D:20260101000000Z)', $pdf );
	}

	public function test_a_licence_says_something_else(): void {
		$media = new DemoMedia();

		$this->assertStringContainsString( 'Credit the artist', $media->for( 'Licence.pdf' ) );
		$this->assertStringNotContainsString( 'Credit the artist', $media->for( 'Guide.pdf' ) );
	}

	public function test_a_pdf_can_say_anything(): void {
		$pdf = new DemoMedia()->pdf( 'Terms', array( 'First paragraph.', 'Second paragraph.' ) );

		$this->assertStringContainsString( '/Title (Terms)', $pdf );
		$this->assertStringContainsString( 'First paragraph.', $pdf );
		$this->assertStringContainsString( 'Second paragraph.', $pdf );
	}

	/* ─── PNG ───────────────────────────────────────────────────────── */

	public function test_a_png_has_a_signature_and_a_square_header(): void {
		$png = new DemoMedia()->for( 'Midnight Drive - Artwork.png' );

		$this->assertSame( "\x89PNG\r\n\x1a\n", substr( $png, 0, 8 ) );
		$this->assertSame( 13, self::unpack( 'N', $png, 8 ) );
		$this->assertSame( 'IHDR', substr( $png, 12, 4 ) );
		$this->assertSame( 600, self::unpack( 'N', $png, 16 ) );
		$this->assertSame( 600, self::unpack( 'N', $png, 20 ) );
		$this->assertStringEndsWith( "IEND\xAE\x42\x60\x82", $png );
	}

	public function test_a_png_opens_as_an_image(): void {
		$size = getimagesizefromstring( DemoMedia::png( 'Cover', 64 ) );

		$this->assertIsArray( $size );
		$this->assertSame( IMAGETYPE_PNG, $size[2] );
		$this->assertSame( array( 64, 64 ), array( $size[0], $size[1] ) );
	}

	public function test_a_png_has_at_least_one_pixel(): void {
		$this->expectException( \InvalidArgumentException::class );

		DemoMedia::png( 'Cover', 0 );
	}

	/* ─── ZIP ───────────────────────────────────────────────────────── */

	/**
	 * Read the way an unzipper reads it: from the end-of-central-directory
	 * record, back to the central directory, then to each member.
	 */
	public function test_a_zip_has_a_central_directory_with_stems_and_a_readme(): void {
		$media   = new DemoMedia();
		$members = self::unzip( $media->for( 'Midnight Drive - Stems.zip' ) );

		$this->assertSame(
			array(
				'Midnight Drive - Stems/Drums.wav',
				'Midnight Drive - Stems/Bass.wav',
				'Midnight Drive - Stems/Keys.wav',
				'Midnight Drive - Stems/README.txt',
			),
			array_keys( $members )
		);

		$this->assertSame( DemoMedia::wav( 110.0, 1.0, beat: true ), $members['Midnight Drive - Stems/Drums.wav']['data'] );
		$this->assertSame( 'RIFF', substr( $members['Midnight Drive - Stems/Keys.wav']['data'], 0, 4 ) );
		$this->assertSame( $media->text( 'Midnight Drive - Stems.zip' ), $members['Midnight Drive - Stems/README.txt']['data'] );

		foreach ( $members as $name => $member ) {
			$this->assertSame( crc32( $member['data'] ), $member['crc'], $name );
		}
	}

	/**
	 * Every member carries the fixed time, in DOS format: 2026-01-01 00:00.
	 */
	public function test_a_zip_is_stamped_with_the_fixed_time(): void {
		$date = ( ( 2026 - 1980 ) << 9 ) | ( 1 << 5 ) | 1;

		foreach ( self::unzip( new DemoMedia()->for( 'A.zip' ) ) as $name => $member ) {
			$this->assertSame( array( 0, $date ), array( $member['time'], $member['date'] ), $name );
		}
	}

	public function test_a_zip_of_given_members(): void {
		$members = self::unzip( new DemoMedia()->zip( array( 'a.txt' => 'A', 'b/c.txt' => 'C' ) ) );

		$this->assertSame( array( 'a.txt', 'b/c.txt' ), array_keys( $members ) );
		$this->assertSame( array( 'A', 'C' ), array_column( $members, 'data' ) );
	}

	public function test_a_zip_opens_in_php_s_own_reader(): void {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'ext-zip is not loaded.' );
		}

		$path = (string) tempnam( sys_get_temp_dir(), 'demo-zip' );
		file_put_contents( $path, new DemoMedia()->for( 'Stems.zip' ) );

		try {
			$zip = new \ZipArchive();

			$this->assertTrue( true === $zip->open( $path, \ZipArchive::RDONLY ) );
			$this->assertSame( 4, $zip->numFiles );
			$this->assertSame( 'RIFF', substr( (string) $zip->getFromName( 'Stems/Bass.wav' ), 0, 4 ) );
			$zip->close();
		} finally {
			@unlink( $path );
		}
	}

	/* ─── Text and settings ─────────────────────────────────────────── */

	public function test_anything_else_is_the_placeholder_with_its_name_in(): void {
		$text = new DemoMedia()->for( 'notes.txt' );

		$this->assertStringStartsWith( "Demo file\nnotes.txt\n", $text );
		$this->assertSame( $text, new DemoMedia()->for( 'notes.txt' ) );
		$this->assertStringStartsWith( "Demo file\nsong.mp3\n", new DemoMedia()->for( 'song.mp3' ) );
	}

	/**
	 * What names the application is the caller's to say.
	 */
	public function test_the_branding_is_the_caller_s(): void {
		$media = new DemoMedia( note: 'Acme demo — not for sale.', creator: 'Acme seed', placeholder: "Acme\n{name}\n" );

		$this->assertSame( "Acme\nnotes.txt\n", $media->for( 'notes.txt' ) );
		$this->assertStringContainsString( '/Creator (Acme seed)', $media->for( 'Guide.pdf' ) );
		$this->assertStringContainsString( 'Acme demo', $media->for( 'Guide.pdf' ) );
		$this->assertSame( "Acme\nKit.zip\n", self::unzip( $media->for( 'Kit.zip' ) )['Kit/README.txt']['data'] );
	}

	public function test_the_fixed_time_can_be_moved(): void {
		$media = new DemoMedia( made: 1_000_000_000 ); // 2001-09-09 01:46:40 UTC.

		$this->assertStringContainsString( '/CreationDate (D:20010909014640Z)', $media->for( 'Guide.pdf' ) );
		$this->assertNotSame( new DemoMedia()->for( 'A.zip' ), $media->for( 'A.zip' ) );
	}

	/* ─── Determinism ───────────────────────────────────────────────── */

	/**
	 * One file of each kind.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function names(): array {
		return array(
			'wav'  => array( 'a.wav' ),
			'beat' => array( 'a beat.wav' ),
			'pdf'  => array( 'b.pdf' ),
			'png'  => array( 'c.png' ),
			'zip'  => array( 'd.zip' ),
			'txt'  => array( 'e.txt' ),
		);
	}

	/**
	 * Same name, same bytes, every run and every instance: a seed that
	 * stores by digest must not leave a second copy of everything behind.
	 */
	#[DataProvider( 'names' )]
	public function test_the_bytes_never_change_between_runs( string $name ): void {
		$this->assertSame( new DemoMedia()->for( $name ), new DemoMedia()->for( $name ) );
	}

	#[DataProvider( 'names' )]
	public function test_different_names_are_different_files( string $name ): void {
		$media = new DemoMedia();

		$this->assertNotSame( $media->for( $name ), $media->for( 'Other ' . $name ) );
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	/**
	 * One unsigned number from a binary string.
	 *
	 * @param string $format An unpack() code: v, V, n or N.
	 * @param string $bytes  The string.
	 * @param int    $offset Where the number starts.
	 *
	 * @return int
	 */
	private static function unpack( string $format, string $bytes, int $offset ): int {
		$value = unpack( $format, $bytes, $offset );

		return is_array( $value ) && is_int( $value[1] ?? null ) ? $value[1] : -1;
	}

	/**
	 * The pitch for() gives a name, worked out the same way.
	 *
	 * @param string $filename File name.
	 *
	 * @return float
	 */
	private static function pitch( string $filename ): float {
		return 220.0 * ( 2 ** ( ( hexdec( substr( hash( 'sha256', $filename ), 0, 2 ) ) % 12 ) / 12 ) );
	}

	/**
	 * Every member of a stored ZIP, found through its central directory.
	 *
	 * @param string $zip The archive's bytes.
	 *
	 * @return array<string, array{data: string, crc: int, time: int, date: int}>
	 */
	private static function unzip( string $zip ): array {
		$end = strrpos( $zip, "PK\x05\x06" );

		self::assertIsInt( $end, 'An end-of-central-directory record.' );
		self::assertSame( strlen( $zip ) - 22, $end, 'At the very end, with no comment.' );

		$count  = self::unpack( 'v', $zip, $end + 10 );
		$offset = self::unpack( 'V', $zip, $end + 16 );

		self::assertSame( $end, $offset + self::unpack( 'V', $zip, $end + 12 ), 'The directory runs up to the record.' );

		$members = array();

		for ( $i = 0; $i < $count; $i++ ) {
			self::assertSame( "PK\x01\x02", substr( $zip, $offset, 4 ), 'A central directory entry.' );
			self::assertSame( 0, self::unpack( 'v', $zip, $offset + 10 ), 'Stored, not deflated.' );

			$size    = self::unpack( 'V', $zip, $offset + 20 );
			$length  = self::unpack( 'v', $zip, $offset + 28 );
			$extra   = self::unpack( 'v', $zip, $offset + 30 );
			$comment = self::unpack( 'v', $zip, $offset + 32 );
			$local   = self::unpack( 'V', $zip, $offset + 42 );
			$name    = substr( $zip, $offset + 46, $length );

			self::assertSame( "PK\x03\x04", substr( $zip, $local, 4 ), 'A local header for ' . $name );

			$start = $local + 30 + self::unpack( 'v', $zip, $local + 26 ) + self::unpack( 'v', $zip, $local + 28 );

			$members[ $name ] = array(
				'data' => substr( $zip, $start, $size ),
				'crc'  => self::unpack( 'V', $zip, $offset + 16 ),
				'time' => self::unpack( 'v', $zip, $offset + 12 ),
				'date' => self::unpack( 'v', $zip, $offset + 14 ),
			);

			$offset += 46 + $length + $extra + $comment;
		}

		return $members;
	}
}
