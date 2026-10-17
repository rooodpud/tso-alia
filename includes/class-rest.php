<?php
/**
 * REST endpoint: POST /wp-json/alia/v1/message, POST .../feedback, GET .../nonce.
 * Protection: nonce (a fresh one can be fetched, because cached pages carry stale ones), message length,
 * per-visitor rate limit (hashed IP in a transient), no personal data stored.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest {

	const NS     = 'alia/v1';
	const ACTION = 'alia_chat';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'nonce' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'message' ),
				'permission_callback' => array( __CLASS__, 'check_nonce' ),
			)
		);
		register_rest_route(
			self::NS,
			'/handoff',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handoff' ),
				'permission_callback' => array( __CLASS__, 'check_nonce' ),
			)
		);
		register_rest_route(
			self::NS,
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'feedback' ),
				'permission_callback' => array( __CLASS__, 'check_nonce' ),
			)
		);
	}

	public static function check_nonce( \WP_REST_Request $req ) {
		$n = (string) $req->get_header( 'X-Alia-Nonce' );
		if ( '' !== $n && wp_verify_nonce( $n, self::ACTION ) ) {
			return true;
		}

		return new \WP_Error( 'alia_nonce', 'expired', array( 'status' => 403 ) );
	}

	public static function nonce() {
		nocache_headers();

		return rest_ensure_response( array( 'nonce' => wp_create_nonce( self::ACTION ) ) );
	}

	private static function visitor_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return 'alia_rl_' . md5( $ip . wp_salt( 'nonce' ) );
	}

	/** @return bool true when the visitor is over the limit */
	private static function limited() {
		$key   = self::visitor_key();
		$limit = (int) Settings::num( 'rate_per_min', 20 );
		$n     = (int) get_transient( $key );
		if ( $n >= $limit ) {
			return true;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );

		return false;
	}

	public static function message( \WP_REST_Request $req ) {
		nocache_headers();
		if ( 'knowledge' !== Settings::get( 'brain' ) ) {
			return new \WP_Error( 'alia_off', 'unavailable', array( 'status' => 503 ) );
		}
		if ( self::limited() ) {
			$p = Index::policies();

			return rest_ensure_response(
				array(
					'outcome' => 'rate_limit',
					'chunks'  => array( array( 'text' => $p['rate_limit'][0]['text'] ?? "You're asking quickly. Give me a moment and try again.", 'links' => array() ) ),
					'quick_replies' => array(),
					'state'   => (array) $req->get_param( 'state' ),
					'feedback' => false,
				)
			);
		}
		$text  = (string) $req->get_param( 'text' );
		$state = self::clean_state( (array) $req->get_param( 'state' ) );
		$opts  = array();
		if ( $req->get_param( 'more' ) ) {
			$opts['more'] = (int) $req->get_param( 'more' );
		}
		if ( $req->get_param( 'pick' ) ) {
			$opts['pick'] = (int) $req->get_param( 'pick' );
		}
		$planop = self::clean_plan_op( $req->get_param( 'plan' ) );
		if ( $planop ) {
			$opts['plan'] = $planop;
		}
		$r = Engine::respond( $text, $state, $opts );

		$sid    = (string) $req->get_param( 'sid' );
		$label  = '' !== $text ? $text : ( isset( $opts['pick'] ) ? '[pick ' . (int) $opts['pick'] . ']' : ( isset( $opts['plan'] ) ? '[plan ' . $opts['plan']['op'] . ' ' . $opts['plan']['slot'] . ']' : '[more]' ) );
		$log_id = Log::record( $sid, $label, $r['entry'], $r['score'], $r['outcome'] );
		unset( $r['trace'] );
		if ( $r['feedback'] && $log_id ) {
			$r['log'] = array( 'id' => $log_id, 'token' => Log::token( $log_id ) );
		}

		return rest_ensure_response( $r );
	}

	public static function feedback( \WP_REST_Request $req ) {
		nocache_headers();
		$id = (int) $req->get_param( 'log' );
		if ( $id <= 0 || ! hash_equals( Log::token( $id ), (string) $req->get_param( 'token' ) ) ) {
			return new \WP_Error( 'alia_bad', 'invalid', array( 'status' => 400 ) );
		}
		Log::feedback( $id, (int) $req->get_param( 'vote' ) );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Session state arrives from the browser, so only known keys of the right type are kept. */
	private static function clean_state( array $s ) {
		return array(
			'dest'    => isset( $s['dest'] ) ? sanitize_key( $s['dest'] ) : '',
			'topic'   => isset( $s['topic'] ) ? (int) $s['topic'] : 0,
			'n'       => isset( $s['n'] ) ? max( 0, min( 500, (int) $s['n'] ) ) : 0,
			'greeted' => ! empty( $s['greeted'] ) ? 1 : 0,
			'pv'      => isset( $s['pv'] ) ? (int) $s['pv'] : -1,
			'op'      => isset( $s['op'] ) ? (int) $s['op'] : -1,
			'plan'    => Handoff::clean_plan( isset( $s['plan'] ) && is_array( $s['plan'] ) ? $s['plan'] : array() ),
		);
	}

	/** A planner operation from a tapped chip, whitelisted. */
	private static function clean_plan_op( $op ) {
		if ( ! is_array( $op ) ) {
			return null;
		}
		$kind = isset( $op['op'] ) ? (string) $op['op'] : '';
		if ( ! in_array( $kind, array( 'start', 'set', 'toggle', 'done', 'ask', 'change', 'build', 'reset', 'resume' ), true ) ) {
			return null;
		}
		$slot = isset( $op['slot'] ) ? (string) $op['slot'] : '';
		$val  = $op['value'] ?? '';
		if ( is_array( $val ) ) {
			$val = array_values( array_filter( array_map( 'sanitize_key', array_slice( $val, 0, 10 ) ) ) );
		} else {
			$val = sanitize_text_field( (string) $val );
		}

		return array( 'op' => $kind, 'slot' => in_array( $slot, Planner::SLOTS, true ) ? $slot : '', 'value' => $val );
	}

	public static function handoff( \WP_REST_Request $req ) {
		nocache_headers();
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$res = Handoff::process( (array) $req->get_json_params(), $ip . wp_salt( 'nonce' ) );
		if ( 'received' === $res['code'] ) {
			Log::record( (string) $req->get_param( 'sid' ), '[hand-off form sent]', 0, 0, 'handoff' );

			return rest_ensure_response( $res );
		}
		$status = 'invalid' === $res['code'] ? 422 : ( 'wait' === $res['code'] ? 429 : 400 );

		return new \WP_REST_Response( $res, $status );
	}
}
