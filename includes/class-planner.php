<?php
/**
 * Rule-based trip planner (spec section 9). No AI: it asks a few tappable questions, scores the
 * published day blocks against the answers and assembles an idea (never a quote, never a price).
 *
 * The visitor's answers live in the browser's session state (`state.plan`); the server keeps nothing.
 * Every request carries one operation: start, set, ask, build, reset.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Planner {

	const SLOTS = array( 'season', 'nights', 'who', 'interests', 'pace', 'city' );

	/* ------------------------------------------------------------------ */
	/* Fixed option lists (shared with the entry editor)                    */
	/* ------------------------------------------------------------------ */

	public static function interests() {
		return array(
			'culture'     => 'Culture and history',
			'desert'      => 'Desert',
			'mountains'   => 'Mountains',
			'beaches'     => 'Beaches and sea',
			'wildlife'    => 'Wildlife',
			'food'        => 'Food',
			'photography' => 'Photography',
		);
	}

	public static function paces() {
		return array(
			'relaxed'  => 'Relaxed',
			'balanced' => 'Balanced',
			'active'   => 'Active',
		);
	}

	public static function who() {
		return array(
			'solo'    => 'Solo',
			'couple'  => 'Couple',
			'family'  => 'Family with kids',
			'friends' => 'Friends',
		);
	}

	/** Season => months (1 to 12). */
	public static function seasons() {
		return array(
			'winter' => array( 'label' => 'Winter (Dec to Feb)', 'months' => array( 12, 1, 2 ) ),
			'spring' => array( 'label' => 'Spring (Mar to May)', 'months' => array( 3, 4, 5 ) ),
			'summer' => array( 'label' => 'Summer (Jun to Aug)', 'months' => array( 6, 7, 8 ) ),
			'autumn' => array( 'label' => 'Autumn (Sep to Nov)', 'months' => array( 9, 10, 11 ) ),
			'any'    => array( 'label' => 'Not sure yet', 'months' => array() ),
		);
	}

	/** Nights bands: key => [label, min, target, max]. */
	public static function nights() {
		return array(
			'3-4'  => array( 'label' => '3 to 4 nights', 'min' => 3, 'target' => 4, 'max' => 4 ),
			'5-7'  => array( 'label' => '5 to 7 nights', 'min' => 5, 'target' => 6, 'max' => 7 ),
			'8-10' => array( 'label' => '8 to 10 nights', 'min' => 8, 'target' => 9, 'max' => 10 ),
			'10+'  => array( 'label' => 'More than 10', 'min' => 10, 'target' => 12, 'max' => 14 ),
		);
	}

	public static function cities() {
		return array(
			'muscat'  => 'Muscat',
			'salalah' => 'Salalah',
			'any'     => 'Not sure yet',
		);
	}

	private static function option_map( $slot ) {
		switch ( $slot ) {
			case 'season':
				return array_map( static function ( $s ) {
					return $s['label'];
				}, self::seasons() );
			case 'nights':
				return array_map( static function ( $s ) {
					return $s['label'];
				}, self::nights() );
			case 'who':
				return self::who();
			case 'interests':
				return self::interests();
			case 'pace':
				return array(
					'relaxed'  => 'Relaxed',
					'balanced' => 'Balanced',
					'active'   => 'Active',
				);
			case 'city':
				return self::cities();
		}

		return array();
	}

	private static function question( $slot ) {
		$q = array(
			'season'    => 'When are you thinking of travelling?',
			'nights'    => 'How many nights are you thinking?',
			'who'       => "Who's travelling?",
			'interests' => 'What would you like to see and do? Pick as many as you like, then tap Done.',
			'pace'      => 'And what pace suits you best?',
			'city'      => 'Where will you arrive? This is optional.',
		);

		return $q[ $slot ] ?? '';
	}

	private static function slot_name( $slot ) {
		$n = array( 'season' => 'When', 'nights' => 'Nights', 'who' => 'Travellers', 'interests' => 'Interests', 'pace' => 'Pace', 'city' => 'Arrival city' );

		return $n[ $slot ] ?? $slot;
	}

	/* ------------------------------------------------------------------ */
	/* Intent and month detection                                          */
	/* ------------------------------------------------------------------ */

	/** "plan a trip", "suggest an itinerary", "build me a trip idea"... (not "do you arrange itineraries"). */
	public static function is_intent( $norm ) {
		if ( ! Settings::flag( 'plan_on' ) ) {
			return false;
		}
		if ( preg_match( '/^(do|does|can|could|will|would) (you|your team|the team) (arrange|organi[sz]e|offer|do|provide|run)/', $norm ) ) {
			return false;
		}

		return 1 === preg_match( '/\b(plan|planning|build|create|design|suggest|recommend|put together|make|draw up|sketch)\b( me| us| my| our| a| an| the| some| your)*( [a-z]+){0,2} (trip|itinerary|holiday|journey|route|tour idea|trip idea)\b/', $norm )
			|| 1 === preg_match( '/^(trip idea|trip ideas|itinerary ideas?|plan a trip|plan my trip|plan my holiday|help me plan|help me choose)\b/', $norm )
			|| 1 === preg_match( '/\b(what should we do|what should i do) in oman\b/', $norm );
	}

	public static function detect_season( $norm ) {
		$months = array( 'january' => 'winter', 'jan' => 'winter', 'february' => 'winter', 'feb' => 'winter', 'march' => 'spring', 'april' => 'spring', 'may' => 'spring', 'june' => 'summer', 'july' => 'summer', 'august' => 'summer', 'september' => 'autumn', 'october' => 'autumn', 'november' => 'autumn', 'december' => 'winter' );
		foreach ( $months as $m => $s ) {
			if ( preg_match( '/(?<![a-z])' . $m . '(?![a-z])/', $norm ) ) {
				return $s;
			}
		}
		foreach ( array( 'winter', 'spring', 'summer', 'autumn' ) as $s ) {
			if ( preg_match( '/(?<![a-z])' . $s . '(?![a-z])/', $norm ) ) {
				return $s;
			}
		}
		if ( preg_match( '/(?<![a-z])fall(?![a-z])/', $norm ) ) {
			return 'autumn';
		}

		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Typed answers (people type "december" or "me and my wife" instead of tapping) */
	/* ------------------------------------------------------------------ */

	/** A trip idea is being put together, or has just been shown and can still be changed. */
	public static function in_context( array $state ) {
		$p = isset( $state['plan'] ) && is_array( $state['plan'] ) ? $state['plan'] : array();

		return Settings::flag( 'plan_on' ) && ( ! empty( $p['active'] ) || ! empty( $p['items'] ) || ! empty( $p['season'] ) );
	}

	/** True while the planner is waiting for an answer. */
	public static function is_active( array $state ) {
		return ! empty( $state['plan']['active'] );
	}

	private static function words_to_digits( $norm ) {
		$w = array( 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'fourteen' => 14 );
		foreach ( $w as $word => $n ) {
			$norm = preg_replace( '/(?<![a-z])' . $word . '(?![a-z])/', (string) $n, $norm );
		}

		return $norm;
	}

	private static function nights_band( $n ) {
		$n = max( 1, (int) $n );
		if ( $n <= 4 ) {
			return '3-4';
		}
		if ( $n <= 7 ) {
			return '5-7';
		}
		if ( $n <= 10 ) {
			return '8-10';
		}

		return '10+';
	}

	/** Pull whatever slot answers a typed message contains. @return array slot => value */
	public static function parse( $norm, $asked = '' ) {
		$f = array();
		$n = self::words_to_digits( $norm );

		$s = self::detect_season( $norm );
		if ( '' !== $s ) {
			$f['season'] = $s;
		} elseif ( 'season' === $asked && preg_match( '/\b(not sure|dont know|do not know|any ?time|flexible|whenever|no idea|open to anything|no preference)\b/', $norm ) ) {
			$f['season'] = 'any';
		}

		if ( preg_match( '/(?<![a-z0-9])(\d{1,2})\s*(?:-|to)?\s*(?:\d{1,2})?\s*(nights?|nts?|days?)(?![a-z])/', $n, $m ) ) {
			$v = (int) $m[1];
			$f['nights'] = self::nights_band( 0 === strpos( $m[2], 'd' ) ? max( 1, $v - 1 ) : $v );
		} elseif ( preg_match( '/(?<![a-z0-9])(\d)?\s*weeks?(?![a-z])/', $n, $m ) ) {
			$f['nights'] = self::nights_band( ( '' !== ( $m[1] ?? '' ) ? (int) $m[1] : 1 ) * 7 );
		} elseif ( 'nights' === $asked && preg_match( '/^(\d{1,2})$/', $n, $m ) ) {
			$f['nights'] = self::nights_band( (int) $m[1] );
		}
		if ( 'who' === $asked && ! isset( $f['nights'] ) && preg_match( '/^(they are |aged |ages |age )?\d{1,2}( and \d{1,2})*( years old| yrs| yo)?$/', $n ) ) {
			$f['who'] = 'family';
		}

		if ( preg_match( '/\b(kids?|child|children|family|families|son|sons|daughter|daughters|toddlers?|babies|baby|grandchildren|grandkids|teenagers?)\b/', $norm ) ) {
			$f['who'] = 'family';
		} elseif ( preg_match( '/\b(wife|husband|partner|couple|girlfriend|boyfriend|fiance|fiancee|honeymoon|two of us|my other half|spouse)\b/', $norm ) ) {
			$f['who'] = 'couple';
		} elseif ( preg_match( '/\b(friends?|mates|buddies|colleagues|group of|a group)\b/', $norm ) ) {
			$f['who'] = 'friends';
		} elseif ( preg_match( '/\b(solo|alone|myself|just me|only me|on my own|by myself|single traveller|single traveler)\b/', $norm ) ) {
			$f['who'] = 'solo';
		}

		$int = array();
		$map = array(
			'culture'     => 'history|historic|culture|cultural|heritage|forts?|castles?|museums?|souq|souk|architecture|old town',
			'desert'      => 'desert|dunes?|camels?|wahiba|sharqiya|camping|stargazing',
			'mountains'   => 'mountains?|hiking|hikes?|trekking|canyons?|jebel|jabal|wadis?|nature|villages',
			'beaches'     => 'beach|beaches|sea|snorkelling|snorkeling|swim|swimming|diving|dolphins?|islands?|coast|boat|sailing|relaxing by the water',
			'wildlife'    => 'wildlife|turtles?|animals|birds?|whales?|nature reserve',
			'food'        => 'food|cuisine|eating|restaurants?|cooking|foodie',
			'photography' => 'photo|photos|photography|photographer|photographing',
		);
		foreach ( $map as $k => $re ) {
			if ( preg_match( '/(?<![a-z])(' . $re . ')(?![a-z])/', $norm ) ) {
				$int[] = $k;
			}
		}
		if ( preg_match( '/(?<![a-z])adventur\w*/', $norm ) ) {
			$int = array_merge( $int, array( 'mountains', 'desert' ) );
		}
		if ( 'interests' === $asked && preg_match( '/\b(everything|all of it|a bit of everything|a little of everything|surprise me|anything)\b/', $norm ) ) {
			$int = array_keys( self::interests() );
		}
		if ( $int ) {
			$f['interests'] = array_values( array_unique( $int ) );
		}

		if ( preg_match( '/\b(relax\w*|slow|easy|chill\w*|laid back|leisure\w*|unhurried|no rush|not rushed|restful)\b/', $norm ) ) {
			$f['pace'] = 'relaxed';
		} elseif ( preg_match( '/\b(active|busy|packed|energetic|adventur\w*|action|full on|lots to do|as much as possible)\b/', $norm ) ) {
			$f['pace'] = 'active';
		} elseif ( preg_match( '/\b(balanced|moderate|medium|a bit of both|bit of both|mix of both|in between|somewhere in between)\b/', $norm ) ) {
			$f['pace'] = 'balanced';
		}

		if ( preg_match( '/\b(muscat|masqat)\b/', $norm ) ) {
			$f['city'] = 'muscat';
		} elseif ( preg_match( '/\b(salalah|dhofar)\b/', $norm ) ) {
			$f['city'] = 'salalah';
		} elseif ( 'city' === $asked && preg_match( '/\b(any|anywhere|not sure|dont mind|do not mind|either|skip|no preference|whichever|no)\b/', $norm ) ) {
			$f['city'] = 'any';
		}

		return $f;
	}

	private static function looks_like_question( $text, $norm ) {
		if ( preg_match( '/^(what|how) about\b/', $norm ) ) {
			return false;
		}

		return false !== strpos( (string) $text, '?' ) || 1 === preg_match( '/^(what|whats|how|hows|is|are|do|does|did|can|could|where|why|which|who|when|should|will|would|tell me|any|have)\b/', $norm );
	}

	/**
	 * A typed message while a trip idea is under way. Returns a response, or null when the message is not
	 * about the trip idea (it then goes to the normal question flow).
	 */
	public static function free_text( $text, $norm, array $state, $drafts = false ) {
		if ( ! self::in_context( $state ) ) {
			return null;
		}
		$plan = array_merge( self::empty_plan(), (array) $state['plan'] );
		$trace = array( 'filters' => array( 'planner: typed message' ), 'matches' => array(), 'tokens' => array(), 'corrected' => array() );

		if ( preg_match( '/^(start again|start over|restart|reset|new idea|a new idea|new trip|begin again|again|start a new idea)$/', $norm ) ) {
			return self::handle( array( 'op' => 'start' ), $state, '', $drafts );
		}
		if ( preg_match( '/^(cancel|never mind|nevermind|forget it|stop|quit|exit|not now|no thanks|no thank you)$/', $norm ) ) {
			$plan['active'] = 0;
			$state['plan']  = $plan;
			$qr = array();
			foreach ( Settings::lines( 'starters' ) as $s ) {
				$qr[] = array( 'label' => $s, 'text' => $s );
			}

			return Engine::finish( 'plan_cancel', array( array( 'text' => 'No problem. Ask me anything about Oman whenever you like.', 'links' => array() ) ), $qr, $state, $trace, array() );
		}

		$asked = self::next_missing( $plan );
		if ( ! empty( $plan['active'] ) && preg_match( '/^(ok|okay|continue|ok continue|carry on|go on|go ahead|yes|yeah|yep|sure|next|ready|lets go|lets continue)$/', $norm ) ) {
			$state['plan'] = $plan;
			if ( '' === $asked ) {
				return self::build_response( array_merge( $plan, array( 'city' => 'any' ) ), $state, $trace, $drafts );
			}
			list( $chunks, $qr ) = self::ask( $asked, $plan, 'Great.' );

			return Engine::finish( 'plan_ask', $chunks, $qr, $state, $trace, array() );
		}
		if ( self::looks_like_question( $text, $norm ) ) {
			return null;
		}

		$found = self::parse( $norm, $asked );
		if ( ! $found ) {
			return null;
		}
		$rebuild = ! empty( $plan['items'] );
		foreach ( $found as $slot => $val ) {
			$plan[ $slot ] = $val;
		}
		if ( isset( $found['interests'] ) && 'active' === ( $found['pace'] ?? '' ) ) {
			$plan['pace'] = 'active';
		}
		$plan['active']   = 1;
		$plan['changing'] = '';
		$trace['filters'][] = 'planner: typed ' . implode( ', ', array_keys( $found ) );
		$next = self::next_missing( $plan );
		if ( '' === $next || 'city' === $next || $rebuild ) {
			if ( '' === $plan['city'] ) {
				$plan['city'] = 'any';
			}
			if ( '' === self::next_missing( $plan ) ) {
				return self::build_response( $plan, $state, $trace, $drafts );
			}
		}
		$state['plan'] = $plan;
		list( $chunks, $qr ) = self::ask( $next, $plan, 'Got it.' );

		return Engine::finish( 'plan_ask', $chunks, $qr, $state, $trace, array() );
	}

	/* ------------------------------------------------------------------ */
	/* Blocks                                                              */
	/* ------------------------------------------------------------------ */

	public static function blocks( $drafts = false ) {
		$statuses = $drafts ? array( 'publish', 'draft', 'pending', 'private' ) : array( 'publish' );
		$ids      = get_posts(
			array(
				'post_type'      => Entries::CPT,
				'post_status'    => $statuses,
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => '_alia_type', 'value' => 'day_block' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$d = Entries::data( $id );
			if ( ! $d ) {
				continue;
			}
			$p      = $d['plan'];
			$link   = Settings::url( $d['link'] );
			$img    = $d['image'];
			if ( '' === $img && '' !== $d['link'] && preg_match( '/^page:(\d+)$/', $d['link'], $m ) ) {
				$u   = get_the_post_thumbnail_url( (int) $m[1], 'medium_large' );
				$img = $u ? $u : '';
			}
			$out[] = array(
				'id'        => (int) $id,
				'title'     => $d['title'],
				'blurb'     => $d['answer'],
				'link'      => $link,
				'img'       => $img,
				'regions'   => $d['regions'],
				'n_min'     => max( 1, (int) ( $p['nights_min'] ?? 1 ) ),
				'n_max'     => max( 1, (int) ( $p['nights_max'] ?? ( $p['nights_min'] ?? 1 ) ) ),
				'interests' => (array) ( $p['interests'] ?? array() ),
				'paces'     => (array) ( $p['paces'] ?? array() ),
				'suitable'  => (array) ( $p['suitable'] ?? array() ),
				'months'    => array_map( 'intval', (array) ( $p['months'] ?? array() ) ),
				'route'     => (int) ( $p['route'] ?? 50 ),
				'arrival'   => ! empty( $p['arrival'] ),
				'note'      => (string) ( $p['note'] ?? '' ),
				'status'    => $d['status'],
			);
		}

		return $out;
	}

	private static function score( array $b, array $plan ) {
		$wi = Settings::num( 'w_interest', 3 );
		$wp = Settings::num( 'w_pace', 1 );
		$wg = Settings::num( 'w_group', 1.5 );
		$ws = Settings::num( 'w_season', 1.5 );
		$total = $wi + $wp + $wg + $ws;

		$sel = (array) ( $plan['interests'] ?? array() );
		if ( $sel ) {
			$hit = count( array_intersect( $sel, $b['interests'] ) );
			$i   = $hit / count( $sel );
		} else {
			$i = 0.5;
		}
		$pace = (string) ( $plan['pace'] ?? '' );
		$p    = ( '' === $pace || ! $b['paces'] ) ? 0.5 : ( in_array( $pace, $b['paces'], true ) ? 1.0 : 0.0 );
		$who  = (string) ( $plan['who'] ?? '' );
		$g    = ( '' === $who || ! $b['suitable'] ) ? 0.5 : ( in_array( $who, $b['suitable'], true ) ? 1.0 : 0.0 );
		$season = (string) ( $plan['season'] ?? '' );
		$sm     = self::seasons()[ $season ]['months'] ?? array();
		if ( ! $sm || ! $b['months'] ) {
			$s = 0.5;
		} else {
			$s = count( array_intersect( $sm, $b['months'] ) ) / count( $sm );
		}

		return ( $wi * $i + $wp * $p + $wg * $g + $ws * $s ) / max( 0.001, $total );
	}

	/**
	 * Assemble an itinerary (spec 9.4).
	 *
	 * @return array{items:array,nights:int,fit:float,ok:bool,why:string}
	 */
	public static function assemble( array $plan, array $blocks ) {
		$band   = self::nights()[ $plan['nights'] ?? '5-7' ] ?? self::nights()['5-7'];
		$target = (int) $band['target'];
		$tol    = (int) Settings::num( 'night_tol', 1 );
		$min_fit = Settings::num( 'min_fit', 0.35 );
		if ( ! $blocks ) {
			return array( 'items' => array(), 'nights' => 0, 'fit' => 0.0, 'ok' => false, 'why' => 'no_blocks' );
		}
		foreach ( $blocks as $k => $b ) {
			$blocks[ $k ]['score'] = self::score( $b, $plan );
		}

		$chosen = array();
		$used   = 0;

		// 1. Arrival block.
		$city     = (string) ( $plan['city'] ?? 'any' );
		$arrivals = array_values( array_filter( $blocks, static function ( $b ) {
			return $b['arrival'];
		} ) );
		if ( $arrivals ) {
			$pick = null;
			foreach ( $arrivals as $a ) {
				if ( 'any' !== $city && in_array( $city, $a['regions'], true ) ) {
					$pick = $a;
				}
			}
			if ( ! $pick ) {
				// No arrival city chosen: start at the main gateway (the arrival stop first along the route).
				usort( $arrivals, static function ( $x, $y ) {
					return $x['route'] <=> $y['route'];
				} );
				$pick = $arrivals[0];
			}
			$pick['nights'] = min( $pick['n_min'], max( 1, $target - 1 ) );
			$chosen[ $pick['id'] ] = $pick;
			$used += $pick['nights'];
		}

		// 2. Best remaining blocks, highest score first, while the nights fit.
		$rest = array_values( array_filter( $blocks, static function ( $b ) use ( $chosen ) {
			return ! isset( $chosen[ $b['id'] ] );
		} ) );
		usort( $rest, static function ( $x, $y ) {
			return $y['score'] <=> $x['score'] ?: $x['route'] <=> $y['route'];
		} );
		// Pace limits how many stops fit: relaxed = fewer, longer stays; active = more, shorter.
		$pace = (string) ( $plan['pace'] ?? '' );
		if ( 'relaxed' === $pace ) {
			$cap = max( 2, (int) ceil( $target / 3 ) );
		} elseif ( 'active' === $pace ) {
			$cap = max( 2, (int) ceil( $target * 0.6 ) );
		} else {
			$cap = max( 2, (int) ceil( $target / 2 ) );
		}
		$cap       += $arrivals ? 1 : 0; // The arrival stop does not use up the pace budget.
		$sel_int    = (array) ( $plan['interests'] ?? array() );
		$remote_at  = (int) Settings::num( 'remote_route', 70 );
		$remote_min = (int) Settings::num( 'remote_min_nights', 8 );
		// Pass 1 keeps to the visitor's interests. Pass 2 (only if the nights are not filled) adds
		// the best of the rest, so a long trip is not left short.
		foreach ( array( true, false ) as $gate ) {
			if ( $used >= $target ) {
				break;
			}
			foreach ( $rest as $b ) {
				if ( count( $chosen ) >= $cap ) {
					break;
				}
				if ( isset( $chosen[ $b['id'] ] ) || $b['score'] < $min_fit ) {
					continue;
				}
				if ( $gate && $sel_int && ! array_intersect( $sel_int, $b['interests'] ) ) {
					continue;
				}
				// Remote stops (far along the route) need a long enough trip, unless the visitor arrives there.
				$arrives_here = 'any' !== $city && in_array( $city, $b['regions'], true );
				if ( $b['route'] >= $remote_at && $target < $remote_min && ! $arrives_here ) {
					continue;
				}
				$need = $b['n_min'];
				if ( $used + $need > $target + $tol ) {
					continue;
				}
				$b['nights']        = $need;
				$chosen[ $b['id'] ] = $b;
				$used              += $need;
				if ( $used >= $target ) {
					break;
				}
			}
		}

		// 3. Spread any remaining nights over the best-scoring chosen blocks (up to each block's maximum).
		if ( $chosen ) {
			$by = array_keys( $chosen );
			usort( $by, static function ( $x, $y ) use ( $chosen ) {
				return $chosen[ $y ]['score'] <=> $chosen[ $x ]['score'];
			} );
			$guard = 0;
			while ( $used < $target && $guard++ < 40 ) {
				$grew = false;
				foreach ( $by as $id ) {
					if ( $used >= $target ) {
						break;
					}
					if ( $chosen[ $id ]['nights'] < $chosen[ $id ]['n_max'] ) {
						++$chosen[ $id ]['nights'];
						++$used;
						$grew = true;
					}
				}
				if ( ! $grew ) {
					break;
				}
			}
		}

		// The arrival stop comes first; the rest follow the route order.
		$first = $arrivals && isset( $pick['id'] ) ? (int) $pick['id'] : 0;
		$items = array_values( $chosen );
		usort( $items, static function ( $x, $y ) use ( $first ) {
			if ( $x['id'] === $first && $y['id'] !== $first ) {
				return -1;
			}
			if ( $y['id'] === $first && $x['id'] !== $first ) {
				return 1;
			}

			return $x['route'] <=> $y['route'];
		} );
		$fit = $items ? array_sum( array_column( $items, 'score' ) ) / count( $items ) : 0.0;
		$ok  = count( $items ) >= 2 || ( 1 === count( $items ) && $target <= 4 );
		if ( $used < max( 2, $band['min'] - 1 ) ) {
			$ok  = false;
			$why = 'too_few_nights';
		} elseif ( $fit < $min_fit ) {
			$ok  = false;
			$why = 'low_fit';
		} elseif ( ! $ok ) {
			$why = 'too_few_blocks';
		} else {
			$why = '';
		}

		return array( 'items' => $items, 'nights' => $used, 'fit' => round( $fit, 3 ), 'ok' => $ok, 'why' => $why );
	}

	/* ------------------------------------------------------------------ */
	/* Responding                                                          */
	/* ------------------------------------------------------------------ */

	private static function empty_plan() {
		return array( 'season' => '', 'nights' => '', 'who' => '', 'interests' => array(), 'pace' => '', 'city' => '', 'items' => array(), 'changing' => '', 'active' => 0 );
	}

	private static function next_missing( array $plan ) {
		foreach ( self::SLOTS as $slot ) {
			if ( 'interests' === $slot ? empty( $plan['interests'] ) : '' === (string) $plan[ $slot ] ) {
				return $slot;
			}
		}

		return '';
	}

	private static function ask( $slot, array $plan, $lead = '' ) {
		$chunks = array( array( 'text' => trim( $lead . ' ' . self::question( $slot ) ), 'links' => array() ) );
		$qr     = array();
		foreach ( self::option_map( $slot ) as $value => $label ) {
			if ( 'interests' === $slot ) {
				$qr[] = array( 'label' => $label, 'plan' => array( 'op' => 'toggle', 'slot' => $slot, 'value' => $value ), 'multi' => true, 'on' => in_array( $value, (array) $plan['interests'], true ) );
			} else {
				$qr[] = array( 'label' => $label, 'plan' => array( 'op' => 'set', 'slot' => $slot, 'value' => $value ) );
			}
		}
		if ( 'interests' === $slot ) {
			$qr[] = array( 'label' => 'Done', 'plan' => array( 'op' => 'done', 'slot' => 'interests' ), 'done' => true );
		}

		return array( $chunks, $qr );
	}

	public static function summary_labels( array $plan ) {
		$seasons = self::seasons();
		$nights  = self::nights();
		$who     = self::who();
		$int     = self::interests();
		$paces   = self::paces();
		$parts   = array();
		if ( ! empty( $plan['nights'] ) && isset( $nights[ $plan['nights'] ] ) ) {
			$parts['nights'] = $nights[ $plan['nights'] ]['label'];
		}
		if ( ! empty( $plan['season'] ) && 'any' !== $plan['season'] && isset( $seasons[ $plan['season'] ] ) ) {
			$parts['season'] = $seasons[ $plan['season'] ]['label'];
		}
		if ( ! empty( $plan['who'] ) && isset( $who[ $plan['who'] ] ) ) {
			$parts['who'] = $who[ $plan['who'] ];
		}
		if ( ! empty( $plan['interests'] ) ) {
			$parts['interests'] = implode( ', ', array_map( static function ( $k ) use ( $int ) {
				return strtolower( $int[ $k ] ?? $k );
			}, array_filter( (array) $plan['interests'], static function ( $k ) use ( $int ) {
				return isset( $int[ $k ] );
			} ) ) );
		}
		if ( ! empty( $plan['pace'] ) && isset( $paces[ $plan['pace'] ] ) ) {
			$parts['pace'] = strtolower( $paces[ $plan['pace'] ] );
		}
		if ( ! empty( $plan['city'] ) && 'any' !== $plan['city'] && isset( self::cities()[ $plan['city'] ] ) ) {
			$parts['city'] = self::cities()[ $plan['city'] ];
		}

		return $parts;
	}

	/** Plain-text summary used for the WhatsApp message and the team's email. */
	public static function summary_text( array $plan, array $items ) {
		$p     = self::summary_labels( $plan );
		$lines = array();
		$bits  = array();
		foreach ( array( 'nights' => 'Length', 'season' => 'When', 'who' => 'Travellers', 'interests' => 'Interests', 'pace' => 'Pace', 'city' => 'Arrival' ) as $k => $label ) {
			if ( isset( $p[ $k ] ) ) {
				$bits[] = $label . ': ' . $p[ $k ];
			}
		}
		if ( $bits ) {
			$lines[] = implode( '; ', $bits );
		}
		$i = 1;
		foreach ( $items as $it ) {
			$line = $i++ . '. ' . $it['title'] . ' (' . (int) $it['nights'] . ' night' . ( 1 === (int) $it['nights'] ? '' : 's' ) . ')';
			if ( ! empty( $it['link'] ) ) {
				$line .= ' ' . $it['link'];
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	private static function whatsapp_link( array $plan, array $items ) {
		$wa = preg_replace( '/\D+/', '', (string) Settings::get( 'whatsapp' ) );
		if ( '' === $wa ) {
			return null;
		}
		$text = "Hello Travel Store Oman, I built this trip idea with Alia and would like to talk it through:\n" . self::summary_text( $plan, $items );
		if ( mb_strlen( $text ) > 900 ) {
			$text = mb_substr( $text, 0, 897 ) . '...';
		}

		return array( 'label' => 'Send this idea on WhatsApp', 'url' => 'https://wa.me/' . $wa . '?text=' . rawurlencode( $text ), 'kind' => 'whatsapp' );
	}

	private static function build_response( array $plan, array $state, array $trace, $drafts ) {
		$blocks = self::blocks( $drafts );
		$r      = self::assemble( $plan, $blocks );
		$trace['filters'][] = 'planner: build (' . count( $blocks ) . ' blocks, fit ' . $r['fit'] . ', nights ' . $r['nights'] . ( $r['why'] ? ', ' . $r['why'] : '' ) . ')';
		$trace['plan'] = array( 'plan' => $plan, 'result' => $r );

		$qr_end = array(
			array( 'label' => 'Send to our team', 'action' => 'handoff' ),
			array( 'label' => 'Change something', 'plan' => array( 'op' => 'change' ) ),
			array( 'label' => 'Start a new idea', 'plan' => array( 'op' => 'start' ) ),
		);
		if ( ! $r['ok'] ) {
			$plan['items']  = array();
			$plan['active'] = 0;
			$state['plan']  = $plan;
			$text = ( 'no_blocks' === $r['why'] )
				? "I don't have trip ideas ready to show just yet, and I'd rather not guess. Our team can put together a proper plan for you."
				: "I couldn't find a combination that fits those choices well, and I'd rather not force one. You could change something, or our team can put together a proper plan for you.";
			$chunks = array( array( 'text' => $text, 'links' => array() ) );
			$qr     = ( 'no_blocks' === $r['why'] ) ? array( $qr_end[0], $qr_end[2] ) : $qr_end;
			$wa     = self::whatsapp_link( $plan, array() );
			if ( $wa ) {
				$chunks[0]['links'][] = $wa;
			}

			return Engine::finish( 'plan_nofit', $chunks, $qr, $state, $trace, array() );
		}

		$plan['items'] = array_map( static function ( $i ) {
			return array( 'id' => $i['id'], 'nights' => $i['nights'] );
		}, $r['items'] );
		$plan['changing'] = '';
		$plan['active']   = 0;
		$state['plan']    = $plan;

		$labels = self::summary_labels( $plan );
		$flavour = array();
		if ( isset( $labels['pace'] ) ) {
			$flavour[] = 'kept it ' . $labels['pace'];
		}
		if ( isset( $labels['interests'] ) ) {
			$flavour[] = 'built it around ' . $labels['interests'];
		}
		$intro = "Here's an idea that could suit you."
			. ( $flavour ? ' I\'ve ' . implode( ' and ', $flavour ) . '.' : '' );
		$line = implode( ' > ', array_map( static function ( $i ) {
			return $i['title'] . ' (' . (int) $i['nights'] . ')';
		}, $r['items'] ) );
		$cards = array();
		foreach ( $r['items'] as $i ) {
			$cards[] = array(
				'title' => $i['title'],
				'blurb' => Guardrails::scrub_prices( $i['blurb'], false ),
				'image' => $i['img'],
				'link'  => $i['link'],
				'nights' => (int) $i['nights'],
				'note'  => $i['note'],
			);
		}
		$closing = "That's an idea, not a quote or a booking. I can't share prices here, but our team can put together a proper plan and quote.";
		$chunks  = array(
			array( 'text' => $intro, 'links' => array() ),
			array( 'text' => $line . ' Total: ' . (int) $r['nights'] . ' nights.', 'links' => array() ),
			array( 'text' => $closing, 'links' => array_values( array_filter( array( self::whatsapp_link( $plan, $r['items'] ) ) ) ) ),
		);

		return Engine::finish( 'plan', $chunks, $qr_end, $state, $trace, array( 'cards' => $cards, 'cards_after' => 2 ) );
	}

	/**
	 * Handle one planner operation.
	 *
	 * @param array  $op      [ op => start|toggle|set|done|ask|change|build|reset, slot?, value? ]
	 * @param array  $state   Session state (with `plan`).
	 * @param string $text    The visitor's free text that started the planner (for month detection).
	 * @param bool   $drafts  Include draft blocks (admin preview only).
	 */
	public static function handle( array $op, array $state, $text = '', $drafts = false ) {
		$trace = array( 'filters' => array(), 'matches' => array(), 'tokens' => array(), 'corrected' => array() );
		$plan  = isset( $state['plan'] ) && is_array( $state['plan'] ) ? array_merge( self::empty_plan(), $state['plan'] ) : self::empty_plan();
		$kind  = (string) ( $op['op'] ?? 'start' );
		$slot  = (string) ( $op['slot'] ?? '' );
		$trace['filters'][] = 'planner: ' . $kind . ( $slot ? ' ' . $slot : '' );
		$plan['active']     = 1;

		if ( 'resume' === $kind ) {
			$state['plan'] = $plan;
			$next          = self::next_missing( $plan );
			if ( '' === $next || 'city' === $next ) {
				if ( '' === self::next_missing( array_merge( $plan, array( 'city' => 'any' ) ) ) ) {
					return self::build_response( array_merge( $plan, array( 'city' => 'any' ) ), $state, $trace, $drafts );
				}
			}
			list( $chunks, $qr ) = self::ask( $next, $plan, (string) ( $op['lead'] ?? 'Back to your trip idea.' ) );

			return Engine::finish( 'plan_ask', $chunks, $qr, $state, $trace, array() );
		}

		if ( 'reset' === $kind || 'start' === $kind ) {
			$plan = self::empty_plan();
			$plan['active'] = 1;
			$found = ( '' !== $text ) ? self::parse( Text::normalize( $text ), '' ) : array();
			foreach ( $found as $fs => $fv ) {
				$plan[ $fs ] = $fv;
			}
			$lead = $found ? 'Lovely.' : 'Sure thing.';
			$next = self::next_missing( $plan );
			if ( $found && ( '' === $next || 'city' === $next ) ) {
				$plan['city']  = 'any';
				$state['plan'] = $plan;

				return self::build_response( $plan, $state, $trace, $drafts );
			}
			$state['plan'] = $plan;
			list( $chunks, $qr ) = self::ask( $next, $plan, $lead );

			return Engine::finish( 'plan_ask', $chunks, $qr, $state, $trace, array() );
		}

		if ( 'change' === $kind ) {
			$qr = array();
			$lbl = self::summary_labels( $plan );
			foreach ( self::SLOTS as $s ) {
				$cur = isset( $lbl[ 'city' === $s ? 'city' : $s ] ) ? $lbl[ $s ] : '';
				$qr[] = array( 'label' => self::slot_name( $s ) . ( '' !== $cur ? ': ' . $cur : '' ), 'plan' => array( 'op' => 'ask', 'slot' => $s ) );
			}
			$state['plan'] = $plan;

			return Engine::finish( 'plan_ask', array( array( 'text' => 'Of course. What would you like to change?', 'links' => array() ) ), $qr, $state, $trace, array() );
		}

		if ( 'ask' === $kind && in_array( $slot, self::SLOTS, true ) ) {
			$plan['changing'] = $slot;
			if ( 'interests' === $slot ) {
				$plan['interests'] = array();
			}
			$state['plan'] = $plan;
			list( $chunks, $qr ) = self::ask( $slot, $plan, 'No problem.' );

			return Engine::finish( 'plan_ask', $chunks, $qr, $state, $trace, array() );
		}

		if ( 'toggle' === $kind && 'interests' === $slot ) {
			$v = (string) ( $op['value'] ?? '' );
			if ( isset( self::interests()[ $v ] ) ) {
				$cur = (array) $plan['interests'];
				$plan['interests'] = in_array( $v, $cur, true ) ? array_values( array_diff( $cur, array( $v ) ) ) : array_merge( $cur, array( $v ) );
			}
			$state['plan'] = $plan;
			// The client updates the chips locally; reply quietly with the current state.
			return Engine::finish( 'plan_toggle', array(), array(), $state, $trace, array() );
		}

		if ( 'set' === $kind || 'done' === $kind ) {
			if ( 'set' === $kind ) {
				$val  = $op['value'] ?? '';
				$opts = self::option_map( $slot );
				if ( 'interests' === $slot && is_array( $val ) ) {
					$plan['interests'] = array_values( array_intersect( array_keys( $opts ), $val ) );
				} elseif ( is_string( $val ) && isset( $opts[ $val ] ) ) {
					$plan[ $slot ] = $val;
				}
			}
			if ( 'interests' === $slot && empty( $plan['interests'] ) ) {
				$state['plan'] = $plan;
				list( $chunks, $qr ) = self::ask( 'interests', $plan, 'Pick at least one so I can tailor it.' );

				return Engine::finish( 'plan_ask', $chunks, $qr, $state, $trace, array() );
			}
			$next = self::next_missing( $plan );
			if ( '' !== $plan['changing'] || '' === $next ) {
				// After a change, or when everything is answered: rebuild. Fill an unanswered city as "any".
				if ( '' === $plan['city'] ) {
					$plan['city'] = 'any';
				}
				return self::build_response( $plan, $state, $trace, $drafts );
			}
			$state['plan'] = $plan;
			list( $chunks, $qr ) = self::ask( $next, $plan, 'Got it.' );

			return Engine::finish( 'plan_ask', $chunks, $qr, $state, $trace, array() );
		}

		if ( 'build' === $kind ) {
			if ( '' === $plan['city'] ) {
				$plan['city'] = 'any';
			}

			return self::build_response( $plan, $state, $trace, $drafts );
		}

		$state['plan'] = $plan;

		return Engine::finish( 'plan_ask', array( array( 'text' => 'Shall we start a trip idea?', 'links' => array() ) ), array( array( 'label' => 'Plan a trip', 'plan' => array( 'op' => 'start' ) ) ), $state, $trace, array() );
	}
}
