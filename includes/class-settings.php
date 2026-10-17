<?php
/**
 * Alia settings: contact details, thresholds, retention and the editable global lists.
 * One option (`alia_settings`). Contact values are placeholders until the owner supplies the real ones.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION = 'alia_settings';
	const PAGE   = 'alia-settings';

	public static function defaults() {
		return array(
			// Contact (owner-supplied 2026-10-16; the same number is used for calls and WhatsApp).
			'phone'          => '+968 7410 0010',
			'whatsapp'       => '96874100010',
			'wa_text'        => 'Hello Travel Store Oman, I have a question about my trip.',
			'email'          => 'info@travelstoreoman.com',
			'contact_url'    => '/contact/',
			'tour_index_url' => '/',
			'privacy_url'    => '',
			'visa_url'       => 'https://evisa.rop.gov.om',
			'visa_label'     => 'Royal Oman Police eVisa website',
			// Retrieval thresholds.
			'th_confident'   => '0.60',
			'th_ambiguous'   => '0.45',
			'th_margin'      => '0.08',
			// Privacy and limits.
			'retention_days' => '90',
			'rate_per_min'   => '20',
			'max_chars'      => '300',
			'brain'          => 'knowledge',
			// Trip planner (Phase 2).
			'plan_on'        => 'yes',
			'w_interest'     => '3',
			'w_pace'         => '1',
			'w_group'        => '1.5',
			'w_season'       => '1.5',
			'min_fit'        => '0.35',
			'night_tol'      => '1',
			'stale_months'   => '6',
			'remote_route'   => '70',
			'remote_min_nights' => '8',
			'consent_text'   => 'I agree that Travel Store Oman may use the details I enter here, and the trip idea above, to reply to my request. My message is sent to the team by email.',
			// Global lists (one item per line).
			'synonyms'       => self::default_synonyms(),
			'politics'       => self::default_politics(),
			'politics_allow' => "sultan qaboos grand mosque\ngrand mosque\nsultan qaboos university",
			'safety_intent'  => "safe\nsafety\nsecure\nsecurity\ndangerous\ndanger\nrisky\ntravel advice\ntravel advisory\nadvisory\nshould i travel\nshould we travel\nshould i go\nshould we go\nok to travel\nokay to travel",
			'safety_now'     => "right now\nat the moment\ncurrently\nthese days\nthis week\nthis month\nat present\nsituation\nlatest news",
			'price'          => "price\nprices\npricing\ncost\ncosts\nhow much\nquote\nquotation\ncheap\ncheapest\nexpensive\nrate\nrates\nper person\nbudget\nfee\nfees\ndiscount\ndiscounts\noffer\ndeal\npackage price\nafford\nballpark\nrough number\nrough figure\nrough price\nrough cost\ncheaper\nlower price\nbest price\nestimate\napproximate cost",
			'frustration'    => "not happy\nunhappy\nangry\nfrustrated\nfrustrating\nannoyed\ndisappointed\nterrible\nawful\nworried\nanxious\nstressed\ncomplaint\ncomplain\nrefund\ncancel\ncancellation\nbooking problem\nproblem with my booking\nlost my booking\nnobody replied\nno one replied\nno reply\nno response\nnot replied\nnot responded\nhaven't heard\nhavent heard\nignored\nunanswered\nnobody has replied\nnobody answered",
			'human'          => "speak to someone\ntalk to someone\nspeak to a person\ntalk to a person\nspeak to a human\ntalk to a human\nreal person\nreal human\nhuman\nmanager\nsupervisor\nagent\nrepresentative\ncall me\ncall me back\nphone me\nring me\ncontact me\nget in touch\nsomeone to call\nsomeone call\nsend it to the team\nsend this to the team\npass this to the team\npass it on",
			'starters'       => "Plan a trip\nBest time to visit\nDo I need a visa?\nWhat should I wear?",
			'openers'        => "Sure thing.\nGood question.\nHappy to help.\nGot it.\nOf course.",
		);
	}

	private static function default_synonyms() {
		return implode(
			"\n",
			array(
				'wahiba = sharqiya = sharqiyah = wahiba sands = sharqiya sands = desert camp',
				'visa = electronic visa',
				'evisa = e visa = e-visa',
				'muscat = masqat = muscut',
				'khareef = kharif = monsoon season',
				'jabal = jebel = jabel = jabl',
				'akhdar = akdar = green mountain',
				'nizwa = nizwah = nazwa',
				'salalah = salala = dhofar',
				'musandam = mussandam = khasab',
				'wadi = wadis',
				'tip = tips = tipping = gratuity',
				'clothes = clothing = dress = attire = wear = outfit',
				'weather = climate = temperature = forecast',
				'kid = kids = child = children = toddler',
				'hotel = accommodation = lodging',
				'car = vehicle = rental car = car hire = hire car',
				'money = currency = cash',
				'taxi = cab',
				'food = cuisine = dish = dishes',
				'mosque = masjid',
				'alcohol = booze = liquor',
				'baby = infant',
				'passport = passports',
			)
		);
	}

	private static function default_politics() {
		return implode(
			"\n",
			array(
				'politic', 'politics', 'political', 'government', 'govern', 'sultan', 'monarchy', 'election', 'elections', 'parliament',
				'majlis ash shura', 'regime', 'protest', 'protests', 'war', 'wars', 'conflict', 'conflicts', 'military', 'army', 'invasion',
				'iran', 'iranian', 'israel', 'israeli', 'gaza', 'palestine', 'palestinian', 'yemen', 'houthi', 'houthis', 'ukraine', 'russia',
				'sanction', 'sanctions', 'embargo', 'terror', 'terrorism', 'terrorist', 'president', 'prime minister', 'minister', 'opposition',
				'human rights', 'foreign policy', 'nuclear', 'missile', 'missiles', 'strait of hormuz', 'hormuz', 'trump', 'biden', 'putin',
				'netanyahu', 'coup', 'dictator', 'democracy', 'propaganda',
				'who rules', 'who runs', 'who leads', 'who is in charge', 'ruler', 'rulers', 'ruling family', 'royal family', 'head of state', 'leader of oman', 'succession',
			)
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/** A one-per-line setting as a clean array. */
	public static function lines( $key ) {
		$out = preg_split( '/\r\n|\r|\n/', (string) self::get( $key ) );

		return array_values( array_filter( array_map( 'trim', (array) $out ), 'strlen' ) );
	}

	public static function flag( $key ) {
		return 'yes' === self::get( $key );
	}

	public static function num( $key, $default = 0.0 ) {
		$v = self::get( $key );

		return is_numeric( $v ) ? (float) $v : (float) $default;
	}

	/** Absolute URL for a stored value that may be a site-relative path. */
	public static function url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		if ( 0 === strpos( $value, '/' ) ) {
			return esc_url_raw( home_url( $value ) );
		}
		if ( preg_match( '/^page:(\d+)$/', $value, $m ) ) {
			// A site page by ID: follows the page if its address changes.
			$link = 'publish' === get_post_status( (int) $m[1] ) ? get_permalink( (int) $m[1] ) : '';

			return $link ? esc_url_raw( $link ) : '';
		}

		return '';
	}

	/** Contact options as link objects, skipping empty ones. Keys are the template placeholders. */
	public static function contact_links() {
		$s     = self::all();
		$out   = array();
		$phone = trim( (string) $s['phone'] );
		$tel   = preg_replace( '/[^\d+]/', '', $phone );
		if ( '' !== $tel ) {
			$out['PHONE_LINK'] = array( 'label' => 'Call ' . $phone, 'url' => 'tel:' . $tel, 'kind' => 'phone' );
		}
		$wa = preg_replace( '/\D+/', '', (string) $s['whatsapp'] );
		if ( '' !== $wa ) {
			$text              = trim( (string) $s['wa_text'] );
			$out['WHATSAPP_LINK'] = array( 'label' => 'WhatsApp us', 'url' => 'https://wa.me/' . $wa . ( '' !== $text ? '?text=' . rawurlencode( $text ) : '' ), 'kind' => 'whatsapp' );
		}
		$email = sanitize_email( (string) $s['email'] );
		if ( is_email( $email ) ) {
			$out['EMAIL_LINK'] = array( 'label' => 'Email ' . $email, 'url' => 'mailto:' . $email, 'kind' => 'email' );
		}
		$form = self::url( $s['contact_url'] );
		if ( '' !== $form ) {
			$out['FORM_LINK'] = array( 'label' => 'Open the contact form', 'url' => $form, 'kind' => 'form' );
		}

		return $out;
	}

	public static function register() {
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'changed' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'changed' ) );
	}

	/** Synonyms change how every entry is indexed, so rebuild. */
	public static function changed() {
		Index::rebuild();
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();
		foreach ( array( 'phone', 'wa_text', 'visa_label' ) as $k ) {
			$out[ $k ] = sanitize_text_field( $input[ $k ] ?? $out[ $k ] );
		}
		$out['whatsapp'] = preg_replace( '/\D+/', '', (string) ( $input['whatsapp'] ?? '' ) );
		$email           = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$out['email']    = is_email( $email ) ? $email : '';
		foreach ( array( 'contact_url', 'tour_index_url', 'privacy_url', 'visa_url' ) as $k ) {
			$v         = trim( (string) ( $input[ $k ] ?? '' ) );
			$out[ $k ] = ( '' === $v || 0 === strpos( $v, '/' ) ) ? sanitize_text_field( $v ) : esc_url_raw( $v );
		}
		foreach ( array( 'th_confident', 'th_ambiguous', 'th_margin' ) as $k ) {
			$v         = isset( $input[ $k ] ) ? (float) $input[ $k ] : (float) $out[ $k ];
			$out[ $k ] = (string) max( 0.05, min( 1.0, $v ) );
		}
		$out['retention_days'] = (string) max( 1, min( 730, (int) ( $input['retention_days'] ?? 90 ) ) );
		$out['rate_per_min']   = (string) max( 3, min( 120, (int) ( $input['rate_per_min'] ?? 20 ) ) );
		$out['max_chars']      = (string) max( 50, min( 1000, (int) ( $input['max_chars'] ?? 300 ) ) );
		$out['brain']          = 'knowledge'; // The AI option is visible but disabled (spec section 11).
		$out['plan_on']        = ! empty( $input['plan_on'] ) ? 'yes' : '';
		foreach ( array( 'w_interest', 'w_pace', 'w_group', 'w_season' ) as $k ) {
			$out[ $k ] = (string) max( 0, min( 10, (float) ( $input[ $k ] ?? $out[ $k ] ) ) );
		}
		$out['min_fit']      = (string) max( 0, min( 1, (float) ( $input['min_fit'] ?? 0.35 ) ) );
		$out['night_tol']    = (string) max( 0, min( 3, (int) ( $input['night_tol'] ?? 1 ) ) );
		$out['stale_months'] = (string) max( 1, min( 60, (int) ( $input['stale_months'] ?? 6 ) ) );
		$out['remote_route'] = (string) max( 0, min( 999, (int) ( $input['remote_route'] ?? 70 ) ) );
		$out['remote_min_nights'] = (string) max( 1, min( 21, (int) ( $input['remote_min_nights'] ?? 8 ) ) );
		$out['consent_text'] = sanitize_textarea_field( (string) ( $input['consent_text'] ?? $out['consent_text'] ) );
		foreach ( array( 'synonyms', 'politics', 'politics_allow', 'safety_intent', 'safety_now', 'price', 'frustration', 'human', 'starters', 'openers' ) as $k ) {
			$out[ $k ] = sanitize_textarea_field( (string) ( $input[ $k ] ?? '' ) );
		}

		return $out;
	}
}
