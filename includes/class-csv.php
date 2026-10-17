<?php
/**
 * CSV export / import of the knowledge base (spec 10). UTF-8 with a BOM, so Excel opens it correctly.
 *
 * Import is always: upload > dry run (nothing written) > review > apply.
 * Rules: never deletes. New rows become DRAFT entries. Changed rows are written and set back to DRAFT, so the
 * team re-checks and republishes them. Rows with errors are skipped. Matching is by the `id` column.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Csv {

	const PAGE     = 'alia-csv';
	const MAX_ROWS = 2000;
	const SEP      = ' | ';

	public static function columns() {
		return array(
			'id', 'type', 'status', 'title', 'policy_key', 'questions', 'keywords', 'answer', 'detail', 'volatile', 'verified', 'link', 'image',
			'followups', 'flags', 'use_policy', 'regions', 'tags', 'nights_min', 'nights_max', 'interests', 'paces', 'suitable', 'months', 'route', 'arrival', 'note',
		);
	}

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_alia_csv_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_alia_csv_preview', array( __CLASS__, 'preview' ) );
		add_action( 'admin_post_alia_csv_apply', array( __CLASS__, 'apply' ) );
	}

	public static function menu() {
		add_submenu_page( Admin::PARENT, __( 'Import / export', 'travelstore-alia' ), __( 'Import / export', 'travelstore-alia' ), Plugin::CAP, self::PAGE, array( __CLASS__, 'page' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	/** @return array[] list of rows keyed by column name */
	public static function rows() {
		$ids = get_posts(
			array(
				'post_type'      => Entries::CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = self::row_of( (int) $id );
		}

		return $out;
	}

	private static function row_of( $id ) {
		$d = Entries::data( $id );
		$p = $d['plan'];
		$j = static function ( $a ) {
			return implode( self::SEP, array_map( 'strval', (array) $a ) );
		};

		return array(
			'id'         => $id,
			'type'       => $d['type'],
			'status'     => $d['status'],
			'title'      => $d['title'],
			'policy_key' => $d['policy'],
			'questions'  => $j( $d['questions'] ),
			'keywords'   => $j( $d['keywords'] ),
			'answer'     => $d['answer'],
			'detail'     => $d['detail'],
			'volatile'   => $d['volatile'] ? 'yes' : 'no',
			'verified'   => $d['verified'],
			'link'       => $d['link'],
			'image'      => $d['image'],
			'followups'  => $j( $d['followups'] ),
			'flags'      => $j( $d['flags'] ),
			'use_policy' => $d['use'],
			'regions'    => $j( $d['regions'] ),
			'tags'       => $j( $d['tags'] ),
			'nights_min' => (string) ( $p['nights_min'] ?? '' ),
			'nights_max' => (string) ( $p['nights_max'] ?? '' ),
			'interests'  => $j( $p['interests'] ?? array() ),
			'paces'      => $j( $p['paces'] ?? array() ),
			'suitable'   => $j( $p['suitable'] ?? array() ),
			'months'     => $j( $p['months'] ?? array() ),
			'route'      => (string) ( $p['route'] ?? '' ),
			'arrival'    => ! empty( $p['arrival'] ) ? 'yes' : 'no',
			'note'       => (string) ( $p['note'] ?? '' ),
		);
	}

	/** Spreadsheet formula injection: a cell that starts with = + - @ would be run by Excel. */
	private static function safe_cell( $v ) {
		$v = (string) $v;

		return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
	}

	public static function export() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_admin_referer( 'alia_csv_export' ) ) {
			wp_die( 'Not allowed.' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="alia-entries-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, self::columns() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( self::rows() as $r ) {
			$line = array();
			foreach ( self::columns() as $c ) {
				$line[] = self::safe_cell( $r[ $c ] );
			}
			fputcsv( $out, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Import: parse and check (dry run)                                   */
	/* ------------------------------------------------------------------ */

	/** @return array|\WP_Error list of associative rows (lower-case column names) */
	public static function parse_file( $path ) {
		$h = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $h ) {
			return new \WP_Error( 'alia_csv', __( 'The file could not be read.', 'travelstore-alia' ) );
		}
		$first = fgets( $h );
		if ( false === $first ) {
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			return new \WP_Error( 'alia_csv', __( 'The file is empty.', 'travelstore-alia' ) );
		}
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
		$delim = ',';
		foreach ( array( ';', "\t" ) as $d ) {
			if ( substr_count( $first, $d ) > substr_count( $first, $delim ) ) {
				$delim = $d;
			}
		}
		$header = array_map( static function ( $v ) {
			return strtolower( trim( (string) $v ) );
		}, str_getcsv( $first, $delim ) );
		$known = self::columns();
		if ( ! in_array( 'title', $header, true ) || ! in_array( 'type', $header, true ) ) {
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			return new \WP_Error( 'alia_csv', __( 'The first row must be a header with at least the columns "type" and "title".', 'travelstore-alia' ) );
		}
		$rows = array();
		$n    = 0;
		while ( false !== ( $line = fgetcsv( $h, 0, $delim ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( null === $line || array( null ) === $line || '' === trim( implode( '', $line ) ) ) {
				continue;
			}
			if ( ++$n > self::MAX_ROWS ) {
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions

				return new \WP_Error( 'alia_csv', sprintf( __( 'Too many rows (the limit is %d).', 'travelstore-alia' ), self::MAX_ROWS ) );
			}
			$r = array();
			foreach ( $header as $i => $name ) {
				if ( in_array( $name, $known, true ) ) {
					$r[ $name ] = isset( $line[ $i ] ) ? (string) $line[ $i ] : '';
				}
			}
			$rows[] = $r;
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $rows;
	}

	private static function split( $v ) {
		$v = (string) $v;
		if ( '' === trim( $v ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', preg_split( '/\s*\|\s*/', $v ) ), 'strlen' ) );
	}

	private static function yes( $v ) {
		return in_array( strtolower( trim( (string) $v ) ), array( 'yes', 'y', '1', 'true' ), true );
	}

	/**
	 * Validate one row and normalise it. Returns [ 'ok' => bool, 'data' => normalised, 'errors' => [], 'warnings' => [] ].
	 */
	public static function check( array $r ) {
		$err  = array();
		$warn = array();
		$get  = static function ( $k ) use ( $r ) {
			$v = isset( $r[ $k ] ) ? (string) $r[ $k ] : '';

			return preg_replace( "/^'(?=[=+\-@])/", '', $v ); // undo the export's formula guard.
		};
		$type  = strtolower( trim( $get( 'type' ) ) );
		$title = trim( sanitize_text_field( $get( 'title' ) ) );
		if ( ! isset( Entries::types()[ $type ] ) ) {
			$err[] = sprintf( __( 'Unknown type "%s".', 'travelstore-alia' ), $type );
		}
		if ( '' === $title ) {
			$err[] = __( 'Title is empty.', 'travelstore-alia' );
		}
		$policy = sanitize_key( $get( 'policy_key' ) );
		if ( '' !== $policy && ! isset( Entries::policy_keys()[ $policy ] ) ) {
			$err[] = sprintf( __( 'Unknown policy key "%s".', 'travelstore-alia' ), $policy );
		}
		if ( 'policy' === $type && '' === $policy ) {
			$err[] = __( 'A policy entry needs a policy key.', 'travelstore-alia' );
		}
		$flags = array_map( 'sanitize_key', self::split( $get( 'flags' ) ) );
		foreach ( $flags as $f ) {
			if ( ! isset( Entries::flags()[ $f ] ) ) {
				$err[] = sprintf( __( 'Unknown flag "%s".', 'travelstore-alia' ), $f );
			}
		}
		$use = sanitize_key( $get( 'use_policy' ) );
		if ( '' !== $use && ! in_array( $use, array( 'price', 'safety' ), true ) ) {
			$err[] = sprintf( __( 'Unknown "use_policy" value "%s".', 'travelstore-alia' ), $use );
		}
		$verified = trim( $get( 'verified' ) );
		if ( '' !== $verified && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $verified ) ) {
			$err[] = __( '"verified" must be a date like 2026-10-15.', 'travelstore-alia' );
		}
		$vol = self::yes( $get( 'volatile' ) );
		if ( $vol && '' === $verified ) {
			$err[] = __( 'A volatile entry needs a "verified" date.', 'travelstore-alia' );
		}
		$ints = array_values( array_intersect( array_keys( Planner::interests() ), array_map( 'sanitize_key', self::split( $get( 'interests' ) ) ) ) );
		$pcs  = array_values( array_intersect( array_keys( Planner::paces() ), array_map( 'sanitize_key', self::split( $get( 'paces' ) ) ) ) );
		$sui  = array_values( array_intersect( array_keys( Planner::who() ), array_map( 'sanitize_key', self::split( $get( 'suitable' ) ) ) ) );
		$mon  = array_values( array_filter( array_map( 'intval', self::split( $get( 'months' ) ) ), static function ( $m ) {
			return $m >= 1 && $m <= 12;
		} ) );
		$answer = sanitize_textarea_field( $get( 'answer' ) );
		$detail = sanitize_textarea_field( $get( 'detail' ) );
		if ( mb_strlen( $answer ) > 3000 || mb_strlen( $detail ) > 6000 ) {
			$err[] = __( 'Answer or detail is far too long.', 'travelstore-alia' );
		}
		if ( ! in_array( 'money_general', $flags, true ) && Guardrails::has_price( $answer . ' ' . $detail ) ) {
			$warn[] = __( 'Looks like it contains a price. Alia must not state prices.', 'travelstore-alia' );
		}
		if ( '' === trim( $answer ) && '' === $use && 'policy' !== $type && 'day_block' !== $type ) {
			$warn[] = __( 'The answer is empty.', 'travelstore-alia' );
		}
		$link = trim( $get( 'link' ) );
		$link = ( '' === $link || 0 === strpos( $link, '/' ) || preg_match( '/^page:\d+$/', $link ) ) ? sanitize_text_field( $link ) : esc_url_raw( $link );
		$nmin = max( 1, min( 14, (int) ( $get( 'nights_min' ) ?: 1 ) ) );

		$data = array(
			'type'       => $type,
			'title'      => $title,
			'policy'     => $policy,
			'questions'  => array_map( 'sanitize_text_field', self::split( $get( 'questions' ) ) ),
			'keywords'   => array_map( 'sanitize_text_field', self::split( $get( 'keywords' ) ) ),
			'answer'     => $answer,
			'detail'     => $detail,
			'volatile'   => $vol,
			'verified'   => $verified,
			'link'       => $link,
			'image'      => esc_url_raw( $get( 'image' ) ),
			'followups'  => array_map( 'sanitize_text_field', self::split( $get( 'followups' ) ) ),
			'flags'      => array_values( array_intersect( array_keys( Entries::flags() ), $flags ) ),
			'use'        => $use,
			'regions'    => array_map( 'sanitize_title', self::split( $get( 'regions' ) ) ),
			'tags'       => array_map( 'sanitize_text_field', self::split( $get( 'tags' ) ) ),
			'plan'       => array(
				'nights_min' => $nmin,
				'nights_max' => max( $nmin, min( 14, (int) ( $get( 'nights_max' ) ?: $nmin ) ) ),
				'interests'  => $ints,
				'paces'      => $pcs,
				'suitable'   => $sui,
				'months'     => $mon,
				'route'      => max( 0, min( 999, (int) ( '' !== $get( 'route' ) ? $get( 'route' ) : 50 ) ) ),
				'arrival'    => self::yes( $get( 'arrival' ) ) ? 1 : 0,
				'note'       => sanitize_text_field( $get( 'note' ) ),
			),
		);

		return array( 'ok' => ! $err, 'data' => $data, 'errors' => $err, 'warnings' => $warn );
	}

	/** Compare a normalised row with the stored entry; returns the names of changed fields. */
	private static function changes( $id, array $new ) {
		$old = Entries::data( $id );
		$chg = array();
		$cmp = array( 'type', 'title', 'policy', 'questions', 'keywords', 'answer', 'detail', 'volatile', 'verified', 'link', 'image', 'followups', 'flags', 'use' );
		foreach ( $cmp as $k ) {
			$a = $old[ $k ];
			$b = $new[ $k ];
			if ( is_array( $a ) ) {
				sort( $a );
				sort( $b );
			}
			if ( $a != $b ) { // phpcs:ignore WordPress.PHP.StrictComparisons
				$chg[] = $k;
			}
		}
		$op = $old['plan'];
		$np = $new['plan'];
		if ( ( 'day_block' === $new['type'] || 'activity' === $new['type'] ) ) {
			foreach ( $np as $k => $v ) {
				$ov = $op[ $k ] ?? null;
				if ( is_array( $v ) ) {
					$o2 = (array) $ov;
					sort( $o2 );
					$v2 = $v;
					sort( $v2 );
					if ( array_map( 'strval', $o2 ) !== array_map( 'strval', $v2 ) ) {
						$chg[] = 'plan:' . $k;
					}
				} elseif ( (string) $ov !== (string) $v ) {
					$chg[] = 'plan:' . $k;
				}
			}
		}
		foreach ( array( 'regions', 'tags' ) as $k ) {
			$a = $old[ $k ];
			$b = $new[ $k ];
			sort( $a );
			sort( $b );
			if ( $a !== $b ) {
				$chg[] = $k;
			}
		}

		return $chg;
	}

	/** Dry run over parsed rows. @return array[] per row: n, action, id, title, changes[], errors[], warnings[], data */
	public static function plan( array $rows ) {
		$out = array();
		$n   = 0;
		foreach ( $rows as $r ) {
			++$n;
			$c   = self::check( $r );
			$id  = isset( $r['id'] ) && ctype_digit( trim( (string) $r['id'] ) ) ? (int) $r['id'] : 0;
			$row = array( 'n' => $n, 'id' => $id, 'title' => $c['data']['title'], 'errors' => $c['errors'], 'warnings' => $c['warnings'], 'changes' => array(), 'data' => $c['data'], 'action' => 'error' );
			if ( $c['ok'] ) {
				if ( $id && Entries::CPT === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
					$row['changes'] = self::changes( $id, $c['data'] );
					$row['action']  = $row['changes'] ? 'changed' : 'unchanged';
				} elseif ( $id ) {
					$row['errors'][] = sprintf( __( 'There is no entry with id %d.', 'travelstore-alia' ), $id );
					$row['action']   = 'error';
				} else {
					$row['action'] = 'new';
				}
			}
			$out[] = $row;
		}

		return $out;
	}

	private static function transient_key( $token ) {
		return 'alia_csv_' . get_current_user_id() . '_' . preg_replace( '/[^a-f0-9]/', '', (string) $token );
	}

	public static function preview() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_admin_referer( 'alia_csv_preview' ) ) {
			wp_die( 'Not allowed.' );
		}
		$back = array( 'post_type' => Entries::CPT, 'page' => self::PAGE );
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) || (int) $_FILES['csv']['size'] > 5 * MB_IN_BYTES ) { // phpcs:ignore
			wp_safe_redirect( add_query_arg( $back + array( 'err' => 'nofile' ), admin_url( 'edit.php' ) ) );
			exit;
		}
		$rows = self::parse_file( $_FILES['csv']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $rows ) ) {
			set_transient( 'alia_csv_err_' . get_current_user_id(), $rows->get_error_message(), 300 );
			wp_safe_redirect( add_query_arg( $back + array( 'err' => 'parse' ), admin_url( 'edit.php' ) ) );
			exit;
		}
		$token = wp_generate_password( 16, false, false );
		$token = strtolower( preg_replace( '/[^a-z0-9]/i', '', $token ) );
		$token = substr( md5( $token . microtime() ), 0, 16 );
		set_transient( self::transient_key( $token ), self::plan( $rows ), 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( $back + array( 'token' => $token ), admin_url( 'edit.php' ) ) );
		exit;
	}

	/** Write one normalised row. @return int post id */
	public static function write( $id, array $d ) {
		$new = 0 === (int) $id;
		if ( $new ) {
			$id = wp_insert_post( array( 'post_type' => Entries::CPT, 'post_status' => 'draft', 'post_title' => $d['title'] ), true );
			if ( is_wp_error( $id ) ) {
				return 0;
			}
		} else {
			wp_update_post( array( 'ID' => $id, 'post_title' => $d['title'], 'post_status' => 'draft' ) );
		}
		$old_ver = (string) get_post_meta( $id, '_alia_verified', true );
		update_post_meta( $id, '_alia_type', $d['type'] );
		update_post_meta( $id, '_alia_policy_key', $d['policy'] );
		update_post_meta( $id, '_alia_questions', $d['questions'] );
		update_post_meta( $id, '_alia_keywords', implode( ', ', $d['keywords'] ) );
		update_post_meta( $id, '_alia_answer', $d['answer'] );
		update_post_meta( $id, '_alia_detail', $d['detail'] );
		update_post_meta( $id, '_alia_volatile', $d['volatile'] ? '1' : '0' );
		update_post_meta( $id, '_alia_verified', $d['verified'] );
		if ( '' !== $d['verified'] && $d['verified'] !== $old_ver ) {
			delete_post_meta( $id, Stale::SEED_FLAG );
		}
		update_post_meta( $id, '_alia_link', $d['link'] );
		update_post_meta( $id, '_alia_image', $d['image'] );
		update_post_meta( $id, '_alia_followups', $d['followups'] );
		update_post_meta( $id, '_alia_flags', $d['flags'] );
		update_post_meta( $id, '_alia_use_policy', $d['use'] );
		if ( in_array( $d['type'], array( 'day_block', 'activity' ), true ) ) {
			update_post_meta( $id, '_alia_plan', $d['plan'] );
		}
		wp_set_object_terms( $id, $d['regions'], Entries::REGION );
		wp_set_object_terms( $id, $d['tags'], Entries::TAG );

		return (int) $id;
	}

	public static function apply() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_admin_referer( 'alia_csv_apply' ) ) {
			wp_die( 'Not allowed.' );
		}
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$plan  = get_transient( self::transient_key( $token ) );
		$back  = array( 'post_type' => Entries::CPT, 'page' => self::PAGE );
		if ( ! is_array( $plan ) || empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( add_query_arg( $back + array( 'err' => 'expired' ), admin_url( 'edit.php' ) ) );
			exit;
		}
		$res = self::apply_plan( $plan );
		delete_transient( self::transient_key( $token ) );
		wp_safe_redirect( add_query_arg( $back + array( 'done' => $res['new'] . '-' . $res['changed'] . '-' . $res['skipped'] ), admin_url( 'edit.php' ) ) );
		exit;
	}

	/** @return array{new:int,changed:int,skipped:int} */
	public static function apply_plan( array $plan ) {
		$res = array( 'new' => 0, 'changed' => 0, 'skipped' => 0 );
		Index::$suspended = true;
		foreach ( $plan as $row ) {
			if ( 'new' === $row['action'] ) {
				if ( self::write( 0, $row['data'] ) ) {
					++$res['new'];
				}
			} elseif ( 'changed' === $row['action'] ) {
				self::write( (int) $row['id'], $row['data'] );
				++$res['changed'];
			} else {
				++$res['skipped'];
			}
		}
		Index::$suspended = false;
		Index::rebuild();

		return $res;
	}

	/* ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$g = static function ( $k ) {
			return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		};
		echo '<div class="wrap"><h1>' . esc_html__( 'Import / export', 'travelstore-alia' ) . '</h1>';
		if ( $g( 'done' ) ) {
			list( $n, $c, $s ) = array_pad( array_map( 'intval', explode( '-', $g( 'done' ) ) ), 3, 0 );
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Import finished: %1$d new entries added as drafts, %2$d entries changed and set back to draft, %3$d rows left alone. Review the drafts and publish them when you are happy.', 'travelstore-alia' ), $n, $c, $s ) ) . '</p></div>';
		}
		if ( $g( 'err' ) ) {
			$msg = array(
				'nofile'  => __( 'No file was uploaded (or it is larger than 5 MB).', 'travelstore-alia' ),
				'expired' => __( 'The review has expired or the confirmation box was not ticked. Please upload the file again.', 'travelstore-alia' ),
				'parse'   => (string) get_transient( 'alia_csv_err_' . get_current_user_id() ),
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $msg[ $g( 'err' ) ] ?? __( 'Something went wrong.', 'travelstore-alia' ) ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( '1. Export', 'travelstore-alia' ) . '</h2><p>' . esc_html__( 'Downloads every entry (published and draft) as a spreadsheet file you can open in Excel. Lists inside a cell are separated by " | ".', 'travelstore-alia' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'alia_csv_export' );
		echo '<input type="hidden" name="action" value="alia_csv_export">';
		submit_button( __( 'Download all entries (CSV)', 'travelstore-alia' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( '2. Import', 'travelstore-alia' ) . '</h2><p>' . esc_html__( 'Upload a CSV in the same format. Nothing is saved yet: you first see what would happen. Import never deletes entries. New rows become drafts. Rows that change an existing entry (matched by the "id" column) update it and set it back to draft so it is re-checked.', 'travelstore-alia' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'alia_csv_preview' );
		echo '<input type="hidden" name="action" value="alia_csv_preview"><input type="file" name="csv" accept=".csv,text/csv" required> ';
		submit_button( __( 'Check the file (nothing is saved)', 'travelstore-alia' ), 'secondary', 'submit', false );
		echo '</form>';

		$token = $g( 'token' );
		if ( '' !== $token ) {
			$plan = get_transient( self::transient_key( $token ) );
			if ( ! is_array( $plan ) ) {
				echo '<p>' . esc_html__( 'That review has expired. Please upload the file again.', 'travelstore-alia' ) . '</p></div>';

				return;
			}
			$count = array( 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'error' => 0 );
			foreach ( $plan as $r ) {
				++$count[ $r['action'] ];
			}
			echo '<h2>' . esc_html__( '3. Review', 'travelstore-alia' ) . '</h2>';
			echo '<p><strong>' . esc_html( sprintf( __( '%1$d new, %2$d changed, %3$d unchanged, %4$d with errors (skipped).', 'travelstore-alia' ), $count['new'], $count['changed'], $count['unchanged'], $count['error'] ) ) . '</strong></p>';
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>#</th><th>' . esc_html__( 'What happens', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Entry', 'travelstore-alia' ) . '</th><th>' . esc_html__( 'Details', 'travelstore-alia' ) . '</th></tr></thead><tbody>';
			$shown = 0;
			foreach ( $plan as $r ) {
				if ( 'unchanged' === $r['action'] ) {
					continue;
				}
				if ( ++$shown > 300 ) {
					break;
				}
				$label = array( 'new' => __( 'NEW (as draft)', 'travelstore-alia' ), 'changed' => __( 'CHANGED (back to draft)', 'travelstore-alia' ), 'error' => __( 'ERROR (skipped)', 'travelstore-alia' ) );
				$det   = array();
				if ( $r['changes'] ) {
					$det[] = esc_html__( 'Changed: ', 'travelstore-alia' ) . esc_html( implode( ', ', $r['changes'] ) );
				}
				foreach ( $r['errors'] as $e ) {
					$det[] = '<span style="color:#b32d2e">' . esc_html( $e ) . '</span>';
				}
				foreach ( $r['warnings'] as $w ) {
					$det[] = '<span style="color:#996800">' . esc_html__( 'Warning: ', 'travelstore-alia' ) . esc_html( $w ) . '</span>';
				}
				echo '<tr><td>' . (int) $r['n'] . '</td><td>' . esc_html( $label[ $r['action'] ] ?? $r['action'] ) . '</td><td>' . esc_html( $r['title'] ) . ( $r['id'] ? ' <small>(id ' . (int) $r['id'] . ')</small>' : '' ) . '</td><td>' . implode( '<br>', $det ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
			if ( $count['new'] + $count['changed'] > 0 ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
				wp_nonce_field( 'alia_csv_apply' );
				echo '<input type="hidden" name="action" value="alia_csv_apply"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
				echo '<p><label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'I have reviewed the list above and want to apply it.', 'travelstore-alia' ) . '</label></p>';
				submit_button( __( 'Apply the import', 'travelstore-alia' ), 'primary', 'submit', false );
				echo '</form>';
			}
		}
		echo '</div>';
	}
}
