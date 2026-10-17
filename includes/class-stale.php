<?php
/**
 * Stale entries (spec 10): entries whose facts nobody has checked recently.
 * Reasons shown: seed text never fact-checked, volatile with no date, not verified for N months.
 * "Mark verified" sets the date to today and clears the seed flag.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stale {

	const SEED_FLAG = '_alia_seed_unverified';
	const PAGE      = 'alia-stale';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_alia_mark_verified', array( __CLASS__, 'mark' ) );
	}

	public static function menu() {
		$n     = count( self::items() );
		$badge = $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
		add_submenu_page( Admin::PARENT, __( 'Stale entries', 'travelstore-alia' ), __( 'Stale entries', 'travelstore-alia' ) . $badge, Plugin::CAP, self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** @return array[] id, title, type, status, volatile, verified, reasons[] */
	public static function items() {
		$months = max( 1, (int) Settings::num( 'stale_months', 6 ) );
		$cut    = gmdate( 'Y-m-d', strtotime( '-' . $months . ' months' ) );
		$ids    = get_posts(
			array(
				'post_type'      => Entries::CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$type = (string) get_post_meta( $id, '_alia_type', true );
			if ( 'policy' === $type ) {
				continue; // System messages are wording, not facts.
			}
			$ver  = (string) get_post_meta( $id, '_alia_verified', true );
			$vol  = '1' === (string) get_post_meta( $id, '_alia_volatile', true );
			$why  = array();
			if ( '1' === (string) get_post_meta( $id, self::SEED_FLAG, true ) ) {
				$why[] = __( 'Imported text, not fact-checked', 'travelstore-alia' );
			}
			if ( $vol && '' === $ver ) {
				$why[] = __( 'Volatile, no verified date', 'travelstore-alia' );
			}
			if ( '' !== $ver && $ver < $cut ) {
				$why[] = sprintf( /* translators: %d: months */ __( 'Not verified for more than %d months', 'travelstore-alia' ), $months );
			}
			if ( $why ) {
				$out[] = array(
					'id'       => (int) $id,
					'title'    => get_the_title( $id ),
					'type'     => $type,
					'status'   => get_post_status( $id ),
					'volatile' => $vol,
					'verified' => $ver,
					'reasons'  => $why,
				);
			}
		}
		// Volatile first, then oldest verified date first.
		usort(
			$out,
			static function ( $a, $b ) {
				return ( (int) $b['volatile'] <=> (int) $a['volatile'] ) ?: strcmp( $a['verified'], $b['verified'] );
			}
		);

		return $out;
	}

	public static function mark() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_admin_referer( 'alia_mark_verified' ) ) {
			wp_die( 'Not allowed.' );
		}
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$n   = self::mark_ids( $ids );
		wp_safe_redirect( add_query_arg( array( 'post_type' => Entries::CPT, 'page' => self::PAGE, 'marked' => $n ), admin_url( 'edit.php' ) ) );
		exit;
	}

	/** Set "Last verified" to today and clear the seed flag. @return int entries changed */
	public static function mark_ids( array $ids ) {
		$n = 0;
		Index::$suspended = true;
		foreach ( $ids as $id ) {
			if ( $id && Entries::CPT === get_post_type( $id ) ) {
				update_post_meta( $id, '_alia_verified', gmdate( 'Y-m-d' ) );
				delete_post_meta( $id, self::SEED_FLAG );
				++$n;
			}
		}
		Index::$suspended = false;
		Index::rebuild();

		return $n;
	}

	public static function page() {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$rows = self::items();
		echo '<div class="wrap"><h1>' . esc_html__( 'Stale entries', 'travelstore-alia' ) . '</h1>';
		if ( isset( $_GET['marked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%d entries marked as verified today.', 'travelstore-alia' ), (int) $_GET['marked'] ) ) . '</p></div>'; // phpcs:ignore
		}
		echo '<p>' . esc_html__( 'These entries have facts that nobody has checked recently, or at all. Open one, check the answer against an official source, and click "Mark verified" (or set the date in the entry). Only tick "Mark verified" for entries you have really checked.', 'travelstore-alia' ) . '</p>';
		if ( ! $rows ) {
			echo '<p><strong>' . esc_html__( 'Nothing is stale. Well done.', 'travelstore-alia' ) . '</strong></p></div>';

			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'alia_mark_verified' );
		echo '<input type="hidden" name="action" value="alia_mark_verified">';
		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><td class="check-column"></td><th>' . esc_html__( 'Entry', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Type', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Status', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Volatile', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Last verified', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Why it is listed', 'travelstore-alia' ) . '</th></tr></thead><tbody>';
		$types = Entries::types();
		foreach ( $rows as $r ) {
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '" aria-label="' . esc_attr( sprintf( __( 'Mark %s verified', 'travelstore-alia' ), $r['title'] ) ) . '"></th>';
			echo '<td><a href="' . esc_url( get_edit_post_link( $r['id'] ) ) . '">' . esc_html( $r['title'] ) . '</a></td>';
			echo '<td>' . esc_html( $types[ $r['type'] ] ?? $r['type'] ) . '</td><td>' . esc_html( $r['status'] ) . '</td><td>' . ( $r['volatile'] ? esc_html__( 'Yes', 'travelstore-alia' ) : '-' ) . '</td><td>' . esc_html( $r['verified'] ?: '-' ) . '</td><td>' . esc_html( implode( '; ', $r['reasons'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Mark the ticked entries as verified today', 'travelstore-alia' ), 'primary', 'submit', true );
		echo '</form></div>';
	}
}
