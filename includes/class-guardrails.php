<?php
/**
 * Guardrails (spec section 7). Deterministic checks that run BEFORE retrieval (pre-filters) and on
 * everything Alia is about to say (post-filters). They live outside the brain, so any future brain
 * is bound by them unchanged.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guardrails {

	const CURRENCY = 'omr|r\.?o\.?|usd|us\$|\$|€|£|aed|sar|eur|gbp|rials?|riyals?|baisa|baiza|dollars?|dirhams?|euros?|pounds?';

	/** A currency name or symbol next to a number, either order. */
	public static function has_price( $text ) {
		$c  = self::CURRENCY;
		$re = '/(?:(?<![a-z])(?:' . $c . ')\s*\d)|(?:\d[\d,.]*\s*(?:' . $c . ')(?![a-z]))/iu';

		return 1 === preg_match( $re, (string) $text );
	}

	/** Pre-filter 1: the message is a political / conflict question. */
	public static function politics_hit( $norm ) {
		$allow = Settings::lines( 'politics_allow' );
		$scan  = $norm;
		foreach ( $allow as $a ) {
			$n = Text::normalize( $a );
			if ( '' !== $n ) {
				$scan = str_replace( $n, ' ', $scan );
			}
		}

		return Text::has_any( $scan, Settings::lines( 'politics' ) );
	}

	/** Safety intent: asks whether travel is safe / advisable. */
	public static function safety_intent( $norm ) {
		return (bool) Text::has_any( $norm, Settings::lines( 'safety_intent' ) );
	}

	/** Safety question about "right now" or "the situation", even without a conflict word. */
	public static function safety_now( $norm ) {
		return self::safety_intent( $norm ) && Text::has_any( $norm, Settings::lines( 'safety_now' ) );
	}

	public static function price_intent( $norm ) {
		return (bool) Text::has_any( $norm, Settings::lines( 'price' ) );
	}

	public static function human( $norm ) {
		return (bool) Text::has_any( $norm, Settings::lines( 'human' ) );
	}

	public static function frustration( $norm ) {
		return (bool) Text::has_any( $norm, Settings::lines( 'frustration' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Post-filters                                                        */
	/* ------------------------------------------------------------------ */

	/** Remove any sentence that carries a price, unless the entry is a general money fact. */
	public static function scrub_prices( $text, $money_general ) {
		if ( $money_general || ! self::has_price( $text ) ) {
			return $text;
		}
		$keep = array();
		foreach ( Text::sentences( $text ) as $s ) {
			if ( ! self::has_price( $s ) ) {
				$keep[] = $s;
			}
		}

		return trim( implode( ' ', $keep ) );
	}

	/** Remove URLs that are not on the allowlist (entry link, contact settings, visa link). */
	public static function strip_urls( $text, array $allowed ) {
		$allowed = array_map( static function ( $u ) {
			return rtrim( strtolower( $u ), '/' );
		}, array_filter( $allowed ) );

		return trim( preg_replace_callback(
			'#\bhttps?://[^\s)\]]+#i',
			static function ( $m ) use ( $allowed ) {
				$u = rtrim( strtolower( rtrim( $m[0], '.,;:' ) ), '/' );

				return in_array( $u, $allowed, true ) ? $m[0] : '';
			},
			(string) $text
		) );
	}

	public static function tidy( $text ) {
		$text = preg_replace( '/\s*\((?:verify|check)\)/i', '', (string) $text );
		$text = preg_replace( '/\s*\(\s*,?\s*\)/', '', $text );
		$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
		$text = preg_replace( '/\s+([,.;:!?])/', '$1', $text );

		return trim( $text );
	}
}
