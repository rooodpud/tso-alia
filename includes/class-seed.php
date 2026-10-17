<?php
/**
 * Seed import: parses data/oman_faq_batch1_draft.md (owner's Batch 1) into entries.
 *  - "### ..." blocks in "Standard responses" become Policy / template entries.
 *  - "**Q001. Question**" blocks become FAQ entries (Volatile, Follow-ups, [CONTACT] understood).
 * Idempotent: an entry already imported (meta _alia_seed_id) is skipped, nothing is overwritten.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seed {

	const DONE_OPTION = 'alia_seed_done';

	private static function file() {
		return TSA_PATH . 'data/oman_faq_batch1_draft.md';
	}

	/** @return array{faqs:int,policies:int,skipped:int,errors:array} */
	public static function import() {
		$res = array( 'faqs' => 0, 'policies' => 0, 'skipped' => 0, 'errors' => array() );
		if ( ! is_readable( self::file() ) ) {
			$res['errors'][] = 'Seed file not found.';

			return $res;
		}
		$md = str_replace( "\r\n", "\n", (string) file_get_contents( self::file() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Entries::register_types();

		// Split off the standard-responses part.
		$first = strpos( $md, "\n## 1." );
		$std   = false === $first ? '' : substr( $md, 0, $first );
		$rest  = false === $first ? $md : substr( $md, $first );

		foreach ( self::parse_policies( $std ) as $p ) {
			$r = self::insert( 'policy', $p['title'], $p['text'], array( 'policy_key' => $p['key'], 'seed' => $p['seed'] ) );
			'new' === $r ? $res['policies']++ : $res['skipped']++;
		}
		foreach ( self::parse_faqs( $rest ) as $f ) {
			$meta = array(
				'seed'      => $f['seed'],
				'volatile'  => $f['volatile'],
				'followups' => $f['followups'],
				'flags'     => $f['flags'],
				'use'       => $f['use'],
				'category'  => $f['category'],
			);
			$r = self::insert( 'faq', $f['title'], $f['answer'], $meta );
			'new' === $r ? $res['faqs']++ : $res['skipped']++;
		}
		Index::rebuild();
		self::link_followups();
		self::apply_extras();
		Index::rebuild();
		update_option( self::DONE_OPTION, gmdate( 'c' ), false );

		return $res;
	}

	private static function parse_policies( $std ) {
		$out = array();
		$parts = preg_split( '/^### /m', $std );
		array_shift( $parts );
		$map = array(
			'Greeting'      => 'greeting',
			'Fallback'      => 'fallback',
			'Price'         => 'price',
			'Visa caveat'   => 'visa_caveat',
			'Politics'      => 'politics_decline',
			'Travel-safety' => 'safety',
			'Empathy'       => 'empathy',
		);
		foreach ( $parts as $block ) {
			$lines  = explode( "\n", $block );
			$header = trim( array_shift( $lines ) );
			$key    = '';
			foreach ( $map as $prefix => $k ) {
				if ( 0 === strpos( $header, $prefix ) ) {
					$key = $k;
				}
			}
			if ( '' === $key ) {
				continue;
			}
			$body = trim( implode( "\n", $lines ) );
			$body = trim( preg_replace( '/\n---\s*$/', '', $body ) );
			if ( in_array( $key, array( 'politics_decline', 'empathy' ), true ) ) {
				preg_match_all( '/^\s*(?:\d+\.|-)\s*"(.+)"\s*$/m', $body, $m );
				foreach ( $m[1] as $i => $text ) {
					$out[] = array( 'key' => $key, 'title' => ucfirst( str_replace( '_', ' ', $key ) ) . ' ' . ( $i + 1 ), 'text' => trim( $text ), 'seed' => 'POL-' . $key . '-' . ( $i + 1 ) );
				}
				continue;
			}
			$text = self::clean_policy( $body );
			if ( 'safety' === $key ) {
				// Spec decision D: no opinion. Replace the "widely regarded ... low crime" sentence.
				$text = preg_replace( '/Oman is widely regarded[^.]*\./', "Conditions can change, and I don't give opinions on that, so I'm not the best source for current advice.", $text );
			}
			$out[] = array( 'key' => $key, 'title' => $header, 'text' => $text, 'seed' => 'POL-' . $key );
		}

		return $out;
	}

	/** Remove the draft file's quote marks and the trailing "+ [CONTACT]" marker (contact options are added by Alia). */
	public static function clean_policy( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/"\s*\+\s*\[CONTACT\]\s*$/', '', $text );
		$text = str_replace( '[CONTACT]', '', $text );

		return trim( $text, " \t\n\r\"" );
	}

	private static function parse_faqs( $rest ) {
		$out = array();
		$cat = '';
		$cur = null;
		foreach ( explode( "\n", $rest ) as $line ) {
			if ( preg_match( '/^## \d+\.\s*(.+)$/', $line, $m ) ) {
				$cat = trim( $m[1] );
				continue;
			}
			if ( preg_match( '/^\*\*Q(\d{3})\.\s*(.+?)\*\*\s*$/', $line, $m ) ) {
				if ( $cur ) {
					$out[] = self::finish_faq( $cur );
				}
				$cur = array( 'seed' => 'Q' . $m[1], 'title' => trim( $m[2] ), 'lines' => array(), 'volatile' => false, 'followups' => array(), 'category' => $cat );
				continue;
			}
			if ( ! $cur ) {
				continue;
			}
			if ( preg_match( '/^Volatile:\s*(yes|no)/i', $line, $m ) ) {
				$cur['volatile'] = 'yes' === strtolower( $m[1] );
				continue;
			}
			if ( preg_match( '/^Follow-ups:\s*(.+)$/i', $line, $m ) ) {
				preg_match_all( '/\[([^\]]+)\]/', $m[1], $f );
				$cur['followups'] = array_map( 'trim', $f[1] );
				continue;
			}
			if ( preg_match( '/^---\s*$/', $line ) || preg_match( '/^## /', $line ) ) {
				$out[] = self::finish_faq( $cur );
				$cur   = null;
				continue;
			}
			if ( '' !== trim( $line ) ) {
				$cur['lines'][] = trim( $line );
			}
		}
		if ( $cur ) {
			$out[] = self::finish_faq( $cur );
		}

		return $out;
	}

	private static function finish_faq( array $c ) {
		$ans   = trim( implode( ' ', $c['lines'] ) );
		$flags = array();
		$use   = '';
		if ( false !== strpos( $ans, '[CONTACT]' ) ) {
			$flags[] = 'contact';
			$ans     = trim( str_replace( '[CONTACT]', '', $ans ) );
		}
		if ( preg_match( '/^\(Use the standard price response/i', $ans ) ) {
			$use = 'price';
			$ans = '';
		} elseif ( preg_match( '/^\(Use the standard travel-safety response/i', $ans ) ) {
			$use = 'safety';
			$ans = '';
		}
		if ( false !== stripos( $c['category'], 'visa' ) ) {
			$flags[] = 'visa';
		}
		if ( 0 === stripos( $c['category'], 'money' ) ) {
			$flags[] = 'money_general';
		}
		$ans = trim( preg_replace( '/\s*\((?:verify|visa caveat appended\.?)\)/i', '', $ans ) );

		return array(
			'seed'      => $c['seed'],
			'title'     => $c['title'],
			'answer'    => $ans,
			'volatile'  => $c['volatile'],
			'followups' => $c['followups'],
			'flags'     => array_values( array_unique( $flags ) ),
			'use'       => $use,
			'category'  => $c['category'],
		);
	}

	/** @return string new|exists */
	private static function insert( $type, $title, $answer, array $meta ) {
		$existing = get_posts(
			array(
				'post_type'      => Entries::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_alia_seed_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $meta['seed'], // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $existing ) {
			return 'exists';
		}
		$id = wp_insert_post(
			array(
				'post_type'   => Entries::CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 'exists';
		}
		update_post_meta( $id, '_alia_type', $type );
		update_post_meta( $id, '_alia_seed_id', $meta['seed'] );
		update_post_meta( $id, '_alia_answer', $answer );
		update_post_meta( $id, '_alia_verified', gmdate( 'Y-m-d' ) ); // Date imported, NOT a fact check. Owner re-verifies.
		if ( 'policy' === $type ) {
			update_post_meta( $id, '_alia_policy_key', $meta['policy_key'] );

			return 'new';
		}
		update_post_meta( $id, Stale::SEED_FLAG, '1' ); // Imported text, not fact-checked by the team.
		update_post_meta( $id, '_alia_questions', array( $title ) );
		update_post_meta( $id, '_alia_volatile', ! empty( $meta['volatile'] ) ? '1' : '0' );
		update_post_meta( $id, '_alia_followups', $meta['followups'] );
		update_post_meta( $id, '_alia_flags', $meta['flags'] );
		update_post_meta( $id, '_alia_use_policy', $meta['use'] );
		if ( ! empty( $meta['category'] ) ) {
			wp_set_object_terms( $id, $meta['category'], Entries::TAG );
		}
		$region = self::region_of( $title );
		if ( '' !== $region ) {
			wp_set_object_terms( $id, $region, Entries::REGION );
		}

		return 'new';
	}

	private static function region_of( $text ) {
		$t = Text::canonical( Text::normalize( $text ) );
		foreach ( array( 'muscat', 'nizwa', 'wahiba', 'salalah', 'musandam' ) as $r ) {
			if ( false !== strpos( $t, $r ) ) {
				return $r;
			}
		}
		if ( preg_match( '/jabal|akhdar|shams/', $t ) ) {
			return 'jabal';
		}
		if ( false !== strpos( $t, 'khareef' ) ) {
			return 'salalah';
		}

		return '';
	}

	/**
	 * data/seed-extra.json lists extra ways visitors phrase each seed question ("seed id" => [phrasings]).
	 * They are added as example questions. Idempotent: nothing is added twice.
	 *
	 * @return int number of phrasings added
	 */
	public static function apply_extras() {
		$file = TSA_PATH . 'data/seed-extra.json';
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		$map   = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$added = 0;
		foreach ( (array) $map as $seed => $phrases ) {
			$ids = get_posts( array( 'post_type' => Entries::CPT, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_alia_seed_id', 'meta_value' => $seed ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( ! $ids ) {
				continue;
			}
			$q    = (array) get_post_meta( $ids[0], '_alia_questions', true );
			$have = array_map( 'strtolower', $q );
			foreach ( (array) $phrases as $p ) {
				if ( ! in_array( strtolower( $p ), $have, true ) ) {
					$q[]    = sanitize_text_field( $p );
					$have[] = strtolower( $p );
					$added++;
				}
			}
			update_post_meta( $ids[0], '_alia_questions', $q );
		}

		return $added;
	}

	/**
	 * Follow-up buttons are written as short labels ("Passport validity"). Where a label closely matches
	 * another entry, add it as an example question there, so tapping the button finds that entry.
	 */
	private static function link_followups() {
		$index = Index::get();
		$ids   = array_keys( $index['docs'] );
		foreach ( $ids as $id ) {
			$labels = Entries::data( $id )['followups'];
			foreach ( $labels as $label ) {
				$tok = Text::tokens( $label );
				list( $tok ) = Retrieval::correct( $tok, $index['vocab'] );
				$m = Retrieval::search( $index, $tok );
				if ( ! $m || $m[0]['id'] === $id || $m[0]['score'] < 0.45 ) {
					continue;
				}
				$target = Entries::data( $m[0]['id'] );
				if ( ! $target || in_array( $label, $target['questions'], true ) ) {
					continue;
				}
				$q = $target['questions'];
				$q[] = $label;
				update_post_meta( $m[0]['id'], '_alia_questions', $q );
			}
		}
	}
}
