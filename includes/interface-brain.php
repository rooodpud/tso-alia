<?php
/**
 * The seam for a future AI brain (spec section 11). Phase 1 ships KnowledgeBrain only.
 *
 * A brain turns a structured Context (the top retrieved entries, the session state, the flags) into a
 * Response (outcome + the entry to answer from). Pre-filters and post-filters sit OUTSIDE the brain
 * (Guardrails / Engine), so politics, price, visa, URL-allowlist and volatile rules apply to any brain.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Brain {

	/**
	 * @param array $context [ 'text' => string, 'tokens' => array, 'matches' => array, 'outcome' => string,
	 *                         'candidates' => array, 'state' => array ]
	 * @return array [ 'outcome' => confident|ambiguous|none, 'entry_ids' => int[], 'score' => float ]
	 */
	public function respond( array $context ): array;
}
