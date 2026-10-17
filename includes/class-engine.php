<?php
/**
 * Engine: one visitor message in, one structured response out.
 *
 * Order (spec 7): length -> greeting / thanks -> frustration -> politics (safety-intent exception)
 * -> price intent -> retrieval -> brain -> post-filters -> chunking.
 * Used by the REST endpoint and by the admin "Test Alia" box, so both behave identically.
 */

namespace TravelStore\Alia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Engine {

	const MAX_CHUNKS = 4;
	const CHUNK_LEN  = 280;

	/** Built-in wording, used only when no Policy entry exists for a key. */
	private static function defaults() {
		return array(
			'greeting'         => "Hi there, I'm Alia, the virtual travel assistant at Travel Store Oman. I'm happy to answer questions about travelling in Oman, from the best time to visit to what to wear. What would you like to know?",
			'fallback'         => "That's a good question, and I'd rather not guess and risk getting it wrong. Our team will know the answer and they're always happy to help. You can reach them here:",
			'price'            => "I'm afraid I can't share prices here, because they depend on your dates, the hotels you like and the size of your group. You can find the details on {TOUR_PAGE_LINK}, or our team can put together a proper quote for you:",
			'visa_caveat'      => 'Visa rules can change, so for the most up-to-date information please check the Royal Oman Police eVisa website or your nearest Omani embassy before you travel.',
			'politics_decline' => "Thanks for asking, but I can't comment on political matters. I'm a travel assistant, so I'm happiest talking about trips, places and planning. Is there something about Oman I can help you with?",
			'safety'           => "I understand wanting to feel sure before you travel. I don't give opinions on that, and I'm not the best source for current advice. The most reliable step is to check your own government's official travel advice before you book. If you'd like to talk it through with a person, our team is happy to help:",
			'empathy'          => "I'm sorry about the trouble. Let's see how we can sort this out.",
			'volatile_note'    => 'Details can change, so please verify before you travel.',
			'thanks'           => "You're very welcome. Is there anything else you'd like to know about Oman?",
			'goodbye'          => 'Take care, and I hope to help you again soon.',
			'too_long'         => "That's a bit long for me to follow. Could you ask it in a sentence or two?",
			'rate_limit'       => "You're asking quickly. Give me a moment and try again.",
			'handoff'          => 'Our team can help with that. You can reach them here:',
			'ack'              => "Glad that helps. Is there anything else you'd like to know about Oman?",
			'confused'         => "Sorry, I didn't quite catch that. Could you tell me a little more about what you'd like to know?",
			'capabilities'     => "I can answer questions about travelling in Oman, such as the best time to go, what to wear, visas, food and getting around. I can also sketch a trip idea for you. If you'd rather talk to a person, our team is happy to help.",
			'identity'         => "I'm Alia, an automated travel assistant for Travel Store Oman, so I'm not a person. I answer from notes our team has written. If you'd like to talk to someone, our team is happy to help:",
			'contact_ask'      => 'Of course. You can reach our team here:',
			'contact_unknown'  => "I don't have our office address or opening hours to hand, so our team is the best source. You can reach them here:",
			'human'            => 'Of course. Our team would be glad to help. You can reach them here:',
			'joke'             => "I'm much better with travel tips than with jokes. Ask me anything about Oman and I'll do my best.",
			'off_topic'        => "I can only help with travel in Oman, so that one is outside what I know. If you're thinking of visiting Oman, I'm happy to help with that.",
			'wellbeing'        => "I'm doing well, thank you for asking. How can I help with your trip to Oman?",
			'hotels'           => "I don't have hotel recommendations to share, and I'd rather not guess. Our team can suggest places to stay that suit your plans. You can reach them here:",
			'safety_note'      => "For the latest advice, please check your own government's official travel guidance.",
		);
	}

	private static function policy( $key, $state_key = null, array &$state = array() ) {
		$pol = Index::policies();
		$def = self::defaults();
		$set = $pol[ $key ] ?? array();
		if ( ! $set ) {
			return array( 'id' => 0, 'text' => $def[ $key ] ?? '' );
		}
		if ( count( $set ) === 1 || null === $state_key ) {
			return $set[ array_rand( $set ) ];
		}
		$last = isset( $state[ $state_key ] ) ? (int) $state[ $state_key ] : -1;
		$i    = wp_rand( 0, count( $set ) - 1 );
		if ( $i === $last ) {
			$i = ( $i + 1 ) % count( $set );
		}
		$state[ $state_key ] = $i;

		return $set[ $i ];
	}

