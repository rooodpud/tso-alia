<?php
/**
 * The chat widget inside the contact flyout. The flyout (TS Enquiries plugin) offers three extension
 * points: actions `ts_flyout_assets`, `ts_flyout_menu_items`, `ts_flyout_panels`, and the JS event
 * `ts-flyout:view`. Alia prints its menu entry and its panel there; nothing else is touched.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widget {

	public static function register() {
		add_action( 'ts_flyout_assets', array( __CLASS__, 'assets' ) );
		add_action( 'ts_flyout_menu_items', array( __CLASS__, 'menu_item' ), 5 );
		add_action( 'ts_flyout_panels', array( __CLASS__, 'panel' ) );
	}

	private static function on() {
		return 'knowledge' === Settings::get( 'brain' );
	}

	public static function assets() {
		if ( ! self::on() ) {
			return;
		}
		$css = TSA_PATH . 'assets/alia.css';
		$js  = TSA_PATH . 'assets/alia.js';
		wp_enqueue_style( 'ts-alia', TSA_URL . 'assets/alia.css', array( 'ts-flyout' ), file_exists( $css ) ? (string) filemtime( $css ) : TSA_VERSION );
		wp_enqueue_script( 'ts-alia', TSA_URL . 'assets/alia.js', array( 'ts-flyout' ), file_exists( $js ) ? (string) filemtime( $js ) : TSA_VERSION, true );
		$priv = Settings::url( Settings::get( 'privacy_url' ) );
		wp_localize_script(
			'ts-alia',
			'tsAlia',
			array(
				'rest'    => esc_url_raw( rest_url( Rest::NS . '/' ) ),
				'nonce'   => wp_create_nonce( Rest::ACTION ),
				'max'     => (int) Settings::num( 'max_chars', 300 ),
				'privacy' => $priv,
				'days'    => (int) Settings::num( 'retention_days', 90 ),
			)
		);
	}

	public static function menu_item() {
		if ( ! self::on() ) {
			return;
		}
		?>
		<li><button type="button" class="ts-flyout__opt ts-flyout__opt--alia" data-ts-alia-open aria-controls="ts-alia">
			<span class="ts-flyout__ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2 9.6 8.6 3 11l6.6 2.4L12 20l2.4-6.6L21 11l-6.6-2.4L12 2Zm7 12-1 2.7-2.7 1 2.7 1 1 2.7 1-2.7 2.7-1-2.7-1-1-2.7Z"/></svg></span>
			<span class="ts-flyout__txt"><strong><?php esc_html_e( 'Chat with Alia', 'travelstore-alia' ); ?></strong><span><?php esc_html_e( 'Ask about travelling in Oman', 'travelstore-alia' ); ?></span></span>
		</button></li>
		<?php
	}

	public static function panel() {
		if ( ! self::on() ) {
			return;
		}
		?>
		<section class="ts-alia" id="ts-alia" aria-labelledby="ts-alia-title" hidden>
			<div class="ts-alia__bar">
				<button type="button" class="ts-flyout__back" data-ts-alia-back><?php esc_html_e( '← Back', 'travelstore-alia' ); ?></button>
				<button type="button" class="ts-alia__restart" data-ts-alia-restart><?php esc_html_e( 'Start over', 'travelstore-alia' ); ?></button>
			</div>
			<div class="ts-alia__who">
				<span class="ts-alia__avatar" aria-hidden="true">A</span>
				<p class="ts-alia__name" id="ts-alia-title"><?php esc_html_e( 'Alia', 'travelstore-alia' ); ?> <span aria-hidden="true">&middot;</span> <span class="ts-alia__role"><?php esc_html_e( 'virtual travel assistant', 'travelstore-alia' ); ?></span></p>
			</div>
			<p class="ts-alia__notice"><?php esc_html_e( 'I answer from what our team has written. Questions are logged without names or contact details to help us improve. Please do not share personal details here.', 'travelstore-alia' ); ?></p>
			<div class="ts-alia__log" id="ts-alia-log" role="log" aria-live="polite" aria-relevant="additions" tabindex="0"></div>
			<p class="ts-alia__typing" id="ts-alia-typing" hidden><span class="ts-alia__dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="ts-alia__typing-text"><?php esc_html_e( 'Alia is typing', 'travelstore-alia' ); ?></span></p>
			<div class="ts-alia__qr" id="ts-alia-qr" role="group" aria-label="<?php echo esc_attr__( 'Suggested replies', 'travelstore-alia' ); ?>"></div>
			<form class="ts-alia__form" id="ts-alia-form" novalidate>
				<label class="ts-alia__sr" for="ts-alia-input"><?php esc_html_e( 'Your message to Alia', 'travelstore-alia' ); ?></label>
				<input type="text" id="ts-alia-input" name="q" maxlength="<?php echo esc_attr( (string) (int) Settings::num( 'max_chars', 300 ) ); ?>" autocomplete="off" placeholder="<?php echo esc_attr__( 'Type your question…', 'travelstore-alia' ); ?>">
				<button type="submit" class="ts-alia__send"><?php esc_html_e( 'Send', 'travelstore-alia' ); ?></button>
			</form>
			<form class="ts-alia__handoff" id="ts-alia-handoff" novalidate hidden>
				<p class="ts-alia__ho-title"><?php esc_html_e( 'Send to our team', 'travelstore-alia' ); ?></p>
				<p class="ts-alia__ho-summary"></p>
				<div class="ts-alia__field">
					<label for="ts-alia-ho-name"><?php esc_html_e( 'Your name', 'travelstore-alia' ); ?> <span aria-hidden="true">*</span></label>
					<input type="text" id="ts-alia-ho-name" name="name" autocomplete="name" maxlength="120" required>
					<p class="ts-alia__err" data-err="name" hidden></p>
				</div>
				<div class="ts-alia__field">
					<label for="ts-alia-ho-email"><?php esc_html_e( 'Email', 'travelstore-alia' ); ?></label>
					<input type="email" id="ts-alia-ho-email" name="email" autocomplete="email" maxlength="200">
					<p class="ts-alia__err" data-err="email" hidden></p>
				</div>
				<div class="ts-alia__field">
					<label for="ts-alia-ho-phone"><?php esc_html_e( 'Phone / WhatsApp', 'travelstore-alia' ); ?></label>
					<input type="tel" id="ts-alia-ho-phone" name="phone" autocomplete="tel" maxlength="60">
					<p class="ts-alia__hint"><?php esc_html_e( 'Please add an email address or a phone number.', 'travelstore-alia' ); ?></p>
					<p class="ts-alia__err" data-err="contact" hidden></p>
				</div>
				<div class="ts-alia__field">
					<label for="ts-alia-ho-notes"><?php esc_html_e( 'Anything else? (optional)', 'travelstore-alia' ); ?></label>
					<textarea id="ts-alia-ho-notes" name="notes" rows="3" maxlength="1000"></textarea>
				</div>
				<div class="ts-alia__trap" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
				<div class="ts-alia__field ts-alia__field--check">
					<label><input type="checkbox" name="consent" value="yes" required> <span><?php echo esc_html( (string) Settings::get( 'consent_text' ) ); ?>
					<?php
					$priv = Settings::url( (string) Settings::get( 'privacy_url' ) );
					if ( '' !== $priv ) :
						?>
						<a href="<?php echo esc_url( $priv ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy notice', 'travelstore-alia' ); ?></a>
					<?php endif; ?></span></label>
					<p class="ts-alia__err" data-err="consent" hidden></p>
				</div>
				<p class="ts-alia__err" data-err="form" role="alert" hidden></p>
				<button type="submit" class="ts-alia__send ts-alia__send--wide"><?php esc_html_e( 'Send to our team', 'travelstore-alia' ); ?></button>
				<button type="button" class="ts-alia__restart" data-ts-alia-ho-cancel><?php esc_html_e( 'Cancel', 'travelstore-alia' ); ?></button>
			</form>
		</section>
		<?php
	}
}
