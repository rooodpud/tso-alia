<?php
/**
 * KnowledgeBrain: answers only from the entries retrieval found. No generation, no outside calls.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KnowledgeBrain implements Brain {

	public function respond( array $context ): array {
		$outcome = (string) ( $context['outcome'] ?? 'none' );
		$cand    = (array) ( $context['candidates'] ?? array() );

		return array(
			'outcome'   => $outcome,
			'entry_ids' => array_values( array_map( static function ( $c ) {
				return (int) $c['id'];
			}, $cand ) ),
			'score'     => $cand ? (float) $cand[0]['score'] : 0.0,
		);
	}
}
