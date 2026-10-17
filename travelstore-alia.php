<?php
/**
 * Plugin Name: Travel Store Alia
 * Description: Alia, the knowledge-base travel assistant in the contact flyout. Answers only from entries the team has written. No AI service and no outbound network calls.
 * Version:     0.3.1
 * Requires PHP: 8.1
 * Author:      Pradeep Hingorani
 * GitHub Plugin URI: https://github.com/rooodpud/tso-alia
 * Primary Branch: main
 * Text Domain: travelstore-alia
 *
 * Phase 1 (see docs/ALIA_SPEC.md): entries, retrieval, guardrails, chat widget, admin test tools.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TSA_VERSION', '0.3.1' );
define( 'TSA_PATH', plugin_dir_path( __FILE__ ) );
define( 'TSA_URL', plugin_dir_url( __FILE__ ) );

require_once TSA_PATH . 'includes/class-settings.php';
require_once TSA_PATH . 'includes/class-text.php';
require_once TSA_PATH . 'includes/class-entries.php';
require_once TSA_PATH . 'includes/class-index.php';
require_once TSA_PATH . 'includes/class-retrieval.php';
require_once TSA_PATH . 'includes/interface-brain.php';
require_once TSA_PATH . 'includes/class-knowledge-brain.php';
require_once TSA_PATH . 'includes/class-guardrails.php';
require_once TSA_PATH . 'includes/class-planner.php';
require_once TSA_PATH . 'includes/class-handoff.php';
require_once TSA_PATH . 'includes/class-engine.php';
require_once TSA_PATH . 'includes/class-log.php';
require_once TSA_PATH . 'includes/class-rest.php';
require_once TSA_PATH . 'includes/class-widget.php';
require_once TSA_PATH . 'includes/class-seed.php';
require_once TSA_PATH . 'includes/class-stale.php';
require_once TSA_PATH . 'includes/class-cases.php';
require_once TSA_PATH . 'includes/class-csv.php';
require_once TSA_PATH . 'includes/class-stats.php';
require_once TSA_PATH . 'includes/class-admin.php';

final class Plugin {

	const CAP = 'manage_alia_kb';

	public static function boot() {
		Entries::register();
		Index::register();
		Settings::register();
		Rest::register();
		Widget::register();
		Log::register();
		Admin::register();
		Stale::register();
		Cases::register();
		Csv::register();
		Stats::register();
		add_action( 'init', array( __CLASS__, 'ensure_cap' ) );
	}

	/** Administrators always hold the editing capability (also after a role reset). */
	public static function ensure_cap() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}
		// The team's role: edits entries, tests, runs test cases, imports / exports. No settings, no other admin screens.
		$ed = get_role( 'alia_editor' );
		if ( ! $ed ) {
			add_role( 'alia_editor', __( 'Alia editor', 'travelstore-alia' ), array( 'read' => true, self::CAP => true ) );
		} elseif ( ! $ed->has_cap( self::CAP ) ) {
			$ed->add_cap( self::CAP );
		}
	}

	public static function activate() {
		self::ensure_cap();
		Log::install();
		Entries::register_types();
		if ( ! wp_next_scheduled( Log::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Log::CRON );
		}
		if ( ! get_option( Seed::DONE_OPTION ) ) {
			Seed::import();
		}
		Index::rebuild();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( Log::CRON );
	}
}

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( __NAMESPACE__ . '\\Plugin', 'boot' ) );
