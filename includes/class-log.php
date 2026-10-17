<?php
/**
 * Conversation log (spec section 10, privacy first).
 * A row has: time, a random session id (not an IP), the question with emails / phone numbers / long
 * numbers scrubbed, matched entry, score, outcome and the visitor's thumbs up / down.
 * Rows older than the retention period (default 90 days) are removed by a daily job.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Log {

	const TABLE_SUFFIX = 'alia_log';
	const CRON         = 'alia_purge_log';
	const DB_VERSION   = '1';

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function register() {
		add_action( self::CRON, array( __CLASS__, 'purge' ) );
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );
	}

	public static function maybe_install() {
		if ( get_option( 'alia_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				session_id varchar(40) NOT NULL DEFAULT '',
				question text NOT NULL,
				entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
				score float NOT NULL DEFAULT 0,
				outcome varchar(20) NOT NULL DEFAULT '',
				feedback tinyint(4) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created (created),
				KEY outcome (outcome)
			) {$charset};"
		);
		update_option( 'alia_db_version', self::DB_VERSION, false );
	}

	/** Remove contact details a visitor may have typed. */
	public static function scrub( $text ) {
		$t = (string) $text;
		$t = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $t );
		$t = preg_replace( '#\bhttps?://\S+#i', '[link]', $t );
		$t = preg_replace( '/\+?\d[\d\s().\-]{5,}\d/', '[number]', $t );
		$t = preg_replace( '/\d{5,}/', '[number]', $t );

		return trim( mb_substr( $t, 0, 300 ) );
	}

	public static function record( $session, $question, $entry_id, $score, $outcome ) {
		global $wpdb;
		$session = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $session );
		$wpdb->insert(
			self::table(),
			array(
				'created'    => current_time( 'mysql', true ),
				'session_id' => substr( $session, 0, 40 ),
				'question'   => self::scrub( $question ),
				'entry_id'   => (int) $entry_id,
				'score'      => (float) $score,
				'outcome'    => substr( sanitize_key( $outcome ), 0, 20 ),
			),
			array( '%s', '%s', '%s', '%d', '%f', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function feedback( $log_id, $vote ) {
		global $wpdb;
		$vote = (int) $vote > 0 ? 1 : -1;

		return (bool) $wpdb->update( self::table(), array( 'feedback' => $vote ), array( 'id' => (int) $log_id ), array( '%d' ), array( '%d' ) );
	}

	public static function token( $log_id ) {
		return substr( hash_hmac( 'sha256', 'alia-fb-' . (int) $log_id, wp_salt( 'auth' ) ), 0, 20 );
	}

	/** @return int rows removed */
	public static function purge() {
		global $wpdb;
		$days = max( 1, (int) Settings::num( 'retention_days', 90 ) );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created < %s', $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Unanswered questions grouped by their normalised text. */
	public static function unanswered( $limit = 200 ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT question, created FROM ' . self::table() . " WHERE outcome = 'none' ORDER BY id DESC LIMIT 1000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$groups = array();
		foreach ( (array) $rows as $r ) {
			$key = implode( ' ', Text::tokens( $r['question'] ) );
			if ( '' === $key ) {
				$key = Text::normalize( $r['question'] );
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'question' => $r['question'], 'count' => 0, 'last' => $r['created'] );
			}
			$groups[ $key ]['count']++;
		}
		uasort(
			$groups,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return array_slice( array_values( $groups ), 0, $limit );
	}

	public static function down_votes( $limit = 50 ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT question, entry_id, created FROM ' . self::table() . ' WHERE feedback = -1 ORDER BY id DESC LIMIT %d', (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function counts() {
		global $wpdb;
		$t = self::table();

		return array(
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ), // phpcs:ignore
			'none'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE outcome = 'none'" ), // phpcs:ignore
			'up'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE feedback = 1" ), // phpcs:ignore
			'down'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE feedback = -1" ), // phpcs:ignore
		);
	}
}