	/* ------------------------------------------------------------------ */

	public static function respond( $text, array $state = array(), array $opts = array() ) {
		$state = array_merge( array( 'dest' => '', 'topic' => 0, 'n' => 0, 'greeted' => 0, 'pv' => -1, 'op' => -1 ), $state );
		$trace = array( 'filters' => array(), 'matches' => array(), 'tokens' => array(), 'corrected' => array() );
		$text  = trim( (string) $text );

		// Planner operation from a tapped chip.
		if ( ! empty( $opts['plan'] ) && is_array( $opts['plan'] ) ) {
			return Planner::handle( $opts['plan'], $state, $text, ! empty( $opts['drafts'] ) );
		}

		// Quick-reply actions bypass retrieval.
		if ( ! empty( $opts['more'] ) ) {
			return self::more( (int) $opts['more'], $state, $trace );
		}
		if ( ! empty( $opts['pick'] ) ) {
			$index = Index::get();
			$doc   = $index['docs'][ (int) $opts['pick'] ] ?? null;
			if ( $doc ) {
				$trace['filters'][] = 'pick';

				return self::answer( $doc, $state, $trace, 'confident', 1.0, $text );
			}
		}

		$max = (int) Settings::num( 'max_chars', 300 );
		if ( '' === $text ) {
			return self::plain( 'empty', self::policy( 'greeting' )['text'], $state, $trace, array( 'starters' => true ) );
		}
		if ( mb_strlen( $text ) > $max ) {
			$trace['filters'][] = 'too_long';

			return self::plain( 'too_long', self::policy( 'too_long' )['text'], $state, $trace, array() );
		}

		$norm = Text::normalize( $text );

		// Greeting, thanks, goodbye.
		if ( preg_match( '/^(hi|hello|hey|hiya|hallo|salam|salaam|assalam(u)? alaikum|assalamu alaykum|good (morning|afternoon|evening)|greetings|hi alia|hello alia|hey alia)( there| alia)?$/', $norm ) ) {
			$trace['filters'][] = 'greeting';
			$state['greeted']   = 1;

			return self::plain( 'greeting', self::policy( 'greeting' )['text'], $state, $trace, array( 'starters' => true ) );
		}
		if ( preg_match( '/^(thanks|thank you|thank you so much|thanks a lot|thx|cheers|shukran|many thanks|great thanks)( alia)?$/', $norm ) ) {
			$trace['filters'][] = 'thanks';

			return self::plain( 'thanks', self::policy( 'thanks' )['text'], $state, $trace, array( 'starters' => true ) );
		}
		if ( preg_match( '/^((ok|okay|great|thanks|thank you|cheers|no thanks|no thank you|no|nope)( thanks| thank you)?,? )?(bye|bye bye|goodbye|good bye|see you|see you later|that is all|thats all|nothing else|no thats all|im done|all done|thats it|that is it)( alia)?$/', $norm ) ) {
			$trace['filters'][] = 'goodbye';

			return self::plain( 'goodbye', self::policy( 'goodbye' )['text'], $state, $trace, array() );
		}

		// A typed answer while a trip idea is under way ("december", "me and my wife").
		$pf = Planner::free_text( $text, $norm, $state, ! empty( $opts['drafts'] ) );
		if ( $pf ) {
			return $pf;
		}

		// Small talk, "who are you", "what can you do".
		$small = self::small_talk( $text, $norm );
		if ( $small ) {
			$trace['filters'][] = 'small talk: ' . $small[0];

			return self::with_resume( self::plain( $small[3], self::policy( $small[1] )['text'], $state, $trace, $small[2] ), $state );
		}

		// Politics / conflict, with the safety-intent exception.
		$pol = Guardrails::politics_hit( $norm );
		if ( false !== $pol ) {
			if ( Guardrails::safety_intent( $norm ) ) {
				$trace['filters'][] = 'politics+safety -> safety response (matched: ' . $pol . ')';

				return self::plain( 'safety', self::policy( 'safety' )['text'], $state, $trace, array( 'contact' => true ) );
			}
			$trace['filters'][] = 'politics -> decline (matched: ' . $pol . ')';
			$p                  = self::policy( 'politics_decline', 'pv', $state );

			return self::plain( 'politics', $p['text'], $state, $trace, array( 'starters' => true ) );
		}
		if ( Guardrails::safety_now( $norm ) ) {
			$trace['filters'][] = 'safety "right now" -> safety response';

			return self::plain( 'safety', self::policy( 'safety' )['text'], $state, $trace, array( 'contact' => true ) );
		}

		// Frustration / complaint / "a real person".
		if ( Guardrails::frustration( $norm ) ) {
			$trace['filters'][] = 'frustration -> empathy + contact';
			$e                  = self::policy( 'empathy' );

			return self::plain( 'handoff', $e['text'] . ' ' . self::policy( 'handoff' )['text'], $state, $trace, array( 'contact' => true ) );
		}

		if ( Guardrails::human( $norm ) ) {
			$trace['filters'][] = 'talk to a person -> contact';

			return self::plain( 'handoff', self::policy( 'human' )['text'], $state, $trace, array( 'contact' => true ) );
		}
		$ask = self::contact_ask( $norm );
		if ( $ask ) {
			$trace['filters'][] = 'contact question (' . $ask . ')';

			return self::plain( 'contact', self::policy( 'hours' === $ask ? 'contact_unknown' : 'contact_ask' )['text'], $state, $trace, array( 'contact' => true ) );
		}
		if ( self::other_place( $norm ) ) {
			$trace['filters'][] = 'place outside Oman -> off topic';

			return self::with_resume( self::plain( 'off_topic', self::policy( 'off_topic' )['text'], $state, $trace, array( 'starters' => true ) ), $state );
		}

		// Trip planner: a request to plan (not "do you arrange itineraries?", which is an FAQ).
		if ( Planner::is_intent( $norm ) ) {
			$trace['filters'][] = 'planner intent';

			return Planner::handle( array( 'op' => 'start' ), $state, $text, ! empty( $opts['drafts'] ) );
		}

		$price = Guardrails::price_intent( $norm );
		if ( $price ) {
			$trace['filters'][] = 'price intent';
		}

		// Retrieval.
		$index           = Index::get();
		$tokens          = Text::tokens( $text );
		list( $tok, $fx ) = Retrieval::correct( $tokens, $index['vocab'] ?? array() );
		if ( '' !== $state['dest'] && preg_match( '/(?<![a-z])(there|it|that place|those|them)(?![a-z])/', $norm ) ) {
			$tok = array_values( array_unique( array_merge( $tok, Text::tokens( $state['dest'] ) ) ) );
			$trace['filters'][] = 'context: added destination "' . $state['dest'] . '"';
		}
		$trace['tokens']    = $tok;
		$trace['corrected'] = $fx;
		$matches            = Retrieval::search( $index, $tok, (string) $state['dest'] );
		$trace['matches']   = $matches;
		$decision           = Retrieval::decide( $matches );

		$brain  = new KnowledgeBrain();
		$result = $brain->respond(
			array(
				'text'       => $text,
				'tokens'     => $tok,
				'matches'    => $matches,
				'outcome'    => $decision['outcome'],
				'candidates' => $decision['candidates'],
				'state'      => $state,
			)
		);
		$top = $result['entry_ids'] ? ( $index['docs'][ $result['entry_ids'][0] ] ?? null ) : null;

		// Price intent: never answer with a price; general money facts are still answered.
		if ( $price ) {
			$money_ok = $top && 'confident' === $result['outcome'] && $result['score'] >= 0.85 && in_array( 'money_general', $top['fl'], true );
			if ( ! $money_ok ) {
				$trace['filters'][] = 'price -> price response';
				$link               = ( $top && '' !== $top['link'] && in_array( $top['type'], array( 'destination', 'activity' ), true ) ) ? $top['link'] : '';

				return self::plain( 'price', self::policy( 'price' )['text'], $state, $trace, array( 'contact' => true, 'tour_link' => $link ), array( 'entry_id' => $top ? $top['id'] : 0, 'score' => $result['score'] ) );
			}
		}

		if ( 'confident' === $result['outcome'] && $top ) {
			if ( 'price' === $top['use'] ) {
				$trace['filters'][] = 'entry uses the price response';

				return self::plain( 'price', self::policy( 'price' )['text'], $state, $trace, array( 'contact' => true ), array( 'entry_id' => $top['id'], 'score' => $result['score'] ) );
			}
			if ( 'safety' === $top['use'] ) {
				$trace['filters'][] = 'entry uses the safety response';

				return self::plain( 'safety', self::policy( 'safety' )['text'], $state, $trace, array( 'contact' => true ), array( 'entry_id' => $top['id'], 'score' => $result['score'] ) );
			}

			return self::with_resume( self::answer( $top, $state, $trace, 'confident', $result['score'], $text ), $state );
		}

		if ( 'confident' !== $result['outcome'] && preg_match( '/(?<![a-z])(hotels?|resorts?|hostels?|accommodation|lodging|where to stay|where should (i|we) stay|places? to stay|somewhere to stay|guest ?houses?|airbnb)(?![a-z])/', $norm ) ) {
			$trace['filters'][] = 'hotel question without an entry -> team';

			return self::with_resume( self::plain( 'hotels', self::policy( 'hotels' )['text'], $state, $trace, array( 'contact' => true ), array( 'score' => $result['score'] ) ), $state );
		}

		if ( 'ambiguous' === $result['outcome'] ) {
			$chips = array();
			foreach ( $result['entry_ids'] as $eid ) {
				$d = $index['docs'][ $eid ] ?? null;
				if ( $d ) {
					$chips[] = array( 'label' => self::label( $d ), 'action' => 'pick', 'entry' => (int) $eid );
				}
			}

			return self::finish(
				'ambiguous',
				array( array( 'text' => 'I want to make sure I give you the right answer. Did you mean one of these?', 'links' => array() ) ),
				$chips,
				$state,
				$trace,
				array( 'entry_id' => $result['entry_ids'][0] ?? 0, 'score' => $result['score'] )
			);
		}

		// A statement about their trip ("we will be in Oman in March with the kids"): start the planner with what they said.
		if ( ! Planner::is_active( $state ) && Settings::flag( 'plan_on' ) && false === strpos( $text, '?' )
			&& preg_match( '/\b(i|we|im|were|ill|well)\b.{0,20}\b(will be|am|are|be|coming|visiting|visit|travelling|traveling|going|planning|arriving|landing|thinking of|want to come|would like to come|heading)\b.{0,40}\b(oman|muscat|salalah|nizwa|there|trip|holiday|vacation)\b/', $norm )
			&& Planner::parse( $norm, '' ) ) {
			$trace['filters'][] = 'trip statement -> planner';

			return Planner::handle( array( 'op' => 'start' ), $state, $text, ! empty( $opts['drafts'] ) );
		}

		// Nothing found while a trip idea is under way: ask the current question again instead of the fallback.
		if ( Planner::is_active( $state ) ) {
			$trace['filters'][] = 'no match during trip idea -> ask again';

			return Planner::handle( array( 'op' => 'resume', 'lead' => "Sorry, I didn't catch that." ), $state, '', ! empty( $opts['drafts'] ) );
		}

		// Nothing found: fallback with contact options (also goes to the unanswered log).
		$trace['filters'][] = 'no confident match -> fallback';

		return self::plain( 'none', self::policy( 'fallback' )['text'], $state, $trace, array( 'contact' => true ), array( 'score' => $result['score'] ) );
	}

