<?php
/**
 * Stats (spec 10): what visitors ask and how Alia does, from the (scrubbed, 90-day) log table.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stats {

	const PAGE = 'alia-stats';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
	}

	public static function menu() {
		add_submenu_page( Admin::PARENT, __( 'Stats', 'travelstore-alia' ), __( 'Stats', 'travelstore-alia' ), Plugin::CAP, self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** @return array summary for the last $days days */
	public static function summary( $days ) {
		global $wpdb;
		$t     = Log::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT question, entry_id, outcome, feedback, created FROM {$t} WHERE created >= %s ORDER BY id DESC LIMIT 20000", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$s = array(
			'messages' => 0, 'by' => array(), 'handoffs_sent' => 0, 'up' => 0, 'down' => 0,
			'plans_started' => 0, 'plans_done' => 0, 'questions' => array(), 'entries' => array(), 'daily' => array(),
		);
		foreach ( (array) $rows as $r ) {
			$q = (string) $r['question'];
			if ( '[hand-off form sent]' === $q ) {
				++$s['handoffs_sent'];
				continue;
			}
			++$s['messages'];
			$o = (string) $r['outcome'];
			$s['by'][ $o ] = ( $s['by'][ $o ] ?? 0 ) + 1;
			if ( 'plan_ask' === $o && ( 0 === strpos( $q, '[plan start' ) || ( '' !== $q && '[' !== $q[0] ) ) ) {
				++$s['plans_started'];
			}
			if ( 'plan' === $o ) {
				++$s['plans_done'];
			}
			if ( (int) $r['feedback'] > 0 ) {
				++$s['up'];
			} elseif ( (int) $r['feedback'] < 0 ) {
				++$s['down'];
			}
			if ( '' !== $q && '[' !== $q[0] ) {
				$key = implode( ' ', Text::tokens( $q ) );
				$key = '' !== $key ? $key : Text::normalize( $q );
				if ( ! isset( $s['questions'][ $key ] ) ) {
					$s['questions'][ $key ] = array( 'q' => $q, 'n' => 0, 'outcome' => $o );
				}
				++$s['questions'][ $key ]['n'];
			}
			if ( (int) $r['entry_id'] > 0 && in_array( $o, array( 'confident', 'more' ), true ) ) {
				$s['entries'][ (int) $r['entry_id'] ] = ( $s['entries'][ (int) $r['entry_id'] ] ?? 0 ) + 1;
			}
			$day = substr( (string) $r['created'], 0, 10 );
			$s['daily'][ $day ] = ( $s['daily'][ $day ] ?? 0 ) + 1;
		}
		uasort( $s['questions'], static function ( $a, $b ) {
			return $b['n'] <=> $a['n'];
		} );
		arsort( $s['entries'] );
		krsort( $s['daily'] );

		return $s;
	}

	private static function pct( $n, $d ) {
		return $d > 0 ? round( 100 * $n / $d ) . '%' : '-';
	}

	public static function page() {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$days = isset( $_GET['days'] ) && 90 === (int) $_GET['days'] ? 90 : 30; // phpcs:ignore WordPress.Security.NonceVerification
		$s    = self::summary( $days );
		$by   = $s['by'];
		$tot  = max( 0, (int) $s['messages'] );
		$base = add_query_arg( array( 'post_type' => Entries::CPT, 'page' => self::PAGE ), admin_url( 'edit.php' ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'Stats', 'travelstore-alia' ) . '</h1>';
		echo '<p><a href="' . esc_url( add_query_arg( 'days', 30, $base ) ) . '"' . ( 30 === $days ? ' style="font-weight:700"' : '' ) . '>' . esc_html__( 'Last 30 days', 'travelstore-alia' ) . '</a> | <a href="' . esc_url( add_query_arg( 'days', 90, $base ) ) . '"' . ( 90 === $days ? ' style="font-weight:700"' : '' ) . '>' . esc_html__( 'Last 90 days', 'travelstore-alia' ) . '</a></p>';
		echo '<p class="description">' . esc_html( sprintf( __( 'Based on logged questions (kept %d days, without emails or phone numbers).', 'travelstore-alia' ), (int) Settings::num( 'retention_days', 90 ) ) ) . '</p>';
		if ( ! $tot && ! $s['handoffs_sent'] ) {
			echo '<p>' . esc_html__( 'No conversations logged in this period yet.', 'travelstore-alia' ) . '</p></div>';

			return;
		}
		$answered = ( $by['confident'] ?? 0 ) + ( $by['more'] ?? 0 );
		$rows     = array(
			__( 'Messages from visitors', 'travelstore-alia' )              => $tot,
			__( 'Answered from an entry', 'travelstore-alia' )              => $answered . ' (' . self::pct( $answered, $tot ) . ')',
			__( '"Did you mean...?" asked', 'travelstore-alia' )            => ( $by['ambiguous'] ?? 0 ) . ' (' . self::pct( $by['ambiguous'] ?? 0, $tot ) . ')',
			__( 'Not answered (fallback)', 'travelstore-alia' )             => ( $by['none'] ?? 0 ) . ' (' . self::pct( $by['none'] ?? 0, $tot ) . ')',
			__( 'Politics declines', 'travelstore-alia' )                   => (int) ( $by['politics'] ?? 0 ),
			__( 'Price questions', 'travelstore-alia' )                     => (int) ( $by['price'] ?? 0 ),
			__( 'Safety questions', 'travelstore-alia' )                    => (int) ( $by['safety'] ?? 0 ),
			__( '"Talk to a person" replies', 'travelstore-alia' )          => (int) ( $by['handoff'] ?? 0 ),
			__( 'Trip ideas built', 'travelstore-alia' )                    => (int) $s['plans_done'],
			__( 'Trip ideas with no good fit', 'travelstore-alia' )         => (int) ( $by['plan_nofit'] ?? 0 ),
			__( 'Requests sent to the team (form)', 'travelstore-alia' )    => (int) $s['handoffs_sent'],
			__( 'Helpful (thumbs up)', 'travelstore-alia' )                 => (int) $s['up'],
			__( 'Not helpful (thumbs down)', 'travelstore-alia' )           => (int) $s['down'],
		);
		echo '<table class="widefat striped" style="max-width:520px"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th scope="row">' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Most asked questions', 'travelstore-alia' ) . '</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Question', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Times', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Result', 'travelstore-alia' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $s['questions'], 0, 10 ) as $q ) {
			echo '<tr><td>' . esc_html( $q['q'] ) . '</td><td>' . (int) $q['n'] . '</td><td>' . esc_html( Cases::kind_of( $q['outcome'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Most used entries', 'travelstore-alia' ) . '</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Entry', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Times used', 'travelstore-alia' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $s['entries'], 0, 10, true ) as $id => $n ) {
			$title = get_the_title( $id );
			echo '<tr><td>' . ( $title ? '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $title ) . '</a>' : esc_html( '#' . $id ) ) . '</td><td>' . (int) $n . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Messages per day (latest 14 days with activity)', 'travelstore-alia' ) . '</h2><table class="widefat striped" style="max-width:520px"><tbody>';
		foreach ( array_slice( $s['daily'], 0, 14, true ) as $day => $n ) {
			echo '<tr><th scope="row">' . esc_html( $day ) . '</th><td>' . str_repeat( '&#9632;', min( 60, (int) $n ) ) . ' ' . (int) $n . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}
}
