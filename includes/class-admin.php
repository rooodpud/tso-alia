<?php
/**
 * Admin screens (Phase 1): Test Alia, Unanswered questions (with thumbs-down list), Settings.
 * Stale list, test-case runner and CSV import / export are Phase 3 and are not here.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const PARENT = 'edit.php?post_type=' . Entries::CPT;

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings_init' ) );
		add_action( 'admin_post_alia_make_entry', array( __CLASS__, 'make_entry' ) );
		add_action( 'admin_post_alia_reseed', array( __CLASS__, 'reseed' ) );
		add_action( 'admin_post_alia_purge_now', array( __CLASS__, 'purge_now' ) );
	}

	public static function settings_init() {
		register_setting( 'alia_settings_group', Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( 'TravelStore\\Alia\\Settings', 'sanitize' ), 'default' => Settings::defaults() ) );
	}

	public static function menu() {
		add_submenu_page( self::PARENT, __( 'Test Alia', 'travelstore-alia' ), __( 'Test Alia', 'travelstore-alia' ), Plugin::CAP, 'alia-test', array( __CLASS__, 'page_test' ) );
		add_submenu_page( self::PARENT, __( 'Unanswered questions', 'travelstore-alia' ), __( 'Unanswered questions', 'travelstore-alia' ), Plugin::CAP, 'alia-unanswered', array( __CLASS__, 'page_unanswered' ) );
		add_submenu_page( self::PARENT, __( 'Alia settings', 'travelstore-alia' ), __( 'Settings', 'travelstore-alia' ), 'manage_options', Settings::PAGE, array( __CLASS__, 'page_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Test Alia                                                           */
	/* ------------------------------------------------------------------ */

	public static function page_test() {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$q     = isset( $_POST['alia_q'] ) ? sanitize_text_field( wp_unslash( $_POST['alia_q'] ) ) : '';
		$state = isset( $_POST['alia_dest'] ) ? sanitize_key( wp_unslash( $_POST['alia_dest'] ) ) : '';
		$res   = null;
		if ( '' !== $q && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'alia_test' ) ) {
			$res = Engine::respond( $q, array( 'dest' => $state ), array() );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Test Alia', 'travelstore-alia' ) . '</h1>';
		echo '<p>' . esc_html__( 'Type a question exactly as a visitor might. You see which rules fired, the top matches with their scores, and Alia\'s final reply. Nothing is logged from this screen.', 'travelstore-alia' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'alia_test' );
		echo '<p><input type="text" name="alia_q" value="' . esc_attr( $q ) . '" class="regular-text" style="width:min(520px,100%)" maxlength="300" autofocus> ';
		echo '<input type="text" name="alia_dest" value="' . esc_attr( $state ) . '" placeholder="' . esc_attr__( 'pretend we are discussing (e.g. salalah)', 'travelstore-alia' ) . '" class="regular-text"> ';
		submit_button( __( 'Ask Alia', 'travelstore-alia' ), 'primary', 'submit', false );
		echo '</p></form>';
		if ( $res ) {
			$index = Index::get();
			echo '<h2>' . esc_html__( 'Outcome', 'travelstore-alia' ) . ': <code>' . esc_html( $res['outcome'] ) . '</code></h2>';
			echo '<h3>' . esc_html__( 'Rules that fired', 'travelstore-alia' ) . '</h3><ul style="list-style:disc;margin-left:20px">';
			foreach ( $res['trace']['filters'] ?: array( __( '(none: went straight to search)', 'travelstore-alia' ) ) as $f ) {
				echo '<li>' . esc_html( $f ) . '</li>';
			}
			echo '</ul>';
			if ( $res['trace']['tokens'] ) {
				echo '<p><strong>' . esc_html__( 'Words used for matching:', 'travelstore-alia' ) . '</strong> ' . esc_html( implode( ', ', $res['trace']['tokens'] ) );
				if ( $res['trace']['corrected'] ) {
					echo ' &mdash; ' . esc_html__( 'spelling corrected:', 'travelstore-alia' ) . ' ' . esc_html( wp_json_encode( $res['trace']['corrected'] ) );
				}
				echo '</p>';
			}
			if ( $res['trace']['matches'] ) {
				echo '<h3>' . esc_html__( 'Top matches', 'travelstore-alia' ) . '</h3><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Score', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Entry', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Matched on', 'travelstore-alia' ) . '</th></tr></thead><tbody>';
				foreach ( $res['trace']['matches'] as $m ) {
					$d = $index['docs'][ $m['id'] ] ?? null;
					echo '<tr><td>' . esc_html( (string) $m['score'] ) . '</td><td>' . ( $d ? '<a href="' . esc_url( get_edit_post_link( $m['id'] ) ) . '">' . esc_html( $d['title'] ) . '</a>' : esc_html( '#' . $m['id'] ) ) . '</td><td>' . esc_html( $m['via'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			// Keep this question as a regression test.
			$kind = Cases::kind_of( $res['outcome'] );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0">';
			wp_nonce_field( 'alia_case_add' );
			echo '<input type="hidden" name="action" value="alia_case_add"><input type="hidden" name="back" value="test"><input type="hidden" name="q" value="' . esc_attr( $q ) . '"><input type="hidden" name="expect" value="' . esc_attr( $kind ) . '">';
			submit_button( sprintf( /* translators: %s: outcome kind */ __( 'Save as a test case (expects: %s)', 'travelstore-alia' ), Cases::kinds()[ $kind ] ?? $kind ), 'secondary', 'submit', false );
			echo '</form>';
			if ( isset( $_GET['added'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Saved as a test case.', 'travelstore-alia' ) . '</p></div>';
			}
			echo '<h3>' . esc_html__( 'Alia would say', 'travelstore-alia' ) . '</h3><div style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;max-width:700px">';
			foreach ( $res['chunks'] as $c ) {
				echo '<p>' . esc_html( $c['text'] ) . '</p>';
				foreach ( $c['links'] as $l ) {
					echo '<p style="margin:0 0 4px 12px">&rarr; ' . esc_html( $l['label'] ) . ' <code>' . esc_html( $l['url'] ) . '</code></p>';
				}
			}
			if ( $res['quick_replies'] ) {
				echo '<p><em>' . esc_html__( 'Buttons:', 'travelstore-alia' ) . '</em> ' . esc_html( implode( ' | ', wp_list_pluck( $res['quick_replies'], 'label' ) ) ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Unanswered questions + thumbs down                                  */
	/* ------------------------------------------------------------------ */

	public static function page_unanswered() {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$c = Log::counts();
		echo '<div class="wrap"><h1>' . esc_html__( 'Unanswered questions', 'travelstore-alia' ) . '</h1>';
		echo '<p>' . esc_html( sprintf( __( 'Logged questions: %1$d. Unanswered: %2$d. Thumbs up: %3$d. Thumbs down: %4$d. Questions are stored without emails or phone numbers and removed after %5$d days.', 'travelstore-alia' ), $c['total'], $c['none'], $c['up'], $c['down'], (int) Settings::num( 'retention_days', 90 ) ) ) . '</p>';
		$rows = Log::unanswered();
		echo '<h2>' . esc_html__( 'Questions Alia could not answer', 'travelstore-alia' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'None yet.', 'travelstore-alia' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Question', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Times asked', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Last asked', 'travelstore-alia' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( $r['question'] ) . '</td><td>' . (int) $r['count'] . '</td><td>' . esc_html( $r['last'] ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'alia_make_entry' );
				echo '<input type="hidden" name="action" value="alia_make_entry"><input type="hidden" name="q" value="' . esc_attr( $r['question'] ) . '">';
				submit_button( __( 'Create entry from this', 'travelstore-alia' ), 'small', 'submit', false );
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
		}
		$down = Log::down_votes();
		echo '<h2>' . esc_html__( 'Answers marked "not helpful"', 'travelstore-alia' ) . '</h2>';
		if ( ! $down ) {
			echo '<p>' . esc_html__( 'None yet.', 'travelstore-alia' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Question', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Answered with', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'When', 'travelstore-alia' ) . '</th></tr></thead><tbody>';
			foreach ( $down as $r ) {
				$t = $r['entry_id'] ? get_the_title( (int) $r['entry_id'] ) : '-';
				echo '<tr><td>' . esc_html( $r['question'] ) . '</td><td>' . ( $r['entry_id'] ? '<a href="' . esc_url( get_edit_post_link( (int) $r['entry_id'] ) ) . '">' . esc_html( $t ) . '</a>' : '-' ) . '</td><td>' . esc_html( $r['created'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function make_entry() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_admin_referer( 'alia_make_entry' ) ) {
			wp_die( 'Not allowed.' );
		}
		$q  = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$id = wp_insert_post( array( 'post_type' => Entries::CPT, 'post_status' => 'draft', 'post_title' => $q ), true );
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}
		update_post_meta( $id, '_alia_type', 'faq' );
		update_post_meta( $id, '_alia_questions', array( $q ) );
		wp_safe_redirect( get_edit_post_link( $id, 'raw' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = Settings::all();
		$n = Settings::OPTION;
		$t = static function ( $key, $label, $help = '', $type = 'text' ) use ( $o, $n ) {
			echo '<tr><th scope="row"><label for="alia-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="' . esc_attr( $type ) . '" class="regular-text" id="alia-' . esc_attr( $key ) . '" name="' . esc_attr( $n ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $o[ $key ] ) . '">';
			if ( $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		};
		$a = static function ( $key, $label, $help = '' ) use ( $o, $n ) {
			echo '<tr><th scope="row"><label for="alia-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><textarea class="large-text" rows="6" id="alia-' . esc_attr( $key ) . '" name="' . esc_attr( $n ) . '[' . esc_attr( $key ) . ']">' . esc_textarea( (string) $o[ $key ] ) . '</textarea>';
			if ( $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		};
		echo '<div class="wrap"><h1>' . esc_html__( 'Alia settings', 'travelstore-alia' ) . '</h1>';
		if ( isset( $_GET['seeded'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Seed import finished. Entries that already existed were left alone.', 'travelstore-alia' ) . '</p></div>';
		}
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( 'alia_settings_group' );

		echo '<h2>' . esc_html__( 'Contact details', 'travelstore-alia' ) . '</h2><p>' . esc_html__( 'These are PLACEHOLDERS until you enter the real ones. Alia uses them for the call, WhatsApp, email and contact-page buttons. An empty field hides that button.', 'travelstore-alia' ) . '</p><table class="form-table" role="presentation">';
		$t( 'phone', __( 'Phone number', 'travelstore-alia' ), __( 'Shown as written, used for the call button.', 'travelstore-alia' ) );
		$t( 'whatsapp', __( 'WhatsApp number', 'travelstore-alia' ), __( 'With the country code, digits only, for example 96874100010.', 'travelstore-alia' ) );
		$t( 'wa_text', __( 'First WhatsApp message', 'travelstore-alia' ) );
		$t( 'email', __( 'Team email', 'travelstore-alia' ), '', 'email' );
		$t( 'contact_url', __( 'Contact page address', 'travelstore-alia' ), __( 'A full address, or a path such as /contact/.', 'travelstore-alia' ) );
		$t( 'tour_index_url', __( 'Tours page address', 'travelstore-alia' ), __( 'Used by price answers when no specific tour page applies.', 'travelstore-alia' ) );
		$t( 'privacy_url', __( 'Privacy page address', 'travelstore-alia' ) );
		$t( 'visa_url', __( 'Official visa website', 'travelstore-alia' ) );
		$t( 'visa_label', __( 'Visa link label', 'travelstore-alia' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'How sure Alia must be', 'travelstore-alia' ) . '</h2><table class="form-table" role="presentation">';
		$t( 'th_confident', __( 'Confident above', 'travelstore-alia' ), __( 'Between 0.05 and 1. At or above this, Alia answers.', 'travelstore-alia' ) );
		$t( 'th_ambiguous', __( 'Unsure above', 'travelstore-alia' ), __( 'Between this and "Confident" Alia asks "Did you mean...?". Below it she uses the fallback.', 'travelstore-alia' ) );
		$t( 'th_margin', __( 'Margin between first and second', 'travelstore-alia' ), __( 'If two entries score closer than this, Alia asks which one.', 'travelstore-alia' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Trip planner', 'travelstore-alia' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Planner', 'travelstore-alia' ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $n ) . '[plan_on]" value="yes"' . checked( 'yes', $o['plan_on'], false ) . '> ' . esc_html__( 'Offer trip ideas ("Plan a trip")', 'travelstore-alia' ) . '</label></td></tr>';
		$t( 'w_interest', __( 'Weight: interests', 'travelstore-alia' ), __( 'How much a matching interest counts (0 to 10).', 'travelstore-alia' ), 'number' );
		$t( 'w_pace', __( 'Weight: pace', 'travelstore-alia' ), '', 'number' );
		$t( 'w_group', __( 'Weight: who is travelling', 'travelstore-alia' ), '', 'number' );
		$t( 'w_season', __( 'Weight: time of year', 'travelstore-alia' ), '', 'number' );
		$t( 'min_fit', __( 'Lowest fit to include a stop', 'travelstore-alia' ), __( 'Between 0 and 1.', 'travelstore-alia' ), 'number' );
		$t( 'night_tol', __( 'Nights more than asked (tolerance)', 'travelstore-alia' ), '', 'number' );
		$t( 'remote_route', __( 'Far-away stops start at route position', 'travelstore-alia' ), __( 'Stops with a route number at or above this are only suggested on longer trips.', 'travelstore-alia' ), 'number' );
		$t( 'remote_min_nights', __( 'Fewest nights for far-away stops', 'travelstore-alia' ), '', 'number' );
		echo '<tr><th scope="row"><label for="alia-consent_text">' . esc_html__( 'Consent line on the "Send to our team" form', 'travelstore-alia' ) . '</label></th><td><textarea class="large-text" rows="3" id="alia-consent_text" name="' . esc_attr( $n ) . '[consent_text]">' . esc_textarea( (string) $o['consent_text'] ) . '</textarea><p class="description">' . esc_html__( 'Requests arrive in Enquiries as "Alia trip idea" and are emailed to the Enquiries office address. A link to the privacy page is added when one is set above.', 'travelstore-alia' ) . '</p></td></tr></table>';

		echo '<h2>' . esc_html__( 'Brain', 'travelstore-alia' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Brain', 'travelstore-alia' ) . '</th><td>';
		echo '<select disabled><option>' . esc_html__( 'Knowledge-only (current)', 'travelstore-alia' ) . '</option><option>' . esc_html__( 'AI assistant (not available)', 'travelstore-alia' ) . '</option></select>';
		echo '<p class="description">' . esc_html__( 'Alia answers only from the entries you write. An AI option is planned as a separate decision (no AI is built or connected). The rules for politics, prices, links and visa notes would apply to it unchanged.', 'travelstore-alia' ) . '</p></td></tr></table>';

		echo '<h2>' . esc_html__( 'Privacy and limits', 'travelstore-alia' ) . '</h2><table class="form-table" role="presentation">';
		$t( 'stale_months', __( 'Entries count as stale after (months)', 'travelstore-alia' ), __( 'Used by Alia > Stale entries.', 'travelstore-alia' ), 'number' );
		$t( 'retention_days', __( 'Keep logged questions for (days)', 'travelstore-alia' ), __( 'Questions are stored without emails or phone numbers and removed after this many days.', 'travelstore-alia' ), 'number' );
		$t( 'rate_per_min', __( 'Messages per visitor per minute', 'travelstore-alia' ), '', 'number' );
		$t( 'max_chars', __( 'Longest message (characters)', 'travelstore-alia' ), '', 'number' );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Word lists (one per line)', 'travelstore-alia' ) . '</h2><table class="form-table" role="presentation">';
		$a( 'synonyms', __( 'Synonyms', 'travelstore-alia' ), __( 'Words that mean the same, separated by "=", for example: wahiba = sharqiya = desert camp.', 'travelstore-alia' ) );
		$a( 'politics', __( 'Politics and conflict words', 'travelstore-alia' ), __( 'A message containing one of these gets a polite decline.', 'travelstore-alia' ) );
		$a( 'politics_allow', __( 'Allowed phrases', 'travelstore-alia' ), __( 'Phrases that contain a political word but are travel questions, for example "sultan qaboos grand mosque".', 'travelstore-alia' ) );
		$a( 'safety_intent', __( 'Safety words', 'travelstore-alia' ), __( 'Politics word + a safety word = the neutral safety response.', 'travelstore-alia' ) );
		$a( 'safety_now', __( '"Right now" words', 'travelstore-alia' ), __( 'Safety word + one of these = the neutral safety response.', 'travelstore-alia' ) );
		$a( 'price', __( 'Price words', 'travelstore-alia' ), __( 'Alia never states prices. These words trigger the price response.', 'travelstore-alia' ) );
		$a( 'frustration', __( 'Complaint words', 'travelstore-alia' ), __( 'Alia says sorry, then shows the contact options.', 'travelstore-alia' ) );
		$a( 'human', __( '"Talk to a person" words', 'travelstore-alia' ), __( 'Alia shows the contact options at once, without an apology.', 'travelstore-alia' ) );
		$a( 'starters', __( 'Starter buttons', 'travelstore-alia' ) );
		$a( 'openers', __( 'Friendly openers', 'travelstore-alia' ) );
		echo '</table>';
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Maintenance', 'travelstore-alia' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		wp_nonce_field( 'alia_reseed' );
		echo '<input type="hidden" name="action" value="alia_reseed">';
		submit_button( __( 'Import Batch 1 (adds missing entries only)', 'travelstore-alia' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
		wp_nonce_field( 'alia_purge_now' );
		echo '<input type="hidden" name="action" value="alia_purge_now">';
		submit_button( __( 'Delete logged questions older than the retention period now', 'travelstore-alia' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	public static function reseed() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'alia_reseed' ) ) {
			wp_die( 'Not allowed.' );
		}
		Seed::import();
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Entries::CPT . '&page=' . Settings::PAGE . '&seeded=1' ) );
		exit;
	}

	public static function purge_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'alia_purge_now' ) ) {
			wp_die( 'Not allowed.' );
		}
		Log::purge();
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Entries::CPT . '&page=' . Settings::PAGE ) );
		exit;
	}
}