	/** Offer a way back to the trip idea after a side question. */
	private static function with_resume( array $resp, array $state ) {
		if ( Planner::is_active( $state ) ) {
			$resp['quick_replies'][] = array( 'label' => 'Carry on with my trip idea', 'plan' => array( 'op' => 'resume' ) );
		}

		return $resp;
	}

	/** Non-question chatter. @return array|null [ name, policy key, add, outcome ] */
	private static function small_talk( $text, $norm ) {
		if ( '' === $norm ) {
			return array( 'unclear', 'confused', array( 'starters' => true ), 'confused' );
		}
		if ( preg_match( '/^((hi|hello|hey|hiya|good (morning|afternoon|evening))( there)?( alia)?,? )?(how are you( doing)?|how r u|how do you do|hows it going|how is it going|whats up|how are things|how have you been)( today)?( alia)?$/', $norm ) ) {
			return array( 'wellbeing', 'wellbeing', array( 'starters' => true ), 'greeting' );
		}
		if ( preg_match( '/^(test|testing|hmm+|huh|um+|uh+|what|eh)$/', $norm ) ) {
			return array( 'unclear', 'confused', array( 'starters' => true ), 'confused' );
		}
		if ( preg_match( '/^(ok|okay|ok then|alright|all right|right|great|cool|nice|good|fine|sure|yes|yeah|yep|got it|i see|understood|perfect|wow|awesome|lovely|sounds good|interesting|thats helpful|helpful|brilliant|excellent|continue|ok continue|carry on|go on|go ahead)( thanks| thank you)?$/', $norm ) ) {
			return array( 'acknowledgement', 'ack', array( 'starters' => true ), 'thanks' );
		}
		if ( preg_match( '/^(help|help me|can you help|can you help me|i need help|what can you do|what do you do|how can you help|how can you help me|what can i ask|what can i ask you|what are you for|how does this work)$/', $norm ) ) {
			return array( 'capabilities', 'capabilities', array( 'starters' => true, 'contact' => true ), 'greeting' );
		}
		if ( preg_match( '/\b(are you|r u|is this|am i (talking|speaking|chatting|typing) (to|with)) (a |an |the )?(real |actual |live |proper )?(person|human|bot|robot|ai|machine|chatbot|computer|someone|alia)\b|^(who|what) (are|r) (you|u)$|^who is this$|^are you (alia|real)$/', $norm ) ) {
			return array( 'identity', 'identity', array( 'contact' => true ), 'identity' );
		}
		if ( preg_match( '/\b(joke|jokes|riddle|sing|poem|song|funny)\b/', $norm ) ) {
			return array( 'joke', 'joke', array( 'starters' => true ), 'off_topic' );
		}

		return null;
	}

