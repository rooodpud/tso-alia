<?php
/**
 * Test cases (spec 10): saved "question -> expected outcome" checks, run on demand after knowledge-base
 * edits as a regression safety net. Stored as one option. Runs the real engine without logging.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cases {

	const OPTION = 'alia_cases';
	const PAGE   = 'alia-cases';

	/** Expected outcome kinds, in plain words. */
	public static function kinds() {
		return array(
			'answer'   => __( 'An answer from an entry', 'travelstore-alia' ),
			'ask'      => __( '"Did you mean...?" (two close entries)', 'travelstore-alia' ),
			'fallback' => __( 'Fallback with contact options', 'travelstore-alia' ),
			'politics' => __( 'Polite decline (politics)', 'travelstore-alia' ),
			'safety'   => __( 'Neutral safety response', 'travelstore-alia' ),
			'price'    => __( 'Price response (no price)', 'travelstore-alia' ),
			'handoff'  => __( 'Empathy + contact (talk to a person)', 'travelstore-alia' ),
			'planner'  => __( 'Trip planner starts', 'travelstore-alia' ),
			'greeting' => __( 'Greeting', 'travelstore-alia' ),
			'thanks'   => __( 'Reply to thanks', 'travelstore-alia' ),
			'offtopic' => __( 'Polite "outside what I know"', 'travelstore-alia' ),
			'contact'  => __( 'Contact details', 'travelstore-alia' ),
			'identity' => __( 'Says it is an automated assistant', 'travelstore-alia' ),
			'confused' => __( 'Asks the visitor to say more', 'travelstore-alia' ),
			'hotels'   => __( 'Hotel question passed to the team', 'travelstore-alia' ),
		);
	}

	/** Map an engine outcome to a case kind. */
	public static function kind_of( $outcome ) {
		$map = array(
			'confident' => 'answer', 'more' => 'answer', 'ambiguous' => 'ask', 'none' => 'fallback', 'politics' => 'politics',
			'safety' => 'safety', 'price' => 'price', 'handoff' => 'handoff', 'greeting' => 'greeting', 'thanks' => 'thanks',
			'plan_ask' => 'planner', 'plan' => 'planner', 'plan_nofit' => 'planner', 'plan_cancel' => 'planner',
			'off_topic' => 'offtopic',
		);

		return $map[ $outcome ] ?? (string) $outcome;
	}

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_alia_case_add', array( __CLASS__, 'add' ) );
		add_action( 'admin_post_alia_case_delete', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_alia_case_seed', array( __CLASS__, 'seed_action' ) );
	}

	public static function menu() {
		add_submenu_page( Admin::PARENT, __( 'Test cases', 'travelstore-alia' ), __( 'Test cases', 'travelstore-alia' ), Plugin::CAP, self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function all() {
		$c = get_option( self::OPTION, array() );

		return is_array( $c ) ? array_values( $c ) : array();
	}

	private static function save_all( array $cases ) {
		update_option( self::OPTION, array_values( $cases ), false );
	}

	/** Add the starter cases from data/test-cases.json (skips questions already present). */
	public static function seed() {
		$file = TSA_PATH . 'data/test-cases.json';
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		$rows  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$cases = self::all();
		$have  = array_map( static function ( $c ) {
			return strtolower( $c['q'] );
		}, $cases );
		$n = 0;
		foreach ( (array) $rows as $r ) {
			if ( empty( $r['q'] ) || empty( $r['expect'] ) || isset( array_flip( $have )[ strtolower( $r['q'] ) ] ) ) {
				continue;
			}
			$cases[] = array( 'id' => 'c' . substr( md5( $r['q'] ), 0, 8 ), 'q' => (string) $r['q'], 'expect' => (string) $r['expect'], 'entry' => 0, 'note' => (string) ( $r['note'] ?? '' ) );
			$have[]  = strtolower( $r['q'] );
			++$n;
		}
		self::save_all( $cases );

		return $n;
	}

	/** @return array[] case + actual + pass */
	public static function run() {
		$out = array();
		foreach ( self::all() as $c ) {
			$r      = Engine::respond( $c['q'], array(), array() );
			$kind   = self::kind_of( $r['outcome'] );
			$pass   = ( $kind === $c['expect'] );
			$reason = $pass ? '' : sprintf( /* translators: 1: expected 2: actual */ __( 'Expected %1$s, got %2$s', 'travelstore-alia' ), $c['expect'], $kind );
			if ( $pass && ! empty( $c['entry'] ) && (int) $c['entry'] !== (int) $r['entry'] ) {
				$pass   = false;
				$reason = sprintf( __( 'Answered with entry #%1$d, expected #%2$d', 'travelstore-alia' ), (int) $r['entry'], (int) $c['entry'] );
			}
			$out[] = array(
				'case'   => $c,
				'kind'   => $kind,
				'pass'   => $pass,
				'reason' => $reason,
				'reply'  => $r['chunks'][0]['text'] ?? '',
				'entry'  => (int) $r['entry'],
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */

	public static function add() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_admin_referer( 'alia_case_add' ) ) {
			wp_die( 'Not allowed.' );
		}
		$q      = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$expect = isset( $_POST['expect'] ) ? sanitize_key( wp_unslash( $_POST['expect'] ) ) : '';
		$entry  = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
		if ( '' !== $q && isset( self::kinds()[ $expect ] ) ) {
			$cases   = self::all();
			$cases[] = array( 'id' => 'c' . substr( md5( $q . microtime() ), 0, 8 ), 'q' => mb_substr( $q, 0, 300 ), 'expect' => $expect, 'entry' => $entry, 'note' => '' );
			self::save_all( $cases );
		}
		$back = isset( $_POST['back'] ) && 'test' === $_POST['back'] ? 'alia-test' : self::PAGE;
		wp_safe_redirect( add_query_arg( array( 'post_type' => Entries::CPT, 'page' => $back, 'added' => 1 ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function delete() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_admin_referer( 'alia_case_delete' ) ) {
			wp_die( 'Not allowed.' );
		}
		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$cases = array_filter( self::all(), static function ( $c ) use ( $id ) {
			return $c['id'] !== $id;
		} );
		self::save_all( $cases );
		wp_safe_redirect( add_query_arg( array( 'post_type' => Entries::CPT, 'page' => self::PAGE ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function seed_action() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_admin_referer( 'alia_case_seed' ) ) {
			wp_die( 'Not allowed.' );
		}
		$n = self::seed();
		wp_safe_redirect( add_query_arg( array( 'post_type' => Entries::CPT, 'page' => self::PAGE, 'seeded' => $n ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$kinds = self::kinds();
		$cases = self::all();
		echo '<div class="wrap"><h1>' . esc_html__( 'Test cases', 'travelstore-alia' ) . '</h1>';
		if ( isset( $_GET['seeded'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%d starter test cases added.', 'travelstore-alia' ), (int) $_GET['seeded'] ) ) . '</p></div>'; // phpcs:ignore
		}
		echo '<p>' . esc_html__( 'A test case is a question and what Alia should do with it. Run them all after you edit entries or word lists: a FAIL means something that used to work has changed.', 'travelstore-alia' ) . '</p>';

		// Run.
		$results = null;
		if ( isset( $_POST['alia_run'] ) && check_admin_referer( 'alia_case_run' ) ) {
			$results = self::run();
			$pass    = count( array_filter( $results, static function ( $r ) {
				return $r['pass'];
			} ) );
			$cls = $pass === count( $results ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $cls ) . '"><p><strong>' . esc_html( sprintf( __( '%1$d of %2$d test cases passed.', 'travelstore-alia' ), $pass, count( $results ) ) ) . '</strong></p></div>';
		}
		echo '<form method="post" style="margin:12px 0">';
		wp_nonce_field( 'alia_case_run' );
		submit_button( __( 'Run all test cases', 'travelstore-alia' ), 'primary', 'alia_run', false );
		echo '</form>';

		if ( $results ) {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Result', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Question', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Expected', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Got', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Alia said', 'travelstore-alia' ) . '</th></tr></thead><tbody>';
			foreach ( $results as $r ) {
				$row = $r['pass'] ? '' : ' style="background:#fcf0f1"';
				echo '<tr' . $row . '><td><strong>' . ( $r['pass'] ? 'PASS' : 'FAIL' ) . '</strong></td><td>' . esc_html( $r['case']['q'] ) . '</td><td>' . esc_html( $kinds[ $r['case']['expect'] ] ?? $r['case']['expect'] ) . '</td><td>' . esc_html( $kinds[ $r['kind'] ] ?? $r['kind'] ) . ( $r['reason'] ? '<br><em>' . esc_html( $r['reason'] ) . '</em>' : '' ) . '</td><td>' . esc_html( mb_substr( $r['reply'], 0, 110 ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Add.
		echo '<h2>' . esc_html__( 'Add a test case', 'travelstore-alia' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'alia_case_add' );
		echo '<input type="hidden" name="action" value="alia_case_add"><p><input type="text" name="q" class="regular-text" style="width:min(480px,100%)" maxlength="300" placeholder="' . esc_attr__( 'Question', 'travelstore-alia' ) . '" required> <select name="expect">';
		foreach ( $kinds as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Add', 'travelstore-alia' ), 'secondary', 'submit', false );
		echo '</p></form>';

		// List.
		echo '<h2>' . esc_html( sprintf( __( 'Saved test cases (%d)', 'travelstore-alia' ), count( $cases ) ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:12px">';
		wp_nonce_field( 'alia_case_seed' );
		echo '<input type="hidden" name="action" value="alia_case_seed">';
		submit_button( __( 'Add the starter test cases (adds missing ones only)', 'travelstore-alia' ), 'secondary', 'submit', false );
		echo '</form>';
		if ( $cases ) {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Question', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Expected', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Note', 'travelstore-alia' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $cases as $c ) {
				echo '<tr><td>' . esc_html( $c['q'] ) . '</td><td>' . esc_html( $kinds[ $c['expect'] ] ?? $c['expect'] ) . '</td><td>' . esc_html( (string) ( $c['note'] ?? '' ) ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'alia_case_delete' );
				echo '<input type="hidden" name="action" value="alia_case_delete"><input type="hidden" name="id" value="' . esc_attr( $c['id'] ) . '">';
				submit_button( __( 'Delete', 'travelstore-alia' ), 'small', 'submit', false );
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
