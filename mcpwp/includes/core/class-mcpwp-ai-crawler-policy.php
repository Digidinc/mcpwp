<?php
/**
 * Per-bot AI crawler allow/deny policy, emitted into robots.txt.
 *
 * Distinguishes SEARCH crawlers (which fetch pages that can be cited in an
 * AI answer right now) from TRAINING crawlers (which harvest content for a
 * future model and have no effect on today's answers). Blocking the wrong
 * one is the most common mistake site owners make here, so every surface
 * that reads this policy (REST, MCP tool, admin UI) carries that
 * distinction rather than a bare allow/deny toggle.
 *
 * Bot names and search-vs-training classification for OAI-SearchBot,
 * GPTBot, ChatGPT-User (OpenAI), ClaudeBot, Claude-SearchBot, Claude-User
 * (Anthropic), PerplexityBot, Perplexity-User (Perplexity) and
 * Google-Extended (Google) are sourced from each vendor's own bot
 * documentation (see docs/scratchpad research, 2026-09-26). Applebot-
 * Extended, CCBot and Bytespider are included because every comparable
 * WordPress SEO plugin's AI-bot list carries them, but their
 * classification here is MCPWP's own conservative default (all three are
 * training-only data harvesters with no live "answers" surface to lose),
 * not a claim sourced from an opened vendor doc — correct it via the
 * `mcpwp_ai_crawler_bots` filter if a vendor publishes something more
 * specific.
 *
 * Default policy is allow-everything: shipping this feature must not
 * change any site's crawlability on its own.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI crawler allow/deny policy.
 */
class Mcpwp_AI_Crawler_Policy {

	const OPTION_NAME = 'mcpwp_ai_crawler_policy';

	const TYPE_SEARCH   = 'search';
	const TYPE_TRAINING = 'training';

	const BLOCK_START = '# MCPWP AI crawler policy — start';
	const BLOCK_END   = '# MCPWP AI crawler policy — end';

	/**
	 * Known bots this feature can allow/deny, keyed by exact User-agent
	 * token as it appears in a crawler's request header.
	 *
	 * @return array<string, array{type:string, vendor:string, label:string}>
	 */
	public static function known_bots() {
		$bots = array(
			'OAI-SearchBot'    => array(
				'type'   => self::TYPE_SEARCH,
				'vendor' => 'OpenAI',
				'label'  => 'ChatGPT search (live answers)',
			),
			'GPTBot'           => array(
				'type'   => self::TYPE_TRAINING,
				'vendor' => 'OpenAI',
				'label'  => 'OpenAI model training',
			),
			'ChatGPT-User'     => array(
				'type'   => self::TYPE_SEARCH,
				'vendor' => 'OpenAI',
				'label'  => 'ChatGPT user-initiated page fetch',
			),
			'ClaudeBot'        => array(
				'type'   => self::TYPE_TRAINING,
				'vendor' => 'Anthropic',
				'label'  => 'Anthropic model training',
			),
			'Claude-SearchBot' => array(
				'type'   => self::TYPE_SEARCH,
				'vendor' => 'Anthropic',
				'label'  => 'Claude search index',
			),
			'Claude-User'      => array(
				'type'   => self::TYPE_SEARCH,
				'vendor' => 'Anthropic',
				'label'  => 'Claude user-initiated page fetch',
			),
			'PerplexityBot'    => array(
				'type'   => self::TYPE_SEARCH,
				'vendor' => 'Perplexity',
				'label'  => 'Perplexity search index',
			),
			'Perplexity-User'  => array(
				'type'   => self::TYPE_SEARCH,
				'vendor' => 'Perplexity',
				'label'  => 'Perplexity user-initiated page fetch (Perplexity documents this bot as generally ignoring robots.txt)',
			),
			'Google-Extended'  => array(
				'type'   => self::TYPE_TRAINING,
				'vendor' => 'Google',
				'label'  => 'Gemini/model training and grounding outside Search — does not affect Google Search or AI Overviews',
			),
			'Applebot-Extended' => array(
				'type'   => self::TYPE_TRAINING,
				'vendor' => 'Apple',
				'label'  => 'Apple Intelligence model training (distinct from Applebot, which serves Search/Siri)',
			),
			'CCBot'            => array(
				'type'   => self::TYPE_TRAINING,
				'vendor' => 'Common Crawl',
				'label'  => 'Common Crawl corpus, widely reused to train other models',
			),
			'Bytespider'       => array(
				'type'   => self::TYPE_TRAINING,
				'vendor' => 'ByteDance',
				'label'  => 'ByteDance model training',
			),
		);

		return apply_filters( 'mcpwp_ai_crawler_bots', $bots );
	}

