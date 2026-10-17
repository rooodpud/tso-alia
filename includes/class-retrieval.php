<?php
/**
 * Retrieval: tokens of the visitor's message are scored against every indexed entry.
 *
 * Score = best of (weighted Dice on example questions, Dice on title, 0.75 x coverage by title/keywords/
 * questions), plus a small bonus for answer-text coverage and for the session's current destination.
 * Tokens are weighted by rarity (idf), so "wahiba" counts for more than "time".
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Retrieval {

	/** Replace unknown words by the closest indexed word (edit distance 1, or 2 on long words). */
	public static function correct( array $tokens, array $vocab ) {
		$set = array_flip( $vocab );
		$out = array();
		$fix = array();
		foreach ( $tokens as $t ) {
			if ( isset( $set[ $t ] ) || strlen( $t ) < 4 || ctype_digit( $t ) ) {
				$out[] = $t;
				continue;
			}
			$max  = strlen( $t ) >= 8 ? 2 : 1;
			$best = null;
			$bd   = 99;
			foreach ( $vocab as $v ) {
				$lv = strlen( $v );
				if ( abs( $lv - strlen( $t ) ) > $max || $lv < 4 ) {
					continue;
				}
				$d = levenshtein( $t, $v );
				if ( $d < $bd || ( $d === $bd && $v[0] === $t[0] && ( null === $best || $best[0] !== $t[0] ) ) ) {
					$bd   = $d;
					$best = $v;
				}
			}
			if ( null !== $best && $bd <= $max ) {
				$out[]     = $best;
				$fix[ $t ] = $best;
			} else {
				$out[] = $t;
			}
		}

		return array( array_values( array_unique( $out ) ), $fix );
	}

	private static function weight( $tok, $df, $n ) {
		return log( 1 + ( $n / ( ( $df[ $tok ] ?? 0 ) + 1 ) ) ) + 0.4;
	}

	/**
	 * @param array  $index   Index::get() result.
	 * @param array  $qtokens Query tokens (already corrected).
	 * @param string $dest    Region slug in the session (context boost), or ''.
	 * @return array Matches [ [id, score, via] ] best first.
	 */
	public static function search( array $index, array $qtokens, $dest = '' ) {
		if ( ! $qtokens || empty( $index['docs'] ) ) {
			return array();
		}
		$df = $index['df'];
		$n  = max( 1, (int) $index['n'] );
		$wq = array();
		$sq = 0.0;
		foreach ( $qtokens as $t ) {
			$wq[ $t ] = self::weight( $t, $df, $n );
			$sq      += $wq[ $t ];
		}
		$results = array();
		foreach ( $index['docs'] as $id => $doc ) {
			$best  = 0.0;
			$via   = '';
			$union = array_flip( array_merge( $doc['t'], $doc['k'], ...( $doc['q'] ?: array( array() ) ) ) );

			// Example questions (highest weight).
			foreach ( $doc['q'] as $q ) {
				$s = self::dice( $wq, $sq, $q, $df, $n );
				if ( $s > $best ) {
					$best = $s;
					$via  = 'question';
				}
			}
			// Title (medium).
			$s = 0.92 * self::dice( $wq, $sq, $doc['t'], $df, $n );
			if ( $s > $best ) {
				$best = $s;
				$via  = 'title';
			}
			// Coverage of the query by title + keywords + questions (good for short queries).
			$cov = 0.0;
			foreach ( $wq as $t => $w ) {
				if ( isset( $union[ $t ] ) ) {
					$cov += $w;
				}
			}
			$cov = $sq > 0 ? $cov / $sq : 0.0;
			$s   = 0.75 * $cov;
			if ( $s > $best ) {
				$best = $s;
				$via  = 'keywords';
			}
			// Answer text (low weight).
			if ( $best > 0 ) {
				$ans = array_flip( $doc['a'] );
				$ca  = 0.0;
				foreach ( $wq as $t => $w ) {
					if ( isset( $ans[ $t ] ) ) {
						$ca += $w;
					}
				}
				$best += 0.12 * ( $sq > 0 ? $ca / $sq : 0 );
			} else {
				$ans = array_flip( $doc['a'] );
				$ca  = 0.0;
				foreach ( $wq as $t => $w ) {
					if ( isset( $ans[ $t ] ) ) {
						$ca += $w;
					}
				}
				$frac = $sq > 0 ? $ca / $sq : 0;
				// Every query word appears in the answer text: a usable (but modest) match.
				$best = ( $frac >= 0.99 && count( $wq ) >= 2 ) ? 0.5 : 0.3 * $frac;
				$via  = $best > 0 ? 'answer' : '';
			}
			if ( $best > 0 && '' !== $dest && in_array( $dest, $doc['reg'], true ) ) {
				$best += 0.06;
			}
			if ( $best > 0 ) {
				$results[] = array( 'id' => (int) $id, 'score' => round( $best, 4 ), 'via' => $via );
			}
		}
		usort(
			$results,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id'];
			}
		);

		return array_slice( $results, 0, 8 );
	}

	private static function dice( array $wq, $sq, array $dtokens, array $df, $n ) {
		if ( ! $dtokens ) {
			return 0.0;
		}
		$sd = 0.0;
		$sh = 0.0;
		foreach ( $dtokens as $t ) {
			$w = self::weight( $t, $df, $n );
			$sd += $w;
			if ( isset( $wq[ $t ] ) ) {
				$sh += $w;
			}
		}

		return ( $sq + $sd ) > 0 ? ( 2 * $sh ) / ( $sq + $sd ) : 0.0;
	}

	/**
	 * Decide the outcome from the sorted matches and the thresholds.
	 *
	 * @return array{outcome:string,candidates:array}
	 */
	public static function decide( array $matches ) {
		$conf   = Settings::num( 'th_confident', 0.60 );
		$amb    = Settings::num( 'th_ambiguous', 0.40 );
		$margin = Settings::num( 'th_margin', 0.08 );
		if ( ! $matches || $matches[0]['score'] < $amb ) {
			return array( 'outcome' => 'none', 'candidates' => array() );
		}
		$top    = $matches[0]['score'];
		$second = $matches[1]['score'] ?? 0.0;
		if ( $top >= $conf && ( $top - $second ) >= $margin ) {
			return array( 'outcome' => 'confident', 'candidates' => array( $matches[0] ) );
		}
		$cand = array();
		foreach ( $matches as $m ) {
			if ( $m['score'] >= $amb && ( $top - $m['score'] ) <= max( $margin * 1.5, 0.12 ) ) {
				$cand[] = $m;
			}
			if ( count( $cand ) >= 3 ) {
				break;
			}
		}
		if ( count( $cand ) < 2 ) {
			return array( 'outcome' => $top >= $amb ? 'confident' : 'none', 'candidates' => array( $matches[0] ) );
		}

		return array( 'outcome' => 'ambiguous', 'candidates' => $cand );
	}
}
