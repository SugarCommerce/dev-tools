<?php
/**
 * Small, real media files for a demo seed, made in code.
 *
 * @package   SugarCommerce\DevTools
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\DevTools;

use SugarCommerce\TextPdf\{Color, Document};
use SugarCommerce\ZipStream\Archive;

/**
 * Class DemoMedia
 *
 * A seed that uploads text files named .wav, .pdf and .png gives a local
 * install a broken player or a blank frame for every file a buyer or a
 * seller opens — the one part of the product a demo most needs to show.
 * These are the real thing, made in code: a WAV with a RIFF header and a
 * chord in it, a one-page PDF, a PNG, a ZIP. No binary blobs in the
 * repository, and small enough to upload in a second.
 *
 * Every file is the same bytes on every run. A seed that stores files under
 * the digest of their bytes then writes the same keys over the same objects
 * on a re-run, rather than leaving a fresh set behind in the bucket each
 * time. That is why the PDF's creation date and the ZIP's timestamps are
 * fixed, and why nothing here is random: what varies from file to file —
 * a tone, a colour — comes from a hash of its name.
 *
 * Everything that names the application comes in through the constructor:
 * the line under each PDF's heading, the PDF's creator, and the text of a
 * placeholder file.
 *
 * PNGs are drawn with GD; PDFs go through sugarcommerce/text-pdf and ZIPs
 * through sugarcommerce/zip-stream.
 *
 * @since 1.2.0
 */
