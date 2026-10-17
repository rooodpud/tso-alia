<?php
/**
 * Search index: built from published entries and cached as one JSON option, rebuilt whenever an
 * entry is saved, trashed or deleted (and when the synonym list changes).
 * Everything retrieval and the answer need is in the index, so a question never touches the posts table.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Index {

	const OPTION = 'alia_index';

	/** Entry types that can answer a visitor's question. */
	const ANSWERING = array( 'faq', 'destination', 'activity' );

	private static $cache = null;

	public static function register() {
		add_action( 'save_post_' . Entries::CPT, array( __CLASS__, 'on_change' ), 99 );
		add_action( 'trashed_post', array( __CLASS__, 'on_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_post' ) );
		add_action( 'deleted_post', array( __CLASS__, 'on_post' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms' ), 10, 1 );
	}

	/** @var bool While true (bulk import) entry saves do not rebuild; the importer rebuilds once at the end. */
	public static $suspended = false;

	public static function on_change() {
		if ( ! self::$suspended ) {
			self::rebuild();
		}
	}

	public static function on_post( $post_id ) {
		if ( Entries::CPT === get_post_type( $post_id ) ) {
			self::rebuild();
		}
	}

	public static function on_terms( $object_id ) {
		if ( Entries::CPT === get_post_type( $object_id ) ) {
			// Terms are set before save_post fires; the save hook rebuilds. Only rebuild for later term edits.
			if ( ! doing_action( 'save_post_' . Entries::CPT ) && ! doing_action( 'wp_insert_post' ) ) {
				self::rebuild();
			}
		}
	}

	/** @return array{docs:array,df:array,vocab:array,n:int,built:int} */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( self::OPTION );
		if ( is_string( $raw ) && '' !== $raw ) {
			$arr = json_decode( $raw, true );
			if ( is_array( $arr ) && isset( $arr['v'], $arr['docs'] ) ) {
				self::$cache = $arr;

				return self::$cache;
			}
		}

		return self::rebuild();
	}

	public static function set_cache( $index ) {
		self::$cache = $index;
	}

	public static function rebuild() {
		Text::reset();
		$posts = get_posts(
			array(
				'post_type'      => Entries::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$entries = array();
		foreach ( $posts as $id ) {
			$d = Entries::data( $id );
			if ( $d ) {
				$entries[] = $d;
			}
		}
		$index = self::build( $entries );
		update_option( self::OPTION, wp_json_encode( $index ), false );
		self::$cache = $index;

		return $index;
	}

	/** Build an index from entry arrays (also used by tests with generated entries). */
	public static function build( array $entries ) {
		$docs = array();
		$df   = array();
		foreach ( $entries as $d ) {
			if ( ! in_array( $d['type'], self::ANSWERING, true ) ) {
				continue;
			}
			$questions = array();
			$all       = array();
			foreach ( $d['questions'] as $q ) {
				$t = Text::tokens( $q );
				if ( $t ) {
					$questions[] = $t;
					$all         = array_merge( $all, $t );
				}
			}
			$title = Text::tokens( $d['title'] );
			$kw    = Text::tokens( implode( ' ', $d['keywords'] ) );
			$ans   = array_slice( Text::tokens( $d['answer'] ), 0, 80 );
			foreach ( array_unique( array_merge( $title, $kw, $all, $ans ) ) as $tok ) {
				$df[ $tok ] = ( $df[ $tok ] ?? 0 ) + 1;
			}
			$docs[ $d['id'] ] = array(
				'id'    => $d['id'],
				'type'  => $d['type'],
				'title' => $d['title'],
				't'     => $title,
				'q'     => $questions,
				'k'     => $kw,
				'a'     => $ans,
				'ans'   => $d['answer'],
				'det'   => $d['detail'],
				'link'  => $d['link'],
				'img'   => $d['image'],
				'vol'   => $d['volatile'],
				'ver'   => $d['verified'],
				'fl'    => $d['flags'],
				'fu'    => $d['followups'],
				'rel'   => $d['related'],
				'reg'   => $d['regions'],
				'use'   => $d['use'],
				'qs'    => $d['questions'],
			);
		}

		return array(
			'v'     => 1,
			'built' => time(),
			'n'     => count( $docs ),
			'docs'  => $docs,
			'df'    => $df,
			'vocab' => array_keys( $df ),
		);
	}

	/** Policy entries by key: [ key => [ [id, text], ... ] ], read from the posts table (small, not on the hot path). */
	public static function policies() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		$ids   = get_posts(
			array(
				'post_type'      => Entries::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => '_alia_type', 'value' => 'policy' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			$key = (string) get_post_meta( $id, '_alia_policy_key', true );
			$txt = (string) get_post_meta( $id, '_alia_answer', true );
			if ( '' !== $key && '' !== trim( $txt ) ) {
				$cache[ $key ][] = array( 'id' => (int) $id, 'text' => $txt );
			}
		}

		return $cache;
	}
}
