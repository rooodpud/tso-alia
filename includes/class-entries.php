<?php
/**
 * The knowledge base: custom post type `alia_entry`, its two taxonomies, and the editor (native meta boxes).
 * Standard WordPress status (draft / published) and revisions apply.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entries {

	const CPT      = 'alia_entry';
	const REGION   = 'alia_region';
	const TAG      = 'alia_tag';
	const NONCE    = 'alia_entry_save';
	const WARN_KEY = 'alia_entry_warnings_';

	/** Entry types (spec 5.2). */
	public static function types() {
		return array(
			'faq'         => __( 'FAQ', 'travelstore-alia' ),
			'destination' => __( 'Destination', 'travelstore-alia' ),
			'activity'    => __( 'Activity', 'travelstore-alia' ),
			'day_block'   => __( 'Day block (Phase 2)', 'travelstore-alia' ),
			'policy'      => __( 'Policy / template', 'travelstore-alia' ),
		);
	}

	/** Policy keys: system messages Alia uses (spec 5.2 item 5). */
	public static function policy_keys() {
		return array(
			'greeting'         => __( 'Greeting', 'travelstore-alia' ),
			'fallback'         => __( 'Fallback: question not covered', 'travelstore-alia' ),
			'price'            => __( 'Price response', 'travelstore-alia' ),
			'visa_caveat'      => __( 'Visa caveat', 'travelstore-alia' ),
			'politics_decline' => __( 'Politics / conflict decline (one entry per wording; they rotate)', 'travelstore-alia' ),
			'safety'           => __( 'Travel-safety response (no opinion)', 'travelstore-alia' ),
			'empathy'          => __( 'Empathy line (one entry per wording)', 'travelstore-alia' ),
			'volatile_note'    => __( 'Volatile note ("please verify")', 'travelstore-alia' ),
			'thanks'           => __( 'Reply to thanks', 'travelstore-alia' ),
			'goodbye'          => __( 'Reply to goodbye', 'travelstore-alia' ),
			'too_long'         => __( 'Message too long', 'travelstore-alia' ),
			'rate_limit'       => __( 'Slow down message', 'travelstore-alia' ),
		);
	}

	public static function flags() {
		return array(
			'visa'          => __( 'Visa: add the visa caveat and the official links', 'travelstore-alia' ),
			'money_general' => __( 'General money fact (currency, tipping, cards): may be answered even though it mentions money', 'travelstore-alia' ),
			'safety'        => __( 'Safety: add the "check your own government\'s advice" note', 'travelstore-alia' ),
			'contact'       => __( 'Also show the contact options under the answer', 'travelstore-alia' ),
		);
	}

	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_types' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_publish' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function register_types() {
		$cap = Plugin::CAP;
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Alia knowledge base', 'travelstore-alia' ),
					'singular_name' => __( 'Alia entry', 'travelstore-alia' ),
					'add_new_item'  => __( 'Add Alia entry', 'travelstore-alia' ),
					'edit_item'     => __( 'Edit Alia entry', 'travelstore-alia' ),
					'menu_name'     => __( 'Alia', 'travelstore-alia' ),
					'all_items'     => __( 'All entries', 'travelstore-alia' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-format-chat',
				'menu_position'   => 58,
				'supports'        => array( 'title', 'revisions' ),
				'taxonomies'      => array( self::REGION, self::TAG ),
				'capability_type' => array( 'alia_entry', 'alia_entries' ),
				'map_meta_cap'    => true,
				'capabilities'    => array(
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'delete_posts'           => $cap,
					'delete_private_posts'   => $cap,
					'delete_published_posts' => $cap,
					'delete_others_posts'    => $cap,
					'edit_private_posts'     => $cap,
					'edit_published_posts'   => $cap,
					'create_posts'           => $cap,
				),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
		foreach ( array( self::REGION => __( 'Regions', 'travelstore-alia' ), self::TAG => __( 'Tags', 'travelstore-alia' ) ) as $tax => $label ) {
			register_taxonomy(
				$tax,
				self::CPT,
				array(
					'labels'            => array( 'name' => $label, 'singular_name' => rtrim( $label, 's' ) ),
					'public'            => false,
					'show_ui'           => true,
					'show_admin_column' => self::REGION === $tax,
					'hierarchical'      => false,
					'show_in_rest'      => false,
					'rewrite'           => false,
					'capabilities'      => array(
						'manage_terms' => $cap,
						'edit_terms'   => $cap,
						'delete_terms' => $cap,
						'assign_terms' => $cap,
					),
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Reading an entry                                                    */
	/* ------------------------------------------------------------------ */

	public static function data( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		$m = static function ( $k ) use ( $post_id ) {
			return get_post_meta( $post_id, '_alia_' . $k, true );
		};
		$lines = static function ( $v ) {
			$a = is_array( $v ) ? $v : preg_split( '/\r\n|\r|\n/', (string) $v );

			return array_values( array_filter( array_map( 'trim', (array) $a ), 'strlen' ) );
		};
		$regions = wp_get_object_terms( $post_id, self::REGION, array( 'fields' => 'slugs' ) );
		$tags    = wp_get_object_terms( $post_id, self::TAG, array( 'fields' => 'slugs' ) );

		return array(
			'id'        => (int) $post_id,
			'status'    => $post->post_status,
			'title'     => $post->post_title,
			'type'      => $m( 'type' ) ? $m( 'type' ) : 'faq',
			'policy'    => (string) $m( 'policy_key' ),
			'questions' => $lines( $m( 'questions' ) ),
			'keywords'  => $lines( preg_replace( '/\s*,\s*/', "\n", (string) $m( 'keywords' ) ) ),
			'answer'    => (string) $m( 'answer' ),
			'detail'    => (string) $m( 'detail' ),
			'volatile'  => '1' === (string) $m( 'volatile' ),
			'verified'  => (string) $m( 'verified' ),
			'link'      => (string) $m( 'link' ),
			'image'     => (string) $m( 'image' ),
			'related'   => array_map( 'intval', (array) $m( 'related' ) ),
			'followups' => $lines( $m( 'followups' ) ),
			'flags'     => array_values( array_intersect( array_keys( self::flags() ), (array) $m( 'flags' ) ) ),
			'use'       => (string) $m( 'use_policy' ),
			'plan'      => is_array( $m( 'plan' ) ) ? $m( 'plan' ) : array(),
			'regions'   => is_wp_error( $regions ) ? array() : $regions,
			'tags'      => is_wp_error( $tags ) ? array() : $tags,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Editor                                                              */
	/* ------------------------------------------------------------------ */

	public static function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::CPT !== $screen->post_type ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', '.alia-box label{font-weight:600;display:block;margin:12px 0 4px}.alia-box textarea,.alia-box input[type=text],.alia-box input[type=date],.alia-box select{width:100%}.alia-box .description{color:#50575e;margin:4px 0 0}.alia-box .alia-flags label{font-weight:400;margin:4px 0}.alia-box textarea{min-height:90px}' );
	}

	public static function meta_boxes() {
		add_meta_box( 'alia_main', __( 'Alia entry', 'travelstore-alia' ), array( __CLASS__, 'box_main' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'alia_more', __( 'Behaviour and links', 'travelstore-alia' ), array( __CLASS__, 'box_more' ), self::CPT, 'normal', 'default' );
		add_meta_box( 'alia_plan', __( 'Trip planner (day blocks and activities)', 'travelstore-alia' ), array( __CLASS__, 'box_plan' ), self::CPT, 'normal', 'default' );
	}

	/** Day-block / activity fields used by the trip planner (spec 9.3). */
	public static function box_plan( $post ) {
		$d = self::data( $post->ID );
		$p = $d['plan'];
		$has = static function ( $k, $v ) use ( $p ) {
			return in_array( $v, (array) ( $p[ $k ] ?? array() ), true );
		};
		echo '<div class="alia-box"><p class="description">' . esc_html__( 'Only needed when "Entry type" is Day block or Activity. A day block is one stop of a trip idea (for example "Nizwa"). Alia puts published day blocks together into an itinerary.', 'travelstore-alia' ) . '</p>';
		echo '<label for="alia_n_min">' . esc_html__( 'Nights: fewest and most', 'travelstore-alia' ) . '</label>';
		echo '<input type="number" min="1" max="14" id="alia_n_min" name="alia_plan[nights_min]" value="' . esc_attr( (string) ( $p['nights_min'] ?? 1 ) ) . '" style="width:90px"> ';
		echo '<input type="number" min="1" max="14" name="alia_plan[nights_max]" value="' . esc_attr( (string) ( $p['nights_max'] ?? 2 ) ) . '" style="width:90px">';

		echo '<label>' . esc_html__( 'Interests this stop suits', 'travelstore-alia' ) . '</label><div class="alia-flags">';
		foreach ( Planner::interests() as $k => $label ) {
			echo '<label><input type="checkbox" name="alia_plan[interests][]" value="' . esc_attr( $k ) . '"' . checked( $has( 'interests', $k ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</div><label>' . esc_html__( 'Pace', 'travelstore-alia' ) . '</label><div class="alia-flags">';
		foreach ( Planner::paces() as $k => $label ) {
			echo '<label><input type="checkbox" name="alia_plan[paces][]" value="' . esc_attr( $k ) . '"' . checked( $has( 'paces', $k ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</div><label>' . esc_html__( 'Suitable for', 'travelstore-alia' ) . '</label><div class="alia-flags">';
		foreach ( Planner::who() as $k => $label ) {
			echo '<label><input type="checkbox" name="alia_plan[suitable][]" value="' . esc_attr( $k ) . '"' . checked( $has( 'suitable', $k ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</div><label>' . esc_html__( 'Best months (leave all empty if any month is fine)', 'travelstore-alia' ) . '</label><div class="alia-flags">';
		for ( $m = 1; $m <= 12; $m++ ) {
			echo '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="alia_plan[months][]" value="' . (int) $m . '"' . checked( in_array( $m, array_map( 'intval', (array) ( $p['months'] ?? array() ) ), true ), true, false ) . '> ' . esc_html( wp_date( 'M', mktime( 0, 0, 0, $m, 1, 2026 ) ) ) . '</label>';
		}
		echo '</div>';
		echo '<label for="alia_route">' . esc_html__( 'Position along the route (small numbers come first, for example Muscat 10, Nizwa 30, Salalah 90)', 'travelstore-alia' ) . '</label>';
		echo '<input type="number" id="alia_route" name="alia_plan[route]" value="' . esc_attr( (string) ( $p['route'] ?? 50 ) ) . '" style="width:90px">';
		echo '<label><input type="checkbox" name="alia_plan[arrival]" value="1"' . checked( ! empty( $p['arrival'] ), true, false ) . '> ' . esc_html__( 'This can be the first stop (arrival)', 'travelstore-alia' ) . '</label>';
		echo '<label for="alia_note">' . esc_html__( 'Travel note (shown on the card, for example "About 2 hours from Muscat")', 'travelstore-alia' ) . '</label>';
		echo '<input type="text" id="alia_note" name="alia_plan[note]" value="' . esc_attr( (string) ( $p['note'] ?? '' ) ) . '">';

		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 150, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) );
		echo '<label for="alia_page">' . esc_html__( 'Link the card to a site page (replaces "Linked page" above)', 'travelstore-alia' ) . '</label><select id="alia_page" name="alia_page"><option value="">-</option>';
		$cur = (string) $d['link'];
		foreach ( $pages as $pg ) {
			echo '<option value="page:' . (int) $pg->ID . '"' . selected( $cur, 'page:' . $pg->ID, false ) . '>' . esc_html( $pg->post_title ) . '</option>';
		}
		echo '</select></div>';
	}

	public static function box_main( $post ) {
		$d = self::data( $post->ID );
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<div class="alia-box">';
		echo '<label for="alia_type">' . esc_html__( 'Entry type', 'travelstore-alia' ) . '</label><select id="alia_type" name="alia_type">';
		foreach ( self::types() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $d['type'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<label for="alia_policy_key">' . esc_html__( 'Policy key (only for "Policy / template")', 'travelstore-alia' ) . '</label><select id="alia_policy_key" name="alia_policy_key"><option value="">-</option>';
		foreach ( self::policy_keys() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $d['policy'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<label for="alia_questions">' . esc_html__( 'Example questions (one per line)', 'travelstore-alia' ) . '</label>';
		echo '<textarea id="alia_questions" name="alia_questions">' . esc_textarea( implode( "\n", $d['questions'] ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'How visitors might ask it. This is the main thing Alia matches on, so add the different ways people phrase it.', 'travelstore-alia' ) . '</p>';

		echo '<label for="alia_keywords">' . esc_html__( 'Extra keywords (comma separated)', 'travelstore-alia' ) . '</label>';
		echo '<input type="text" id="alia_keywords" name="alia_keywords" value="' . esc_attr( implode( ', ', $d['keywords'] ) ) . '">';

		echo '<label for="alia_answer">' . esc_html__( 'Answer', 'travelstore-alia' ) . '</label>';
		echo '<textarea id="alia_answer" name="alia_answer" rows="6">' . esc_textarea( $d['answer'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'In Alia\'s voice, about 3 to 5 sentences. Do not put tour, hotel or activity prices here. Contact placeholders for policy entries: {PHONE_LINK} {WHATSAPP_LINK} {EMAIL_LINK} {FORM_LINK} {TOUR_PAGE_LINK}.', 'travelstore-alia' ) . '</p>';

		echo '<label for="alia_detail">' . esc_html__( 'Detail (optional, shown when the visitor taps "Tell me more")', 'travelstore-alia' ) . '</label>';
		echo '<textarea id="alia_detail" name="alia_detail" rows="4">' . esc_textarea( $d['detail'] ) . '</textarea>';
		echo '</div>';
	}

	public static function box_more( $post ) {
		$d = self::data( $post->ID );
		echo '<div class="alia-box">';
		echo '<label><input type="checkbox" name="alia_volatile" value="1"' . checked( $d['volatile'], true, false ) . '> ' . esc_html__( 'Volatile: this can change (rules, hours, dates). Alia adds a "please verify" note.', 'travelstore-alia' ) . '</label>';
		echo '<label for="alia_verified">' . esc_html__( 'Last verified', 'travelstore-alia' ) . '</label><input type="date" id="alia_verified" name="alia_verified" value="' . esc_attr( $d['verified'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Required for volatile entries before they can be published.', 'travelstore-alia' ) . '</p>';
		echo '<label for="alia_link">' . esc_html__( 'Linked page (tour or destination page address)', 'travelstore-alia' ) . '</label><input type="text" id="alia_link" name="alia_link" value="' . esc_attr( $d['link'] ) . '">';
		echo '<label for="alia_image">' . esc_html__( 'Image address (optional, for cards)', 'travelstore-alia' ) . '</label><input type="text" id="alia_image" name="alia_image" value="' . esc_attr( $d['image'] ) . '">';
		echo '<label for="alia_followups">' . esc_html__( 'Follow-up buttons (one per line; each is a question Alia can answer)', 'travelstore-alia' ) . '</label>';
		echo '<textarea id="alia_followups" name="alia_followups" rows="3">' . esc_textarea( implode( "\n", $d['followups'] ) ) . '</textarea>';

		echo '<label for="alia_use_policy">' . esc_html__( 'Answer with a standard response instead of the text above', 'travelstore-alia' ) . '</label><select id="alia_use_policy" name="alia_use_policy"><option value="">-</option>';
		foreach ( array( 'price' => __( 'Price response', 'travelstore-alia' ), 'safety' => __( 'Travel-safety response', 'travelstore-alia' ) ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $d['use'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		$rel = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 400, 'orderby' => 'title', 'order' => 'ASC', 'exclude' => array( $post->ID ), 'no_found_rows' => true, 'meta_query' => array( array( 'key' => '_alia_type', 'value' => 'policy', 'compare' => '!=' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		echo '<label for="alia_related">' . esc_html__( 'Related entries (hold Ctrl or Cmd to pick several)', 'travelstore-alia' ) . '</label><select id="alia_related" name="alia_related[]" multiple size="6">';
		foreach ( $rel as $r ) {
			echo '<option value="' . (int) $r->ID . '"' . selected( in_array( (int) $r->ID, $d['related'], true ), true, false ) . '>' . esc_html( $r->post_title ) . '</option>';
		}
		echo '</select>';

		echo '<label>' . esc_html__( 'Special behaviour', 'travelstore-alia' ) . '</label><div class="alia-flags">';
		foreach ( self::flags() as $k => $label ) {
			echo '<label><input type="checkbox" name="alia_flags[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $d['flags'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Region and Tags are in the boxes on the right.', 'travelstore-alia' ) . '</p>';
		echo '</div>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( Plugin::CAP ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$p     = wp_unslash( $_POST );
		$types = array_keys( self::types() );
		$type  = in_array( $p['alia_type'] ?? '', $types, true ) ? $p['alia_type'] : 'faq';
		$lines = static function ( $v ) {
			$a = preg_split( '/\r\n|\r|\n/', (string) $v );

			return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', (array) $a ) ), 'strlen' ) );
		};
		update_post_meta( $post_id, '_alia_type', $type );
		update_post_meta( $post_id, '_alia_policy_key', in_array( $p['alia_policy_key'] ?? '', array_keys( self::policy_keys() ), true ) ? $p['alia_policy_key'] : '' );
		update_post_meta( $post_id, '_alia_questions', $lines( $p['alia_questions'] ?? '' ) );
		update_post_meta( $post_id, '_alia_keywords', sanitize_text_field( $p['alia_keywords'] ?? '' ) );
		update_post_meta( $post_id, '_alia_answer', sanitize_textarea_field( $p['alia_answer'] ?? '' ) );
		update_post_meta( $post_id, '_alia_detail', sanitize_textarea_field( $p['alia_detail'] ?? '' ) );
		update_post_meta( $post_id, '_alia_volatile', ! empty( $p['alia_volatile'] ) ? '1' : '0' );
		$ver     = (string) ( $p['alia_verified'] ?? '' );
		$ver     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ver ) ? $ver : '';
		$old_ver = (string) get_post_meta( $post_id, '_alia_verified', true );
		update_post_meta( $post_id, '_alia_verified', $ver );
		if ( $ver !== $old_ver && '' !== $ver ) {
			// Someone consciously set a new "Last verified" date: it is no longer "seed, not fact-checked".
			delete_post_meta( $post_id, Stale::SEED_FLAG );
		}
		$link = trim( (string) ( $p['alia_link'] ?? '' ) );
		if ( ! empty( $p['alia_page'] ) && preg_match( '/^page:\d+$/', (string) $p['alia_page'] ) ) {
			$link = (string) $p['alia_page'];
		}
		update_post_meta( $post_id, '_alia_link', ( '' === $link || 0 === strpos( $link, '/' ) || 0 === strpos( $link, 'page:' ) ) ? sanitize_text_field( $link ) : esc_url_raw( $link ) );
		$pl   = isset( $p['alia_plan'] ) && is_array( $p['alia_plan'] ) ? $p['alia_plan'] : array();
		$pick = static function ( $key, array $allowed ) use ( $pl ) {
			return isset( $pl[ $key ] ) ? array_values( array_intersect( $allowed, (array) $pl[ $key ] ) ) : array();
		};
		$nmin = max( 1, min( 14, (int) ( $pl['nights_min'] ?? 1 ) ) );
		update_post_meta(
			$post_id,
			'_alia_plan',
			array(
				'nights_min' => $nmin,
				'nights_max' => max( $nmin, min( 14, (int) ( $pl['nights_max'] ?? $nmin ) ) ),
				'interests'  => $pick( 'interests', array_keys( Planner::interests() ) ),
				'paces'      => $pick( 'paces', array_keys( Planner::paces() ) ),
				'suitable'   => $pick( 'suitable', array_keys( Planner::who() ) ),
				'months'     => array_values( array_filter( array_map( 'intval', (array) ( $pl['months'] ?? array() ) ), static function ( $m ) {
					return $m >= 1 && $m <= 12;
				} ) ),
				'route'      => max( 0, min( 999, (int) ( $pl['route'] ?? 50 ) ) ),
				'arrival'    => ! empty( $pl['arrival'] ) ? 1 : 0,
				'note'       => sanitize_text_field( (string) ( $pl['note'] ?? '' ) ),
			)
		);
		update_post_meta( $post_id, '_alia_image', esc_url_raw( (string) ( $p['alia_image'] ?? '' ) ) );
		update_post_meta( $post_id, '_alia_followups', $lines( $p['alia_followups'] ?? '' ) );
		update_post_meta( $post_id, '_alia_use_policy', in_array( $p['alia_use_policy'] ?? '', array( 'price', 'safety' ), true ) ? $p['alia_use_policy'] : '' );
		update_post_meta( $post_id, '_alia_related', isset( $p['alia_related'] ) ? array_values( array_filter( array_map( 'absint', (array) $p['alia_related'] ) ) ) : array() );
		$flags = isset( $p['alia_flags'] ) ? array_values( array_intersect( array_keys( self::flags() ), (array) $p['alia_flags'] ) ) : array();
		update_post_meta( $post_id, '_alia_flags', $flags );

		// Content warnings (never block): prices, length.
		$warn   = array();
		$all    = (string) ( $p['alia_answer'] ?? '' ) . ' ' . (string) ( $p['alia_detail'] ?? '' );
		$moneyg = in_array( 'money_general', $flags, true );
		if ( ! $moneyg && Guardrails::has_price( $all ) ) {
			$warn[] = __( 'This entry looks like it contains a price (a currency name or symbol next to a number). Alia must never state tour, hotel or activity prices. If it is a general money fact, tick "General money fact".', 'travelstore-alia' );
		}
		if ( mb_strlen( (string) ( $p['alia_answer'] ?? '' ) ) > 800 ) {
			$warn[] = __( 'The answer is longer than about 800 characters. Shorter answers read better in a chat; move extra text into "Detail".', 'travelstore-alia' );
		}
		if ( '' === trim( (string) ( $p['alia_answer'] ?? '' ) ) && '' === (string) get_post_meta( $post_id, '_alia_use_policy', true ) ) {
			$warn[] = __( 'The answer is empty.', 'travelstore-alia' );
		}
		set_transient( self::WARN_KEY . get_current_user_id(), $warn, 120 );

		if ( function_exists( __NAMESPACE__ . '\\_noop' ) ) {
			return;
		}
	}

	/** Volatile entries without a "last verified" date cannot be published. */
	public static function guard_publish( $data, $postarr ) {
		if ( self::CPT !== ( $data['post_type'] ?? '' ) || 'publish' !== ( $data['post_status'] ?? '' ) ) {
			return $data;
		}
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return $data;
		}
		$vol = ! empty( $_POST['alia_volatile'] ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in save().
		$ver = isset( $_POST['alia_verified'] ) ? trim( (string) wp_unslash( $_POST['alia_verified'] ) ) : ''; // phpcs:ignore
		if ( $vol && '' === $ver ) {
			$data['post_status'] = 'draft';
			set_transient( self::WARN_KEY . 'blocked_' . get_current_user_id(), 1, 120 );
		}

		return $data;
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || self::CPT !== $screen->post_type ) {
			return;
		}
		$uid = get_current_user_id();
		if ( get_transient( self::WARN_KEY . 'blocked_' . $uid ) ) {
			delete_transient( self::WARN_KEY . 'blocked_' . $uid );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Not published: a volatile entry needs a "Last verified" date. It was saved as a draft.', 'travelstore-alia' ) . '</p></div>';
		}
		$warn = get_transient( self::WARN_KEY . $uid );
		if ( $warn ) {
			delete_transient( self::WARN_KEY . $uid );
			foreach ( (array) $warn as $w ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $w ) . '</p></div>';
			}
		}
	}

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['alia_type']     = __( 'Type', 'travelstore-alia' );
				$out['alia_volatile'] = __( 'Volatile', 'travelstore-alia' );
				$out['alia_verified'] = __( 'Last verified', 'travelstore-alia' );
			}
		}

		return $out;
	}

	public static function column( $col, $post_id ) {
		if ( 'alia_type' === $col ) {
			$t = (string) get_post_meta( $post_id, '_alia_type', true );
			$a = self::types();
			echo esc_html( $a[ $t ] ?? '-' );
		} elseif ( 'alia_volatile' === $col ) {
			echo '1' === (string) get_post_meta( $post_id, '_alia_volatile', true ) ? esc_html__( 'Yes', 'travelstore-alia' ) : '-';
		} elseif ( 'alia_verified' === $col ) {
			echo esc_html( (string) get_post_meta( $post_id, '_alia_verified', true ) ?: '-' );
		}
	}
}