final readonly class DemoMedia {

	/**
	 * The moment every generated file claims to have been made, unless told
	 * otherwise: a fixed point, so the bytes never depend on the clock.
	 *
	 * @since 1.2.0
	 */
	public const int MADE = 1_767_225_600; // 2026-01-01 00:00:00 UTC.

	/**
	 * Samples per second. Half CD rate: every browser plays it, and three
	 * seconds stay under 140 KB.
	 *
	 * @since 1.2.0
	 */
	public const int RATE = 22_050;

	/**
	 * Fixed for the life of the object, so one instance always makes the
	 * same bytes for the same name.
	 *
	 * @since 1.2.0
	 *
	 * @param string $note        The grey line under every PDF's heading.
	 * @param string $creator     The PDF's Creator.
	 * @param string $placeholder A text file's contents, with `{name}`
	 *                            where its file name goes. Also the README
	 *                            inside every ZIP.
	 * @param int    $made        Unix time stamped into every PDF and ZIP.
	 */
	public function __construct(
		public string $note = 'Demo file — made in code, not a real product.',
		public string $creator = 'sugarcommerce/dev-tools',
		public string $placeholder = "Demo file\n{name}\nPlaceholder content, not a real product.\n\n"
			. "Every other file in this demo is real media made in code by\n"
			. "sugarcommerce/dev-tools, so the players have something to play.\n",
		public int $made = self::MADE,
	) {}

	/* ─── By name ───────────────────────────────────────────────────── */

	/**
	 * Bytes for a demo file, chosen by its extension.
	 *
	 * The name decides the content too, so two files of the same kind are
	 * two different objects, each under its own digest:
	 *
	 * - `.wav` — three seconds of a chord whose root comes from the name;
	 *   pulsed four times a second when the name contains "beat".
	 * - `.pdf` — the name without its extension as the heading, over licence
	 *   terms when it contains "licence" and a short guide otherwise.
	 * - `.png` — 600 × 600 artwork in the name's colours, with the name.
	 * - `.zip` — a folder named for the archive: three one-second stems
	 *   (Drums, Bass, Keys) and a README.
	 * - anything else — the placeholder text.
	 *
	 * @since 1.2.0
	 *
	 * @param string $filename What the file is called in the library.
	 *
	 * @return string
	 */
	public function for( string $filename ): string {
		$title = (string) preg_replace( '/\.[a-z0-9]+$/i', '', $filename );

		return match ( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			'wav'   => self::wav( self::pitch( $filename ), 3.0, beat: str_contains( strtolower( $filename ), 'beat' ) ),
			'pdf'   => $this->pdf( $title, self::paragraphs( $title ) ),
			'png'   => self::png( $title ),
			'zip'   => $this->zip( $this->members( $title ) ),
			default => $this->text( $filename ),
		};
	}

	/* ─── Each kind ─────────────────────────────────────────────────── */

	/**
	 * A mono 16-bit PCM WAV: a chord, faded in and out so it does not click.
	 *
	 * The RIFF header is written here, field by field, because it is
	 * forty-four bytes and a library for it would be larger than the tone.
	 *
	 * @since 1.2.0
	 *
	 * @param float $hz      Root of the chord.
	 * @param float $seconds Length.
	 * @param bool  $beat    Pulse it four times a second, like a beat,
	 *                       rather than hold one long note.
	 *
	 * @return string
	 */
	public static function wav( float $hz, float $seconds = 3.0, bool $beat = false ): string {
		$count   = (int) round( self::RATE * $seconds );
		$fade    = (int) ( self::RATE * 0.05 );
		$samples = '';

		for ( $i = 0; $i < $count; $i++ ) {
			$t = $i / self::RATE;

			// A root, a fifth and an octave: a chord reads as music in the
			// player where a bare sine reads as a test signal.
			$value = 0.5 * sin( 2 * M_PI * $hz * $t )
				+ 0.3 * sin( 2 * M_PI * $hz * 1.5 * $t )
				+ 0.2 * sin( 2 * M_PI * $hz * 2 * $t );

			$envelope = min( 1.0, $i / max( 1, $fade ), ( $count - 1 - $i ) / max( 1, $fade ) );

			if ( $beat ) {
				$envelope *= exp( -8 * fmod( $t, 0.25 ) );
			}

			$samples .= pack( 'v', (int) round( 0.6 * $envelope * $value * 32767 ) & 0xFFFF );
		}

		$size = strlen( $samples );

		return 'RIFF' . pack( 'V', 36 + $size ) . 'WAVE'
			// fmt: PCM (1), mono, the rate, bytes a second, block align, bits.
			. 'fmt ' . pack( 'VvvVVvv', 16, 1, 1, self::RATE, self::RATE * 2, 2, 16 )
			. 'data' . pack( 'V', $size ) . $samples;
	}

	/**
	 * A one-page PDF: a heading, the note, a rule, then the body.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $title      Its heading and its document title.
	 * @param string[] $paragraphs The body.
	 *
	 * @return string
	 */
	public function pdf( string $title, array $paragraphs ): string {
		$pdf = new Document();

		$pdf->heading( $title )
			->gap( 6 )
			->text( $this->note, color: Color::grey( 120 ) )
			->rule();

		foreach ( $paragraphs as $paragraph ) {
			$pdf->text( $paragraph )->gap( 8 );
		}

		return $pdf->render( array( 'Title' => $title, 'Creator' => $this->creator, 'CreationDate' => $this->made ) );
	}

	/**
	 * Square artwork: a gradient in the title's colours, rings, and the
	 * title across the bottom.
	 *
	 * GD's built-in font, drawn small and scaled up: no font file to find,
	 * and the same pixels on every machine.
	 *
	 * @since 1.2.0
	 *
	 * @param string $title What it says, and where its colours come from.
	 * @param int    $size  Width and height, in pixels.
	 *
	 * @return string PNG bytes.
	 *
	 * @throws \InvalidArgumentException When the size is less than one.
	 * @throws \RuntimeException         When GD cannot make the image.
	 */
	public static function png( string $title, int $size = 600 ): string {
		if ( $size < 1 ) {
			throw new \InvalidArgumentException( 'A PNG is at least one pixel square.' );
		}

		$label = strtoupper( $title );
		$image = imagecreatetruecolor( $size, $size );
		$small = imagecreatetruecolor( max( 1, imagefontwidth( 5 ) * strlen( $label ) ), max( 1, imagefontheight( 5 ) ) );

		if ( false === $image || false === $small ) {
			throw new \RuntimeException( 'GD could not make the image.' );
		}

		$hue = hexdec( substr( hash( 'sha256', $title ), 0, 2 ) ) / 255;

		for ( $y = 0; $y < $size; $y++ ) {
			[ $r, $g, $b ] = self::rgb( $hue + ( 0.15 * $y / $size ), 0.6, 0.18 + ( 0.3 * $y / $size ) );
			imageline( $image, 0, $y, $size - 1, $y, (int) imagecolorallocate( $image, $r, $g, $b ) );
		}

		[ $r, $g, $b ] = self::rgb( $hue + 0.5, 0.5, 0.7 );
		$ring          = (int) imagecolorallocatealpha( $image, $r, $g, $b, 80 );

		for ( $n = 1; $n <= 6; $n++ ) {
			imageellipse( $image, (int) ( $size * 0.5 ), (int) ( $size * 0.42 ), $n * (int) ( $size / 8 ), $n * (int) ( $size / 8 ), $ring );
		}

		imagefill( $small, 0, 0, (int) imagecolorallocate( $small, 0, 0, 0 ) );
		imagecolortransparent( $small, (int) imagecolorallocate( $small, 0, 0, 0 ) );
		imagestring( $small, 5, 0, 0, $label, (int) imagecolorallocate( $small, 255, 255, 255 ) );

		$scale = max( 1, min( 3, intdiv( (int) ( $size * 0.9 ), imagesx( $small ) ) ) );
		$width = imagesx( $small ) * $scale;
		imagecopyresized( $image, $small, intdiv( $size - $width, 2 ), (int) ( $size * 0.8 ), 0, 0, $width, imagesy( $small ) * $scale, imagesx( $small ), imagesy( $small ) );

		ob_start();
		imagepng( $image, null, 9 );

		return (string) ob_get_clean();
	}

	/**
	 * A ZIP of the given members, stored, every one stamped with the same
	 * fixed time.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, string> $members Name inside the archive => bytes.
	 *
	 * @return string
	 */
	public function zip( array $members ): string {
		$bytes = '';
		$zip   = new Archive( static function ( string $chunk ) use ( &$bytes ): void {
			$bytes .= $chunk;
		} );

		foreach ( $members as $name => $contents ) {
			$zip->add_string( $name, $contents, $this->made );
		}

		$zip->finish();

		return $bytes;
	}

	/**
	 * Plain text that says what it is: the placeholder, with the name in.
	 *
	 * @since 1.2.0
	 *
	 * @param string $filename Its name.
	 *
	 * @return string
	 */
	public function text( string $filename ): string {
		return strtr( $this->placeholder, array( '{name}' => $filename ) );
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	/**
	 * A pitch for a file, from its name: different files, different tones.
	 *
	 * @param string $filename File name.
	 *
	 * @return float Hertz, a note between A3 and G#4.
	 */
	private static function pitch( string $filename ): float {
		return 220.0 * ( 2 ** ( ( hexdec( substr( hash( 'sha256', $filename ), 0, 2 ) ) % 12 ) / 12 ) );
	}

	/**
	 * What a demo PDF says, by its title.
	 *
	 * @param string $title Title.
	 *
	 * @return string[]
	 */
	private static function paragraphs( string $title ): array {
		return str_contains( strtolower( $title ), 'licence' )
			? array(
				'This licence lets the buyer use the recording in one commercial project, worldwide, for as long as the project is distributed.',
				'It does not transfer ownership, and the recording may not be resold or redistributed on its own, as a sample or otherwise.',
				'Credit the artist where credits are given.',
			)
			: array(
				'A short guide that comes with the files: what is in the download, which formats, and how to get help.',
				'Open the ZIP, start with the master, and keep the original files somewhere safe.',
			);
	}

	/**
	 * What goes inside a demo ZIP: a few short one-shots and a README.
	 *
	 * @param string $title The archive's title, and the folder inside it.
	 *
	 * @return array<string, string>
	 */
	private function members( string $title ): array {
		$members = array();

		foreach ( array( 'Drums', 'Bass', 'Keys' ) as $n => $part ) {
			$members[ $title . '/' . $part . '.wav' ] = self::wav( 110.0 * ( 2 ** ( $n * 5 / 12 ) ), 1.0, beat: 'Drums' === $part );
		}

		$members[ $title . '/README.txt' ] = $this->text( $title . '.zip' );

		return $members;
	}

	/**
	 * A colour from hue, saturation and lightness, for the artwork.
	 *
	 * @param float $h Hue, 0–1, wrapping.
	 * @param float $s Saturation, 0–1.
	 * @param float $l Lightness, 0–1.
	 *
	 * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
	 */
	private static function rgb( float $h, float $s, float $l ): array {
		$h -= floor( $h );
		$q  = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - ( $l * $s );
		$p  = 2 * $l - $q;

		/**
		 * One channel, clamped so rounding can never leave 0–255.
		 *
		 * @param float $t Hue shifted for this channel.
		 *
		 * @return int<0, 255>
		 */
		$channel = static function ( float $t ) use ( $p, $q ): int {
			$t -= floor( $t );

			$v = match ( true ) {
				$t < 1 / 6 => $p + ( ( $q - $p ) * 6 * $t ),
				$t < 1 / 2 => $q,
				$t < 2 / 3 => $p + ( ( $q - $p ) * ( 2 / 3 - $t ) * 6 ),
				default    => $p,
			};

			return max( 0, min( 255, (int) round( 255 * $v ) ) );
		};

		return array( $channel( $h + 1 / 3 ), $channel( $h ), $channel( $h - 1 / 3 ) );
	}
}
