<?php
/**
 * Text helpers shared by indexing and searching, so both sides see exactly the same tokens:
 * normalise, apply synonyms, drop stop words, light stemming.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Text {

	/** Words that carry no search meaning. "oman" is noise: every entry is about Oman. */
	const STOP = 'a an the is are was were be been am do does did can could should would will shall i you we they he she it my me our your to of in on at for and or but with about from by this that these those what whats how when where which who whom there their any some please tell know want need like get have has had if so as into than then just also oman omani here very much many';

	/** @var array|null [ [regex, replacement], ... ] built from the synonym list */
	private static $syn = null;
	private static $stop = null;

	public static function reset() {
		self::$syn = null;
	}

	public static function normalize( $text ) {
		$t = remove_accents( (string) $text );
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
		$t = str_replace( array( "'", "\u{2019}", "\u{2018}" ), '', $t );
		$t = preg_replace( '/[^a-z0-9]+/', ' ', $t );

		return trim( preg_replace( '/\s+/', ' ', (string) $t ) );
	}

	private static function synonyms() {
		if ( null !== self::$syn ) {
			return self::$syn;
		}
		$pairs = array();
		foreach ( Settings::lines( 'synonyms' ) as $line ) {
			$members = array_values( array_filter( array_map( array( __CLASS__, 'normalize' ), explode( '=', $line ) ), 'strlen' ) );
			if ( count( $members ) < 2 ) {
				continue;
			}
			$canon = str_replace( ' ', '', $members[0] );
			foreach ( array_slice( $members, 1 ) as $m ) {
				$pairs[] = array( $m, $canon );
			}
		}
		// Longest phrases first, so "wahiba sands" is replaced before "wahiba".
		usort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b[0] ) <=> strlen( $a[0 ] );
			}
		);
		self::$syn = array_map(
			static function ( $p ) {
				return array( '/(?<![a-z0-9])' . preg_quote( $p[0], '/' ) . '(?![a-z0-9])/', $p[1] );
			},
			$pairs
		);

		return self::$syn;
	}

	public static function canonical( $norm ) {
		foreach ( self::synonyms() as $pair ) {
			$norm = preg_replace( $pair[0], $pair[1], $norm );
		}

		return $norm;
	}

	public static function stem( $w ) {
		$n = strlen( $w );
		if ( $n > 5 && substr( $w, -3 ) === 'ing' ) {
			$w = substr( $w, 0, -3 );
		} elseif ( $n > 4 && substr( $w, -2 ) === 'ed' ) {
			$w = substr( $w, 0, -2 );
		} elseif ( $n > 4 && substr( $w, -3 ) === 'ies' ) {
			$w = substr( $w, 0, -3 ) . 'y';
		} elseif ( $n > 4 && preg_match( '/(ches|shes|sses|xes)$/', $w ) ) {
			$w = substr( $w, 0, -2 );
		} elseif ( $n > 3 && substr( $w, -1 ) === 's' && substr( $w, -2 ) !== 'ss' ) {
			$w = substr( $w, 0, -1 );
		}
		if ( strlen( $w ) > 3 && substr( $w, -1 ) === 'e' ) {
			$w = substr( $w, 0, -1 );
		}

		return $w;
	}

	/** Tokens for matching: normalised, synonyms applied, stop words dropped, stemmed, unique order kept. */
	public static function tokens( $text ) {
		if ( null === self::$stop ) {
			self::$stop = array_flip( explode( ' ', self::STOP ) );
		}
		$norm = self::canonical( self::normalize( $text ) );
		$out  = array();
		foreach ( explode( ' ', $norm ) as $w ) {
			if ( '' === $w || isset( self::$stop[ $w ] ) ) {
				continue;
			}
			$s = self::stem( $w );
			if ( '' !== $s ) {
				$out[ $s ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * Is a phrase present in already-normalised text? Whole words; a single word of 6+ letters also
	 * matches as a prefix, so "politic" catches "politics" and "political".
	 */
	public static function has_phrase( $norm, $phrase ) {
		$p = self::normalize( $phrase );
		if ( '' === $p ) {
			return false;
		}
		$stem = strlen( $p ) >= 6 && false === strpos( $p, ' ' );
		$re   = '/(?<![a-z0-9])' . preg_quote( $p, '/' ) . ( $stem ? '' : '(?![a-z0-9])' ) . '/';

		return 1 === preg_match( $re, $norm );
	}

	public static function has_any( $norm, array $phrases ) {
		foreach ( $phrases as $p ) {
			if ( self::has_phrase( $norm, $p ) ) {
				return $p;
			}
		}

		return false;
	}

	/** Split into sentences, keeping the end punctuation. */
	public static function sentences( $text ) {
		$text = trim( preg_replace( '/[ \t]+/', ' ', (string) $text ) );
		if ( '' === $text ) {
			return array();
		}
		$parts = preg_split( '/(?<=[.!?])\s+(?=[A-Z0-9"\'(])/', $text );

		return array_values( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) );
	}
}