	/**
	 * Stored policy, merged with allow-all defaults for every known bot.
	 *
	 * @return array<string, string> Bot => 'allow'|'deny'.
	 */
	public static function get_policy() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$policy = array();
		foreach ( array_keys( self::known_bots() ) as $bot ) {
			$policy[ $bot ] = ( isset( $stored[ $bot ] ) && 'deny' === $stored[ $bot ] ) ? 'deny' : 'allow';
		}

		return $policy;
	}

	/**
	 * Merge and persist policy updates. Only known bot names are accepted;
	 * unknown ones are reported back so a typo in an agent's call is never
	 * silently ignored.
	 *
	 * @param array<string, string> $updates Bot => 'allow'|'deny'.
	 * @return array{policy: array, applied: array, rejected: array}
	 */
	public static function set_policy( $updates ) {
		$known   = self::known_bots();
		$current = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$applied  = array();
		$rejected = array();

		foreach ( (array) $updates as $bot => $value ) {
			$bot = (string) $bot;
			if ( ! isset( $known[ $bot ] ) ) {
				$rejected[ $bot ] = 'unknown bot name';
				continue;
			}
			$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
			if ( ! in_array( $value, array( 'allow', 'deny' ), true ) ) {
				$rejected[ $bot ] = 'value must be "allow" or "deny"';
				continue;
			}
			$current[ $bot ] = $value;
			$applied[ $bot ] = $value;
		}

		update_option( self::OPTION_NAME, $current );

		return array(
			'policy'   => self::get_policy(),
			'applied'  => $applied,
			'rejected' => $rejected,
		);
	}

	/**
	 * A physical robots.txt file at the site root makes WordPress's virtual
	 * robots.txt (and therefore the `robots_txt` filter this policy relies
	 * on) unreachable — WordPress only serves the virtual file when no
	 * physical one exists. Callers must surface this rather than silently
	 * doing nothing.
	 *
	 * @return bool
	 */
	public static function physical_robots_file_exists() {
		return file_exists( trailingslashit( ABSPATH ) . 'robots.txt' );
	}

	/**
	 * Build the denylist block for robots.txt. Idempotent: skips a bot
	 * already mentioned anywhere in the existing output (case-insensitive)
	 * so this never duplicates a directive an SEO plugin's own robots.txt
	 * filter already emitted for the same agent, and calling this twice on
	 * the same $output never doubles the block.
	 *
	 * @param string $output Robots.txt content assembled so far.
	 * @return string Lines to append, or '' when there is nothing to add.
	 */
	public static function build_block( $output ) {
		if ( false !== stripos( (string) $output, self::BLOCK_START ) ) {
			return '';
		}

		$policy = self::get_policy();
		$denied = array_keys( array_filter( $policy, static function ( $v ) {
			return 'deny' === $v;
		} ) );

		if ( empty( $denied ) ) {
			return '';
		}

		$lines   = array();
		$lines[] = self::BLOCK_START;
		foreach ( $denied as $bot ) {
			if ( false !== stripos( (string) $output, 'user-agent: ' . $bot ) ) {
				// Some other filter (an SEO plugin's own robots.txt output)
				// already carries a directive for this exact agent —
				// cooperate rather than emit a second, possibly
				// contradictory block for it.
				continue;
			}
			$lines[] = 'User-agent: ' . $bot;
			$lines[] = 'Disallow: /';
		}
		$lines[] = self::BLOCK_END;

		// Every denied bot was already covered by someone else's output.
		if ( 2 === count( $lines ) ) {
			return '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Append the policy block to robots.txt output, if any.
	 *
	 * @param string $output Robots.txt content assembled so far.
	 * @return string
	 */
	public static function append_to_robots( $output ) {
		$block = self::build_block( $output );
		if ( '' === $block ) {
			return $output;
		}

		$output = rtrim( (string) $output, "\n" );
		return ( '' !== $output ? $output . "\n" : '' ) . $block . "\n";
	}
}
