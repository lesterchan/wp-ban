<?php
/**
 * The Settings screen for WP-Ban.
 *
 * Before 2.0.0 this was a 470 line procedural page requiring itself through the
 * legacy "plugin file as menu slug" form, hand-rolling two $_POST handlers and
 * eight update_option() calls.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders Settings -> Ban.
 *
 * One page, three tabs. Stats is the counters -- a list table with its own form
 * and its own nonce, and the tab the screen opens on, because reading the
 * counters is what somebody comes here to do. Settings is the trusted header and
 * the six ban lists. Templates is the banned message, which is a whole HTML
 * document and buried everything above it when it sat inline.
 *
 * A tab is a rendering decision, not a storage one: there is one
 * register_setting(), one group and one wp_ban_options row behind all three,
 * and the only thing the tab changes is which sections do_settings_sections()
 * is asked for. What that costs is in WP_Ban_Options::sanitize().
 */
class WP_Ban_Settings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE = 'wp-ban';

	/**
	 * Settings group passed to register_setting()/settings_fields().
	 *
	 * The group is spelled the same as the row it writes, so there is one
	 * string to remember rather than two that have to be kept in step.
	 *
	 * @var string
	 */
	const GROUP = 'wp_ban_options';

	/**
	 * Capability required to reach this plugin's admin surface.
	 *
	 * WP-Ban has no custom capability of its own: deciding who may not read a
	 * site is a site configuration decision, so it takes the same capability
	 * as every other settings screen. Every check goes through
	 * capability(), never through the constant directly.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The tab shown when the request names none, and the one the page opens on.
	 *
	 * The counters come first because they are what somebody opens this screen
	 * to look at; the lists behind them are edited far less often than they are
	 * consulted.
	 *
	 * @var string
	 */
	const DEFAULT_TAB = 'stats';

	/**
	 * The section explaining how the visitor's address is resolved.
	 *
	 * @var string
	 */
	const SECTION_PROXY = 'wp_ban_proxy';

	/**
	 * The six ban list textareas.
	 *
	 * @var string
	 */
	const SECTION_LISTS = 'wp_ban_lists';

	/**
	 * The banned message template.
	 *
	 * @var string
	 */
	const SECTION_MESSAGE = 'wp_ban_message';

	/**
	 * The statistics table's "plural" name, as passed to WP_List_Table.
	 *
	 * @var string
	 */
	const STATS_PLURAL = 'wp-ban-stats';

	/**
	 * Nonce action for the statistics form.
	 *
	 * WP_List_Table::display_tablenav() emits its own _wpnonce for
	 * "bulk-{$plural}". A second wp_nonce_field() in the same form would be
	 * overridden by it -- both inputs are named _wpnonce, and the last one
	 * posted wins -- so every bulk action failed its referer check. Use the
	 * table's nonce rather than competing with it.
	 *
	 * @var string
	 */
	const STATS_NONCE = 'bulk-' . self::STATS_PLURAL;

	/**
	 * The hook suffix admin_enqueue_scripts hands back for our screen.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Hook the screen up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_wp_ban_preview', array( __CLASS__, 'ajax_preview' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( WP_BAN_MAIN_FILE ),
			array( __CLASS__, 'action_links' )
		);
	}

	/**
	 * Add a Settings link on the Plugins screen row.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				// The Settings tab, not the screen's default: somebody who
				// clicked "Settings" came to change one, not to read counters.
				esc_url( self::url( 'settings' ) ),
				esc_html__( 'Settings', 'wp-ban' )
			)
		);

		return $links;
	}

	/**
	 * The settings screen's URL, optionally on one of its tabs.
	 *
	 * @param string $tab Tab slug, or '' for the screen's own address.
	 * @return string
	 */
	public static function url( $tab = '' ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );

		return '' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * The screen's tabs, in the order they are shown.
	 *
	 * Named for what they hold and nothing else: the heading above them already
	 * says which plugin this is, so "Ban Settings" would only repeat it.
	 *
	 * @return array<string, string>
	 */
	public static function tabs() {
		return array(
			'stats'     => __( 'Stats', 'wp-ban' ),
			'settings'  => __( 'Settings', 'wp-ban' ),
			'templates' => __( 'Templates', 'wp-ban' ),
		);
	}

	/**
	 * Which tab this request is asking for.
	 *
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which tab to draw; nothing else is read from the request and nothing is written.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::DEFAULT_TAB;

		return array_key_exists( $tab, self::tabs() ) ? $tab : self::DEFAULT_TAB;
	}

	/**
	 * The Settings API page a tab's sections are registered against.
	 *
	 * Each tab is its own page as far as do_settings_sections() is concerned,
	 * which is what stops one tab drawing the other's fields. It is not a second
	 * settings page: there is still one register_setting(), one group and one
	 * option row behind all three.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function tab_page( $tab ) {
		return self::PAGE . '-' . $tab;
	}

	/**
	 * The capability required for one part of the admin surface.
	 *
	 * One filter for every check, so a site that delegates ban management to a
	 * role below administrator does it in one place and cannot accidentally
	 * open the settings form while closing the preview.
	 *
	 * @param string $context One of 'screen', 'stats' or 'preview'.
	 * @return string
	 */
	public static function capability( $context ) {
		/**
		 * Filters the capability required to reach WP-Ban's admin surface.
		 *
		 * @since 2.0.0
		 *
		 * @param string $capability Capability name.
		 * @param string $context    One of 'screen', 'stats' or 'preview'.
		 */
		return (string) apply_filters( 'wp_ban_capability', self::CAPABILITY, $context );
	}

	/**
	 * Register the submenu page.
	 *
	 * @return void
	 */
	public static function add_page() {
		self::$hook_suffix = add_options_page(
			__( 'Ban', 'wp-ban' ),
			__( 'WP-Ban', 'wp-ban' ),
			self::capability( 'screen' ),
			self::PAGE,
			array( __CLASS__, 'render' )
		);

		if ( self::$hook_suffix ) {
			add_action( 'load-' . self::$hook_suffix, array( __CLASS__, 'handle_stats_actions' ) );
		}
	}

	/**
	 * Register the setting, sections and fields.
	 *
	 * @return void
	 */
	public static function register() {
		// Activation does not fire when a plugin is updated, so the upgrade is
		// driven from here too.
		WP_Ban_Options::maybe_upgrade();

		register_setting(
			self::GROUP,
			WP_Ban_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WP_Ban_Options', 'sanitize' ),
				'default'           => WP_Ban_Options::defaults(),
			)
		);

		$settings  = self::tab_page( 'settings' );
		$templates = self::tab_page( 'templates' );

		add_settings_section(
			self::SECTION_PROXY,
			__( 'Visitor IP Address', 'wp-ban' ),
			array( __CLASS__, 'section_proxy' ),
			$settings
		);

		add_settings_field(
			'wp_ban_ip_header',
			__( 'Header That Contains The IP', 'wp-ban' ),
			array( __CLASS__, 'field_ip_header' ),
			$settings,
			self::SECTION_PROXY,
			array( 'label_for' => 'wp-ban-ip-header' )
		);

		add_settings_section(
			self::SECTION_LISTS,
			__( 'Ban Lists', 'wp-ban' ),
			array( __CLASS__, 'section_lists' ),
			$settings
		);

		foreach ( self::list_fields() as $key => $field ) {
			add_settings_field(
				'wp_ban_list_' . $key,
				$field['label'],
				array( __CLASS__, 'field_list' ),
				$settings,
				self::SECTION_LISTS,
				array(
					'key'         => $key,
					'description' => $field['description'],
					'examples'    => $field['examples'],
					'label_for'   => 'wp-ban-list-' . $key,
				)
			);
		}

		add_settings_section(
			self::SECTION_MESSAGE,
			'',
			array( __CLASS__, 'section_message' ),
			$templates
		);

		add_settings_field(
			'wp_ban_message_template',
			__( 'Template', 'wp-ban' ),
			array( __CLASS__, 'field_message' ),
			$templates,
			self::SECTION_MESSAGE,
			array( 'label_for' => 'wp-ban-message' )
		);
	}

	/**
	 * The six ban lists, and how each is described.
	 *
	 * @return array
	 */
	private static function list_fields() {
		return array(
			'ips'         => array(
				'label'       => __( 'Banned IPs', 'wp-ban' ),
				'description' => __( 'Use * for wildcards. One entry per line.', 'wp-ban' ),
				'examples'    => array( '192.168.1.100', '192.168.1.*', '192.168.*.*', '2001:db8::1' ),
			),
			'ips_range'   => array(
				'label'       => __( 'Banned IP Ranges', 'wp-ban' ),
				'description' => __( 'One range per line, written as start-end. No wildcards. IPv4 and IPv6 are both supported, but a range cannot mix the two.', 'wp-ban' ),
				'examples'    => array( '192.168.1.1-192.168.1.255', '2001:db8::1-2001:db8::ffff' ),
			),
			'hosts'       => array(
				'label'       => __( 'Banned Host Names', 'wp-ban' ),
				'description' => __( 'Use * for wildcards. One entry per line. Each visit costs a reverse DNS lookup, so leave this empty unless you use it.', 'wp-ban' ),
				'examples'    => array( '*.sg', '*.cn', 'crawler.example.com' ),
			),
			'referers'    => array(
				'label'       => __( 'Banned Referrers', 'wp-ban' ),
				'description' => __( 'Use * for wildcards. One entry per line. The Referer header is set by the visitor, so this is trivially bypassed.', 'wp-ban' ),
				'examples'    => array( 'http://*.blogspot.com', 'https://*.spam.example' ),
			),
			'user_agents' => array(
				'label'       => __( 'Banned User Agents', 'wp-ban' ),
				'description' => __( 'Use * for wildcards. One entry per line. The User-Agent header is set by the visitor, so this only stops the honest ones.', 'wp-ban' ),
				'examples'    => array( 'EmailSiphon*', 'LMQueueBot*', 'ContactBot*' ),
			),
			'exclude_ips' => array(
				'label'       => __( 'Excluded IPs', 'wp-ban' ),
				'description' => __( 'One entry per line. No wildcards. These addresses are never banned, whatever else matches.', 'wp-ban' ),
				'examples'    => array( '192.168.1.100' ),
			),
		);
	}

	/**
	 * Explain what the plugin thinks the current visitor's address is.
	 *
	 * @return void
	 */
	public static function section_proxy() {
		$ip       = WP_Ban_IP::address();
		$hostname = WP_Ban_IP::hostname( $ip );

		echo '<p>';
		esc_html_e( 'Please do not ban yourself. These are your own details as WP-Ban sees them right now:', 'wp-ban' );
		echo '</p>';

		$rows = array(
			__( 'Your IP', 'wp-ban' )         => '' === $ip ? __( 'Unknown', 'wp-ban' ) : $ip,
			__( 'Your host name', 'wp-ban' )  => '' === $hostname ? __( 'Unknown', 'wp-ban' ) : $hostname,
			__( 'Your user agent', 'wp-ban' ) => '' === WP_Ban_IP::user_agent() ? __( 'Unknown', 'wp-ban' ) : WP_Ban_IP::user_agent(),
			__( 'Site URL', 'wp-ban' )        => get_option( 'home' ),
		);

		/*
		 * A list, not a table. These four are one fact each about the current
		 * request rather than tabular data, and the <table> this used to be
		 * needed two inline style attributes to stop it running the width of
		 * the screen -- which §4.4 rules out and a theme could not have
		 * overridden anyway.
		 */
		echo '<ul>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<li>%s: <strong>%s</strong></li>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</ul>';
	}

	/**
	 * The named-header field.
	 *
	 * The only control in this section as of 2.0.0. There used to be a "This
	 * site is behind a reverse proxy." checkbox above it, and no other plugin in
	 * the collection had one: an empty field already says "no proxy", so the box
	 * only ever meant "trust whichever of the seven headers turns up", which is
	 * what the warning at the bottom of this very field tells owners not to do.
	 *
	 * @return void
	 */
	public static function field_ip_header() {
		$options = WP_Ban_Options::get();

		// The placeholder names the same header as the Example sentence below,
		// and the same one all five proxy-aware plugins now offer.
		printf(
			'<input type="text" class="regular-text code" id="wp-ban-ip-header" name="%s[ip_header]" value="%s" placeholder="HTTP_X_FORWARDED_FOR" />',
			esc_attr( WP_Ban_Options::OPTION ),
			esc_attr( $options['ip_header'] )
		);

		// Worded exactly as wp-polls, wp-postratings, wp-email and wp-useronline
		// word it. The plural in the second sentence is wp-ban's own and correct:
		// its constant and filter really do walk seven headers.
		echo '<p class="description">';
		esc_html_e( 'Leave this blank unless the site is behind a reverse proxy or CDN. Blank means the address the web server saw is used.', 'wp-ban' );
		echo '<br />';
		printf(
			/* translators: 1: an example header name, 2: the WP_BAN_TRUST_PROXY constant, 3: the wp_ban_trust_proxy filter, all in code spans. */
			esc_html__( 'Example: %1$s. You can also opt in with the %2$s constant or the %3$s filter, which trust the usual proxy headers instead of one you name.', 'wp-ban' ),
			'<code>HTTP_X_FORWARDED_FOR</code>',
			'<code>WP_BAN_TRUST_PROXY</code>',
			'<code>wp_ban_trust_proxy</code>'
		);
		echo '<br />';
		printf(
			'<strong>%s</strong>',
			esc_html__( 'Only name a header your proxy sets and overwrites. A visitor can send any header they like, so trusting one your stack does not control lets anyone walk past a ban.', 'wp-ban' )
		);
		echo '</p>';

		/*
		 * The constant used to be reported under the checkbox. It still has to be
		 * reported somewhere: without this an owner who left the field blank has
		 * no way to tell from the screen that the site is trusting seven headers
		 * anyway, on the say-so of a line in wp-config.php they may not have
		 * written.
		 */
		if ( defined( 'WP_BAN_TRUST_PROXY' ) && WP_BAN_TRUST_PROXY ) {
			printf(
				'<p class="description"><strong>%s</strong></p>',
				esc_html__( 'The WP_BAN_TRUST_PROXY constant is defined on this site, so the usual proxy headers are trusted even when this field is empty.', 'wp-ban' )
			);
		}
	}

	/**
	 * Copy for the ban lists section.
	 *
	 * @return void
	 */
	public static function section_lists() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Every list matches the whole value, so use * where you want a partial match.', 'wp-ban' )
		);
	}

	/**
	 * One ban list textarea.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function field_list( $args ) {
		$key = $args['key'];

		printf(
			'<textarea id="wp-ban-list-%s" name="%s[lists][%s]" rows="8" cols="50" class="large-text code" dir="ltr">%s</textarea>',
			esc_attr( $key ),
			esc_attr( WP_Ban_Options::OPTION ),
			esc_attr( $key ),
			esc_textarea( WP_Ban_Options::list_to_lines( $key ) )
		);

		printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );

		echo '<p class="description">' . esc_html__( 'Examples:', 'wp-ban' ) . ' ';

		$examples = array();

		foreach ( $args['examples'] as $example ) {
			$examples[] = '<code>' . esc_html( $example ) . '</code>';
		}

		echo wp_kses( implode( ' ', $examples ), array( 'code' => array() ) );
		echo '</p>';
	}

	/**
	 * Copy for the banned message section.
	 *
	 * @return void
	 */
	public static function section_message() {
		printf(
			'<p>%s</p>',
			esc_html__( 'The page a banned visitor is served. It is a complete HTML document, and your message must sit inside the container div so the preview can find it:', 'wp-ban' )
		);

		echo '<p><code>' . esc_html( '<div id="wp-ban-container"></div>' ) . '</code></p>';
	}

	/**
	 * The tokens the banned message accepts, listed under the field.
	 *
	 * Under the textarea rather than in the section copy above it: a hint belongs
	 * with the control it describes, which is where WordPress puts one. Listing
	 * them in the section intro put them a screen away from the field they apply
	 * to, and made this the only one of five plugins that did it that way.
	 *
	 * These are literal tokens, not printf placeholders. They stay out of
	 * translatable strings on purpose: phpcbf reads a % inside a translatable
	 * string as a placeholder and renumbers it, which would turn %SITE_NAME% into
	 * %1$SITE_NAME% on the screen.
	 *
	 * @return void
	 */
	private static function token_list() {
		$tokens = array(
			'%SITE_NAME%',
			'%SITE_URL%',
			'%USER_IP%',
			'%USER_HOSTNAME%',
			'%USER_ATTEMPTS_COUNT%',
			'%TOTAL_ATTEMPTS_COUNT%',
		);

		echo '<p class="description">' . esc_html__( 'Allowed variables:', 'wp-ban' ) . ' ';

		foreach ( $tokens as $token ) {
			echo '<code>' . esc_html( $token ) . '</code> ';
		}

		echo '</p>';
	}

	/**
	 * The banned message textarea and its buttons.
	 *
	 * @return void
	 */
	public static function field_message() {
		printf(
			'<textarea id="wp-ban-message" name="%s[message]" rows="18" cols="100" class="large-text code">%s</textarea>',
			esc_attr( WP_Ban_Options::OPTION ),
			esc_textarea( WP_Ban_Options::message() )
		);

		self::token_list();

		// The two buttons are found by their data-wp-ban-action, not by id: the
		// script binds behaviour to the attribute, so the ids stay free to be
		// what a label points at rather than what a listener matches on.
		echo '<p>';
		printf(
			'<button type="button" class="button" data-wp-ban-action="restore">%s</button> ',
			esc_html__( 'Restore Default Template', 'wp-ban' )
		);
		printf(
			'<button type="button" class="button" data-wp-ban-action="preview" data-label-show="%s" data-label-hide="%s">%s</button>',
			esc_attr__( 'Show Preview', 'wp-ban' ),
			esc_attr__( 'Show Template', 'wp-ban' ),
			esc_html__( 'Show Preview', 'wp-ban' )
		);
		echo '</p>';

		echo '<div id="wp-ban-preview" hidden></div>';
	}

	/**
	 * Enqueue the settings screen's script.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'wp-ban-admin',
			WP_BAN_URL . 'js/wp-ban-admin.js',
			array(),
			WP_BAN_VERSION,
			// No dependencies, and nothing to do before the fields exist, so it
			// is deferred rather than merely put in the footer. The $args array
			// is WordPress 6.3+ and the floor is 6.8.
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'wp-ban-admin',
			'wpBanL10n',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wp_ban_preview' ),
				'defaultTemplate' => WP_Ban_Options::default_message(),
				'previewError'    => __( 'The preview could not be loaded.', 'wp-ban' ),
			)
		);
	}

	/**
	 * Serve the banned message preview.
	 *
	 * Note that wp_ajax_* fires for every authenticated role, subscribers
	 * included, so the hook alone is not an authorisation check.
	 *
	 * @return void
	 */
	public static function ajax_preview() {
		if ( ! current_user_can( self::capability( 'preview' ) ) ) {
			wp_die( -1, 403 );
		}

		check_ajax_referer( 'wp_ban_preview' );

		echo wp_kses( WP_Ban_Blocker::preview(), WP_Ban_Options::allowed_html() );

		wp_die();
	}

	/**
	 * Process the statistics form before the screen renders.
	 *
	 * @return void
	 */
	public static function handle_stats_actions() {
		if ( ! current_user_can( self::capability( 'stats' ) ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action = ( '' === $action || '-1' === $action ) && isset( $_REQUEST['action2'] )
			? sanitize_key( wp_unslash( $_REQUEST['action2'] ) )
			: $action;

		$reset_all = ! empty( $_REQUEST['reset_all'] );

		if ( 'reset' !== $action && ! $reset_all ) {
			return;
		}

		check_admin_referer( self::STATS_NONCE );

		if ( $reset_all ) {
			WP_Ban_Stats::reset();
			$notice = 'all';
		} else {
			$ips = isset( $_REQUEST['ips'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['ips'] ) ) : array();

			if ( empty( $ips ) ) {
				return;
			}

			WP_Ban_Stats::forget( $ips );
			$notice = 'selected';
		}

		/*
		 * The notice travels in a short-lived transient rather than a query
		 * argument. A marker in the URL has to be read back out of $_GET on the
		 * next request, where there is no nonce to check it against and no way
		 * to tell the plugin's own redirect from a link someone was sent -- and
		 * it survives being bookmarked, so the screen claims to have reset
		 * something every time that bookmark is opened.
		 */
		set_transient( self::notice_key(), $notice, MINUTE_IN_SECONDS );

		// Post/Redirect/Get, so a refresh does not replay the reset -- and back
		// to the tab the table lives on, not to whichever tab is the default.
		wp_safe_redirect( self::url( 'stats' ) );
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::capability( 'screen' ) ) ) {
			return;
		}

		$tab = self::current_tab();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Ban Settings', 'wp-ban' ) );

		/*
		 * No settings_errors() call here on purpose, on any tab.
		 *
		 * §4.2.1 requires the notices to print on every tab, and they do: this
		 * screen hangs off Settings, and admin-header.php includes
		 * options-head.php -- which calls settings_errors() -- for anything
		 * whose parent is options-general.php. That happens above .wrap for
		 * whichever tab is being drawn, and common.js then relocates the
		 * notices into it. A manual call here would render every warning twice,
		 * in what looks like the plugin's own markup. Verified in a browser,
		 * not just asserted, and a plugin on a top-level menu -- dispatched by
		 * admin.php, which includes no such thing -- would need the opposite.
		 */
		self::reset_notice();
		self::render_tabs( $tab );

		if ( 'stats' === $tab ) {
			require_once WP_BAN_DIR . 'includes/class-wp-ban-stats-table.php';

			self::render_stats();
		} else {
			self::render_form( $tab );
		}

		echo '</div>';
	}

	/**
	 * The tab strip.
	 *
	 * @param string $current The tab being drawn.
	 * @return void
	 */
	private static function render_tabs( $current ) {
		echo '<nav class="nav-tab-wrapper">';

		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( self::url( $slug ) ),
				esc_attr( $slug === $current ? 'nav-tab nav-tab-active' : 'nav-tab' ),
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * The settings form for one tab.
	 *
	 * One group and one option row whichever tab this is; only the sections
	 * drawn inside it differ.
	 *
	 * @param string $tab Tab slug.
	 * @return void
	 */
	private static function render_form( $tab ) {
		echo '<form action="options.php" method="post">';

		settings_fields( self::GROUP );

		/*
		 * The tab travels with the save.
		 *
		 * options.php redirects to wp_get_referer(), and settings_fields()
		 * already emitted a _wp_http_referer of its own. PHP keeps the last
		 * input of a repeated name, so this one wins -- and the browser comes
		 * back to the tab it submitted from rather than to the default one,
		 * where the notice would be about fields that are not on screen.
		 */
		printf(
			'<input type="hidden" name="_wp_http_referer" value="%s" />',
			esc_url( self::url( $tab ) )
		);

		do_settings_sections( self::tab_page( $tab ) );
		submit_button();

		echo '</form>';
	}

	/**
	 * Where the reset notice waits between the redirect and the render.
	 *
	 * Per user, so two administrators resetting at once do not read each
	 * other's notice.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return 'wp_ban_notice_' . get_current_user_id();
	}

	/**
	 * Print the notice left behind by a statistics reset.
	 *
	 * @return void
	 */
	private static function reset_notice() {
		$reset = (string) get_transient( self::notice_key() );

		if ( '' === $reset ) {
			return;
		}

		// Shown once. A refresh has nothing left to report.
		delete_transient( self::notice_key() );

		if ( 'all' === $reset ) {
			wp_admin_notice(
				esc_html__( 'All ban stats were reset.', 'wp-ban' ),
				array( 'type' => 'success' )
			);
		} elseif ( 'selected' === $reset ) {
			wp_admin_notice(
				esc_html__( 'The selected ban stats were reset.', 'wp-ban' ),
				array( 'type' => 'success' )
			);
		}
	}

	/**
	 * Render the statistics table and its form.
	 *
	 * @return void
	 */
	private static function render_stats() {
		$table = new WP_Ban_Stats_Table();
		$table->prepare_items();

		printf( '<h2>%s</h2>', esc_html__( 'Ban Stats', 'wp-ban' ) );

		printf(
			'<p>%s <strong>%s</strong></p>',
			esc_html__( 'Total attempts turned away:', 'wp-ban' ),
			esc_html( number_format_i18n( WP_Ban_Stats::total() ) )
		);

		/*
		 * Its own form and its own nonce: this is a list table, not a settings
		 * form, and it posts to the screen rather than to options.php.
		 *
		 * No wp_nonce_field() here -- $table->display() emits the bulk nonce
		 * this form is checked against, and a second _wpnonce input would
		 * override it. Moving the table onto a tab changes only the action URL,
		 * which now names the tab so the redirect after a bulk action comes
		 * back to the table rather than to nothing in particular.
		 */
		printf( '<form method="post" action="%s">', esc_url( self::url( 'stats' ) ) );
		$table->display();

		printf(
			'<p><label><input type="checkbox" name="reset_all" value="1" /> %s</label></p>',
			esc_html__( 'Reset every IP ban stat and the total.', 'wp-ban' )
		);

		submit_button( __( 'Reset Ban Stats', 'wp-ban' ), 'secondary', 'submit', false );

		echo '</form>';
	}
}
