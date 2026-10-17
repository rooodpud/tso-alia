<?php
/**
 * Hand-off to the team (spec 9.5). The visitor fills in a small form with a consent line; the request
 * (with the trip idea) is saved in the TS Enquiries inbox as type "Alia trip idea" and emailed to the
 * office address by TS Enquiries' own mailer. A pre-filled WhatsApp link is returned too (the visitor
 * taps it to send; nothing is pushed to WhatsApp).
 *
 * Alia never collects personal details in the chat itself, only through this form.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handoff {

	const LIMIT  = 3;
	const WINDOW = 600; // seconds.

	public static function available() {
		return class_exists( '\\TravelStore\\Enquiries\\Store' ) && class_exists( '\\TravelStore\\Enquiries\\Mailer' );
	}

	/** @return array code: received|invalid|wait|error; errors (keys) for invalid; whatsapp link on success. */
	public static function process( array $in, $visitor_key ) {
		if ( ! self::available() ) {
			return array( 'code' => 'error' );
		}
		// Spam trap filled in: pretend it worked, save nothing.
		if ( ! empty( $in['website'] ) ) {
			return array( 'code' => 'received' );
		}
		$line = static function ( $k, $max ) use ( $in ) {
			$v = isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? sanitize_text_field( (string) $in[ $k ] ) : '';

			return mb_substr( $v, 0, $max );
		};
		$name  = $line( 'name', 120 );
		$email = sanitize_email( $line( 'email', 200 ) );
		$phone = $line( 'phone', 60 );
		$notes = isset( $in['notes'] ) && is_scalar( $in['notes'] ) ? mb_substr( trim( sanitize_textarea_field( (string) $in['notes'] ) ), 0, 1000 ) : '';

		$errors = array();
		if ( '' === $name ) {
			$errors['name'] = true;
		}
		if ( '' === $email && '' === trim( preg_replace( '/[^\d+]/', '', $phone ) ) ) {
			$errors['contact'] = true;
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			$errors['email'] = true;
		}
		if ( empty( $in['consent'] ) || 'yes' !== $in['consent'] ) {
			$errors['consent'] = true;
		}
		if ( $errors ) {
			return array( 'code' => 'invalid', 'errors' => $errors );
		}

		$key = 'alia_ho_' . md5( $visitor_key );
		$n   = (int) get_transient( $key );
		if ( $n >= self::LIMIT ) {
			return array( 'code' => 'wait' );
		}

		// Rebuild the trip summary on the server from block IDs, so nothing is trusted from the browser.
		$plan   = self::clean_plan( isset( $in['plan'] ) && is_array( $in['plan'] ) ? $in['plan'] : array() );
		$items  = self::items( $plan );
		$summary = $items || self::has_slots( $plan ) ? Planner::summary_text( $plan, $items ) : '';

		$type  = '' !== $summary ? __( 'Alia trip idea', 'travelstore-alia' ) : __( 'Alia message', 'travelstore-alia' );
		$rows  = array(
			array( 'key' => 'enquiry_type', 'label' => __( 'What can we help with?', 'travelstore-alia' ), 'value' => $type ),
			array( 'key' => 'full_name', 'label' => __( 'Your name', 'travelstore-alia' ), 'value' => $name ),
			array( 'key' => 'email', 'label' => __( 'Email', 'travelstore-alia' ), 'value' => $email ),
			array( 'key' => 'phone', 'label' => __( 'Phone / WhatsApp', 'travelstore-alia' ), 'value' => $phone ),
		);
		if ( '' !== $summary ) {
			$rows[] = array( 'key' => 'trip_idea', 'label' => __( 'Trip idea built with Alia', 'travelstore-alia' ), 'value' => "\n" . $summary );
		}
		$rows[] = array( 'key' => 'message', 'label' => __( 'Notes', 'travelstore-alia' ), 'value' => $notes );
		$rows[] = array( 'key' => 'consent', 'label' => __( 'Agreed to be contacted', 'travelstore-alia' ), 'value' => __( 'Yes', 'travelstore-alia' ) );

		$data  = array(
			'enquiry_type' => $type,
			'full_name'    => $name,
			'email'        => $email,
			'phone'        => $phone,
			'message'      => trim( $notes . ( '' !== $summary ? "\n\n" . $summary : '' ) ),
			'consent'      => 'yes',
		);
		$clean = array(
			'ok'     => true,
			'errors' => array(),
			'data'   => $data,
			'rows'   => $rows,
			'name'   => $name,
			'email'  => $email,
			'type'   => $type,
		);
		$id = \TravelStore\Enquiries\Store::create( $clean, array( 'page_id' => 0, 'url' => home_url( '/' ) ) );
		if ( is_wp_error( $id ) ) {
			return array( 'code' => 'error' );
		}
		set_transient( $key, $n + 1, self::WINDOW );
		\TravelStore\Enquiries\Mailer::office( $id );

		$out = array( 'code' => 'received' );
		$wa  = preg_replace( '/\D+/', '', (string) Settings::get( 'whatsapp' ) );
		if ( '' !== $wa ) {
			$text = "Hello Travel Store Oman, I just sent a request through Alia.\n" . ( '' !== $summary ? $summary : '' );
			$out['whatsapp'] = array( 'label' => 'Message us on WhatsApp too', 'url' => 'https://wa.me/' . $wa . '?text=' . rawurlencode( mb_substr( trim( $text ), 0, 900 ) ), 'kind' => 'whatsapp' );
		}

		return $out;
	}

	/** Whitelist the plan the browser sends. */
	public static function clean_plan( array $p ) {
		$int = array_keys( Planner::interests() );
		$out = array(
			'season'    => isset( $p['season'] ) && isset( Planner::seasons()[ $p['season'] ] ) ? $p['season'] : '',
			'nights'    => isset( $p['nights'] ) && isset( Planner::nights()[ $p['nights'] ] ) ? $p['nights'] : '',
			'who'       => isset( $p['who'] ) && isset( Planner::who()[ $p['who'] ] ) ? $p['who'] : '',
			'interests' => isset( $p['interests'] ) ? array_values( array_intersect( $int, (array) $p['interests'] ) ) : array(),
			'pace'      => isset( $p['pace'] ) && isset( Planner::paces()[ $p['pace'] ] ) ? $p['pace'] : '',
			'city'      => isset( $p['city'] ) && isset( Planner::cities()[ $p['city'] ] ) ? $p['city'] : '',
			'items'     => array(),
			'changing'  => '',
			'active'    => ! empty( $p['active'] ) ? 1 : 0,
		);
		foreach ( (array) ( $p['items'] ?? array() ) as $it ) {
			if ( is_array( $it ) && isset( $it['id'] ) ) {
				$out['items'][] = array( 'id' => (int) $it['id'], 'nights' => max( 1, min( 14, (int) ( $it['nights'] ?? 1 ) ) ) );
			}
		}
		$out['items'] = array_slice( $out['items'], 0, 12 );

		return $out;
	}

	private static function has_slots( array $plan ) {
		return '' !== $plan['season'] || '' !== $plan['nights'] || '' !== $plan['who'] || $plan['interests'] || '' !== $plan['pace'];
	}

	/** Items with titles and links resolved from the day blocks themselves. */
	private static function items( array $plan ) {
		$out = array();
		foreach ( $plan['items'] as $it ) {
			$post = get_post( $it['id'] );
			if ( ! $post || Entries::CPT !== $post->post_type || 'day_block' !== get_post_meta( $post->ID, '_alia_type', true ) ) {
				continue;
			}
			$out[] = array(
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'nights' => $it['nights'],
				'link'   => Settings::url( (string) get_post_meta( $post->ID, '_alia_link', true ) ),
			);
		}

		return $out;
	}
}