	/** The visitor wants our contact details, address or hours. @return string '' | 'contact' | 'hours' */
	private static function contact_ask( $norm ) {
		if ( preg_match( '/\b(your|ur) (office|address|location|opening hours|hours|working hours)\b|\b(office|business|opening|working) hours\b|\bwhere (are you|is your office|is the office|is travel store|can i find you|are you based|are you located)\b|\bare you open\b|\bwhat time do you (open|close)\b|\bwhen (are|do) you (open|close)\b/', $norm ) ) {
			return 'hours';
		}
		if ( preg_match( '/\b(your|ur) (phone|telephone|mobile|cell|whatsapp|whats app|email|e mail|number|contact|website|details)\b|\bhow (can|do|could|would|should) (i|we) (contact|reach|call|email|message|phone|text|speak to|get hold of|get in touch with|book with)\b|\b(contact|call|phone|whatsapp|email|message|text|reach|ring) (you|us|the team|your team|travel store)\b|\b(whatsapp|whats app) (number|me|you)\b|\bcontact (details|info|information|number|page|form)\b|\bemail address\b|\bcan i (call|phone|ring|whatsapp|email|message) (you|someone|the team)\b/', $norm ) ) {
			return 'contact';
		}

		return '';
	}

	/**
	 * A place outside Oman in a message that has no Oman place and no trip words
	 * ("weather in London"). Trip questions that mention another place ("from London", "Dubai to Muscat") stay in.
	 */
	private static function other_place( $norm ) {
		$places = 'london|paris|dubai|abu dhabi|sharjah|doha|qatar|riyadh|jeddah|saudi|mecca|makkah|medina|india|delhi|mumbai|goa|usa|america|new york|los angeles|uk|britain|england|scotland|ireland|germany|berlin|france|spain|madrid|italy|rome|greece|australia|sydney|canada|toronto|japan|tokyo|china|beijing|thailand|bangkok|egypt|cairo|turkey|istanbul|singapore|malaysia|pakistan|karachi|lahore|bahrain|kuwait|jordan|amman|morocco|bali|maldives|sri lanka|nepal|kenya|tanzania|brazil|mexico|russia|netherlands|amsterdam|switzerland|sweden|norway|denmark';
		if ( ! preg_match( '/(?<![a-z])(' . $places . ')(?![a-z])/', $norm ) ) {
			return false;
		}
		if ( preg_match( '/(?<![a-z])(oman|omani|muscat|salalah|nizwa|sohar|sur|musandam|khasab|wahiba|sharqiya|jebel|jabal|wadi|bahla|ibri|duqm|barka|rustaq|ras al hadd|masirah|dhofar|khareef|batinah|dakhiliyah)(?![a-z])/', $norm ) ) {
			return false;
		}
		if ( preg_match( '/(?<![a-z])(visit|visiting|travel|travelling|traveling|trip|trips|fly|flying|flight|flights|drive|driving|combine|stopover|layover|from|to|via|near|nearby|between|border|cross|crossing|visa|passport|nationals?|citizens?|resident|embassy|plan|planning|holiday|vacation|tour)(?![a-z])/', $norm ) ) {
			return false;
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Building replies                                                    */
	/* ------------------------------------------------------------------ */

	private static function label( array $doc ) {
		$q = $doc['qs'][0] ?? $doc['title'];
		$q = rtrim( (string) $q );

		return mb_strlen( $q ) > 60 ? mb_substr( $q, 0, 57 ) . '...' : $q;
	}

	/** Answer from an index doc, with every entry-driven add-on. */
	private static function answer( array $doc, array $state, array $trace, $outcome, $score, $text ) {
		$money = in_array( 'money_general', $doc['fl'], true );
		$body  = self::template( $doc['ans'], array( 'tour_link' => $doc['link'] ), $links_from_text );
		$body  = Guardrails::scrub_prices( $body, $money );
		$chunks = self::chunk( $body, 3 );
		if ( ! $chunks ) {
			$chunks = array( array( 'text' => self::policy( 'fallback' )['text'], 'links' => array() ) );
		}
		// Opener on every second reply.
		$op_i = -1;
		if ( (int) $state['n'] > 0 && 1 === ( (int) $state['n'] % 2 ) ) {
			$ops = Settings::lines( 'openers' );
			if ( $ops ) {
				$i = ( (int) $state['op'] + 1 + wp_rand( 0, max( 0, count( $ops ) - 1 ) ) ) % count( $ops );
				if ( $i === (int) $state['op'] && count( $ops ) > 1 ) {
					$i = ( $i + 1 ) % count( $ops );
				}
				$state['op'] = $i;
				$chunks[0]['text'] = $ops[ $i ] . ' ' . $chunks[0]['text'];
			}
		}
		$last = count( $chunks ) - 1;
		if ( in_array( 'visa', $doc['fl'], true ) ) {
			$cav = self::template( self::policy( 'visa_caveat' )['text'], array(), $ignore );
			$cav = preg_replace( '/\s*\(https?:[^)]*\)/', '', $cav );
			$vl  = array();
			$u   = Settings::url( Settings::get( 'visa_url' ) );
			if ( '' !== $u ) {
				$vl[] = array( 'label' => (string) Settings::get( 'visa_label' ) ?: 'Royal Oman Police eVisa website', 'url' => $u, 'kind' => 'visa' );
			}
			$chunks[] = array( 'text' => $cav, 'links' => $vl );
		} elseif ( $doc['vol'] ) {
			$chunks[ $last ]['text'] .= ' ' . self::policy( 'volatile_note' )['text'];
		}
		if ( in_array( 'safety', $doc['fl'], true ) ) {
			$chunks[ $last ]['text'] .= ' ' . self::defaults()['safety_note'];
		}
		if ( in_array( 'contact', $doc['fl'], true ) ) {
			$chunks[] = array( 'text' => 'If you would like to talk it through, our team is happy to help:', 'links' => array_values( Settings::contact_links() ) );
		}
		if ( ! empty( $doc['link'] ) ) {
			$u = Settings::url( $doc['link'] );
			if ( '' !== $u ) {
				$chunks[ count( $chunks ) - 1 ]['links'][] = array( 'label' => 'See the page', 'url' => $u, 'kind' => 'page' );
			}
		}

		$qr = array();
		if ( '' !== trim( $doc['det'] ) ) {
			$qr[] = array( 'label' => 'Tell me more', 'action' => 'more', 'entry' => (int) $doc['id'] );
		}
		foreach ( array_slice( $doc['fu'], 0, 3 ) as $f ) {
			$qr[] = array( 'label' => $f, 'text' => $f );
		}

		// Session patch: topic and destination.
		$state['topic'] = (int) $doc['id'];
		if ( ! empty( $doc['reg'][0] ) ) {
			$state['dest'] = $doc['reg'][0];
		}

		return self::finish( $outcome, $chunks, $qr, $state, $trace, array( 'entry_id' => (int) $doc['id'], 'score' => $score, 'money' => $money, 'feedback' => true ) );
	}

	private static function more( $id, array $state, array $trace ) {
		$index = Index::get();
		$doc   = $index['docs'][ $id ] ?? null;
		$trace['filters'][] = 'more';
		if ( ! $doc || '' === trim( $doc['det'] ) ) {
			return self::plain( 'more', "That's all I have on this one. Is there something else I can help with?", $state, $trace, array( 'starters' => true ) );
		}
		$money  = in_array( 'money_general', $doc['fl'], true );
		$body   = Guardrails::scrub_prices( $doc['det'], $money );
		$chunks = self::chunk( $body, 4 );
		$qr     = array();
		foreach ( array_slice( $doc['fu'], 0, 3 ) as $f ) {
			$qr[] = array( 'label' => $f, 'text' => $f );
		}

		return self::finish( 'more', $chunks, $qr, $state, $trace, array( 'entry_id' => $id, 'score' => 1.0, 'money' => $money ) );
	}

	/** A reply built from a template text, optionally with the contact options and the starter chips. */
	private static function plain( $outcome, $template, array $state, array $trace, array $add, array $meta = array() ) {
		$links = array();
		$body  = self::template( $template, array( 'tour_link' => $add['tour_link'] ?? '' ), $links );
		$chunks = self::chunk( $body, 3 );
		if ( ! $chunks ) {
			$chunks = array( array( 'text' => '', 'links' => array() ) );
		}
		$last = count( $chunks ) - 1;
		foreach ( $links as $l ) {
			$chunks[ $last ]['links'][] = $l;
		}
		if ( ! empty( $add['contact'] ) ) {
			foreach ( Settings::contact_links() as $key => $l ) {
				$have = false;
				foreach ( $chunks[ $last ]['links'] as $x ) {
					if ( $x['url'] === $l['url'] ) {
						$have = true;
					}
				}
				if ( ! $have ) {
					$chunks[ $last ]['links'][] = $l;
				}
			}
		}
		$qr = array();
		if ( ! empty( $add['starters'] ) ) {
			foreach ( Settings::lines( 'starters' ) as $s ) {
				$qr[] = array( 'label' => $s, 'text' => $s );
			}
		}

		return self::finish( $outcome, $chunks, $qr, $state, $trace, $meta );
	}

	/**
	 * Fill a template: lines made only of "label: {X_LINK}" become link buttons; inline placeholders
	 * are removed (or replaced for the tour page). Returns the clean text; link buttons via $links.
	 */
	private static function template( $text, array $vars, &$links ) {
		$links   = array();
		$contact = Settings::contact_links();
		$text    = trim( (string) $text );
		$text    = preg_replace( '/["\x{201D}]\s*\+\s*\[CONTACT\]\s*$/u', '', $text );
		$text    = trim( $text, "\"\u{201C}\u{201D}" );
		$lines   = preg_split( '/\r\n|\r|\n/', $text );
		$keep    = array();
		foreach ( (array) $lines as $line ) {
			if ( preg_match( '/\{(PHONE|WHATSAPP|EMAIL|FORM)_LINK\}/', $line, $m ) ) {
				$key = $m[1] . '_LINK';
				if ( isset( $contact[ $key ] ) ) {
					$links[] = $contact[ $key ];
				}
				continue;
			}
			$keep[] = $line;
		}
		$text = trim( implode( "\n", $keep ) );
		if ( false !== strpos( $text, '{TOUR_PAGE_LINK}' ) ) {
			$u = Settings::url( $vars['tour_link'] ?? '' );
			if ( '' === $u ) {
				$u = Settings::url( Settings::get( 'tour_index_url' ) );
			}
			$text = str_replace( '{TOUR_PAGE_LINK}', 'our tours page', $text );
			if ( '' !== $u ) {
				$links[] = array( 'label' => 'See our tours', 'url' => $u, 'kind' => 'page' );
			}
		}
		$text = preg_replace( '/\{[A-Z_]+\}/', '', $text );
		$text = str_replace( array( '[CONTACT]', '+ [CONTACT]' ), '', $text );

		return Guardrails::tidy( $text );
	}

	/** Split into at most $max short messages at sentence boundaries. */
	public static function chunk( $text, $max = 3 ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}
		$out = array();
		$cur = '';
		foreach ( preg_split( '/\n{1,}/', $text ) as $para ) {
			foreach ( Text::sentences( $para ) as $s ) {
				if ( '' !== $cur && mb_strlen( $cur ) + 1 + mb_strlen( $s ) > self::CHUNK_LEN ) {
					$out[] = $cur;
					$cur   = $s;
				} else {
					$cur = '' === $cur ? $s : $cur . ' ' . $s;
				}
			}
			if ( '' !== $cur && count( $out ) < $max - 1 ) {
				// Paragraph break closes a chunk when it is already a decent size.
				if ( mb_strlen( $cur ) > 120 ) {
					$out[] = $cur;
					$cur   = '';
				}
			}
		}
		if ( '' !== $cur ) {
			$out[] = $cur;
		}
		// Merge extras into the last allowed chunk.
		while ( count( $out ) > $max ) {
			$tail                 = array_pop( $out );
			$out[ count( $out ) - 1 ] .= ' ' . $tail;
		}

		return array_map(
			static function ( $t ) {
				return array( 'text' => $t, 'links' => array() );
			},
			$out
		);
	}

	/** Cards come from entries only: prices are stripped and only plain web addresses are kept. */
	private static function clean_cards( array $cards ) {
		$out = array();
		foreach ( $cards as $c ) {
			$out[] = array(
				'title'  => Guardrails::scrub_prices( (string) ( $c['title'] ?? '' ), false ),
				'blurb'  => Guardrails::scrub_prices( (string) ( $c['blurb'] ?? '' ), false ),
				'image'  => esc_url_raw( (string) ( $c['image'] ?? '' ) ),
				'link'   => esc_url_raw( (string) ( $c['link'] ?? '' ) ),
				'nights' => (int) ( $c['nights'] ?? 0 ),
				'note'   => Guardrails::scrub_prices( (string) ( $c['note'] ?? '' ), false ),
			);
		}

		return $out;
	}

	/** Apply the post-filters and assemble the response object. */
	public static function finish( $outcome, array $chunks, array $qr, array $state, array $trace, array $meta ) {
		$allowed = array_map(
			static function ( $l ) {
				return $l['url'];
			},
			array_values( Settings::contact_links() )
		);
		$allowed[] = Settings::url( Settings::get( 'visa_url' ) );
		$allowed[] = Settings::url( Settings::get( 'tour_index_url' ) );
		foreach ( $chunks as $c ) {
			foreach ( $c['links'] as $l ) {
				$allowed[] = $l['url'];
			}
		}
		$money = ! empty( $meta['money'] );
		$out   = array();
		foreach ( $chunks as $c ) {
			$t = Guardrails::scrub_prices( (string) $c['text'], $money );
			$t = Guardrails::strip_urls( $t, $allowed );
			$t = Guardrails::tidy( $t );
			if ( mb_strlen( $t ) > 700 ) {
				$t = mb_substr( $t, 0, 697 ) . '...';
			}
			if ( '' === $t && empty( $c['links'] ) ) {
				continue;
			}
			$out[] = array( 'text' => $t, 'links' => array_values( $c['links'] ) );
		}
		$state['n'] = (int) $state['n'] + 1;

		return array(
			'outcome'       => $outcome,
			'chunks'        => $out,
			'quick_replies' => array_values( $qr ),
			'state'         => $state,
			'entry'         => (int) ( $meta['entry_id'] ?? 0 ),
			'score'         => round( (float) ( $meta['score'] ?? 0 ), 3 ),
			'feedback'      => ! empty( $meta['feedback'] ),
			'cards'         => self::clean_cards( (array) ( $meta['cards'] ?? array() ) ),
			'cards_after'   => (int) ( $meta['cards_after'] ?? 0 ),
			'trace'         => $trace,
		);
	}
}
