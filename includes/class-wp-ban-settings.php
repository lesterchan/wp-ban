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
	 * The statistics table's "plural" name, as passed to WP_List_Table.
	 *
	 * @var string
	 */
	const STATS_PLURAL = 'ban-stats';

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
		add_action( 'wp_ajax_ban-admin', array( __CLASS__, 'ajax_preview' ) );
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
				esc_url( self::url() ),
				esc_html__( 'Settings', 'wp-ban' )
			)
		);

		return $links;
	}

	/**
	 * The settings screen's URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
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
			__( 'Ban', 'wp-ban' ),
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
		// Activation does not fire when a plugin is updated, so the migration
		// is driven from here too.
		WP_Ban_Options::maybe_migrate();

		register_setting(
			self::GROUP,
			WP_Ban_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WP_Ban_Options', 'sanitize' ),
				'default'           => WP_Ban_Options::defaults(),
			)
		);

		add_settings_section(
			'ban_proxy',
			__( 'Visitor IP Address', 'wp-ban' ),
			array( __CLASS__, 'section_proxy' ),
			self::PAGE
		);

		add_settings_field(
			'ban_reverse_proxy',
			__( 'Reverse proxy', 'wp-ban' ),
			array( __CLASS__, 'field_reverse_proxy' ),
			self::PAGE,
			'ban_proxy'
		);

		add_settings_field(
			'ban_ip_header',
			__( 'Trusted header', 'wp-ban' ),
			array( __CLASS__, 'field_ip_header' ),
			self::PAGE,
			'ban_proxy'
		);

		add_settings_section(
			'ban_lists',
			__( 'Ban Lists', 'wp-ban' ),
			array( __CLASS__, 'section_lists' ),
			self::PAGE
		);

		foreach ( self::list_fields() as $key => $field ) {
			add_settings_field(
				'ban_list_' . $key,
				$field['label'],
				array( __CLASS__, 'field_list' ),
				self::PAGE,
				'ban_lists',
				array(
					'key'         => $key,
					'description' => $field['description'],
					'examples'    => $field['examples'],
					'label_for'   => 'ban-list-' . $key,
				)
			);
		}

		add_settings_section(
			'ban_message',
			__( 'Banned Message', 'wp-ban' ),
			array( __CLASS__, 'section_message' ),
			self::PAGE
		);

		add_settings_field(
			'ban_message_template',
			__( 'Template', 'wp-ban' ),
			array( __CLASS__, 'field_message' ),
			self::PAGE,
			'ban_message',
			array( 'label_for' => 'ban-message' )
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

		echo '<table class="widefat striped" style="max-width:60em;"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td style="width:14em;">%s</td><td><strong>%s</strong></td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The reverse proxy checkbox.
	 *
	 * @return void
	 */
	public static function field_reverse_proxy() {
		$options = WP_Ban_Options::get();

		printf(
			'<label><input type="checkbox" name="%s[reverse_proxy]" value="1"%s /> %s</label>',
			esc_attr( WP_Ban_Options::OPTION ),
			checked( ! empty( $options['reverse_proxy'] ), true, false ),
			esc_html__( 'This site is behind a reverse proxy.', 'wp-ban' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Leave this unchecked if you are not sure. Ticking it when there is no proxy in front of WordPress makes every IP ban trivial to bypass, because the headers it then trusts are set by the visitor.', 'wp-ban' )
		);

		if ( defined( 'WP_BAN_TRUST_PROXY' ) && WP_BAN_TRUST_PROXY ) {
			printf(
				'<p class="description"><strong>%s</strong></p>',
				esc_html__( 'The WP_BAN_TRUST_PROXY constant is defined on this site, so proxy headers are trusted regardless of this box.', 'wp-ban' )
			);
		}
	}

	/**
	 * The named-header field.
	 *
	 * @return void
	 */
	public static function field_ip_header() {
		$options = WP_Ban_Options::get();

		printf(
			'<input type="text" class="regular-text code" id="ban-ip-header" name="%s[ip_header]" value="%s" placeholder="HTTP_CF_CONNECTING_IP" />',
			esc_attr( WP_Ban_Options::OPTION ),
			esc_attr( $options['ip_header'] )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Optional, and the safest choice if you know your stack: name the single header your proxy sets, and only that one is trusted. Leave empty to fall back to the checkbox above.', 'wp-ban' )
		);
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
			'<textarea id="ban-list-%s" name="%s[lists][%s]" rows="8" cols="50" class="large-text code" dir="ltr">%s</textarea>',
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

		echo '<p>' . esc_html__( 'Allowed variables:', 'wp-ban' ) . '</p>';

		/*
		 * These are literal tokens, not printf placeholders. They stay out of
		 * translatable strings on purpose: phpcbf reads a % inside a
		 * translatable string as a placeholder and renumbers it, which would
		 * turn %SITE_NAME% into %1$SITE_NAME% on the screen.
		 */
		$tokens = array(
			'%SITE_NAME%',
			'%SITE_URL%',
			'%USER_IP%',
			'%USER_HOSTNAME%',
			'%USER_ATTEMPTS_COUNT%',
			'%TOTAL_ATTEMPTS_COUNT%',
		);

		echo '<p>';

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
			'<textarea id="ban-message" name="%s[message]" rows="18" cols="100" class="large-text code">%s</textarea>',
			esc_attr( WP_Ban_Options::OPTION ),
			esc_textarea( WP_Ban_Options::message() )
		);

		echo '<p>';
		printf(
			'<button type="button" class="button" id="ban-restore-default">%s</button> ',
			esc_html__( 'Restore Default Template', 'wp-ban' )
		);
		printf(
			'<button type="button" class="button" id="ban-preview-toggle" data-label-show="%s" data-label-hide="%s">%s</button>',
			esc_attr__( 'Show Preview', 'wp-ban' ),
			esc_attr__( 'Show Template', 'wp-ban' ),
			esc_html__( 'Show Preview', 'wp-ban' )
		);
		echo '</p>';

		echo '<div id="ban-preview" hidden></div>';
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
			// wp_enqueue_script()'s $args array, and with it
			// strategy => defer, is WordPress 6.3+; the floor here is 6.0.
			true
		);

		wp_localize_script(
			'wp-ban-admin',
			'wpBanL10n',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wp-ban_preview' ),
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

		check_ajax_referer( 'wp-ban_preview' );

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

		$table  = null;
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

		// Post/Redirect/Get, so a refresh does not replay the reset.
		wp_safe_redirect( add_query_arg( 'ban-reset', $notice, self::url() ) );
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

		require_once WP_BAN_DIR . 'includes/class-wp-ban-stats-table.php';

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Ban Options', 'wp-ban' ) );

		/*
		 * No settings_errors() call here on purpose. WordPress already prints
		 * the errors queued by the sanitize callback for pages registered under
		 * Settings, and common.js then relocates the notices into .wrap -- so
		 * adding a manual call renders every warning twice, in what looks like
		 * the plugin's own markup. Verified in a browser, not just asserted.
		 */
		self::reset_notice();

		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form>';

		self::render_stats();

		echo '</div>';
	}

	/**
	 * Print the notice left behind by a statistics reset.
	 *
	 * @return void
	 */
	private static function reset_notice() {
		// Reading a redirect marker to pick a notice changes nothing.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reset = isset( $_GET['ban-reset'] ) ? sanitize_key( wp_unslash( $_GET['ban-reset'] ) ) : '';

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

		// No wp_nonce_field() here: $table->display() emits the bulk nonce this
		// form is checked against, and a second _wpnonce input would override it.
		printf( '<form method="post" action="%s">', esc_url( self::url() ) );
		$table->display();

		printf(
			'<p><label><input type="checkbox" name="reset_all" value="1" /> %s</label></p>',
			esc_html__( 'Reset every IP ban stat and the total.', 'wp-ban' )
		);

		submit_button( __( 'Reset Ban Stats', 'wp-ban' ), 'secondary', 'submit', false );

		echo '</form>';
	}
}
