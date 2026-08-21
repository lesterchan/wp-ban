<?php
/**
 * Network activation: the settings seed has to reach every site.
 *
 * Only runs under WP_MULTISITE=1 (see bin/test-multisite.sh). The settings and
 * the version markers are per-site rows, so an activation that seeds only
 * whichever site happened to be current leaves the rest of the network with no
 * row at all. Nothing is destroyed by that -- the same routine runs again from
 * the settings screen's admin_init registration -- which is precisely why it
 * went unnoticed: every site heals the moment somebody opens its dashboard,
 * and a network whose subsites are front-end only never does.
 *
 * @package WP-Ban
 */

/**
 * WP_Ban::activate() across a network.
 *
 * @group ms-required
 */
class WP_Ban_Multisite_Test extends WP_Ban_TestCase {

	/**
	 * Skip the whole class on a single site install.
	 *
	 * @return void
	 */
	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite install. Run bin/test-multisite.sh.' );
		}

		parent::set_up();
	}

	/**
	 * Create sites and tear the plugin's rows down on each.
	 *
	 * Torn down so activation has something to do: a leftover row would let a
	 * loop that never reaches the site pass anyway.
	 *
	 * @param int $count How many extra sites to create.
	 * @return int[] Site IDs, the current site first.
	 */
	protected function seed_network( $count = 3 ) {
		$site_ids = array( get_current_blog_id() );

		for ( $i = 0; $i < $count; $i++ ) {
			$site_ids[] = (int) self::factory()->blog->create();
		}

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );

			delete_option( WP_Ban_Options::OPTION );
			delete_option( WP_Ban_Options::VERSION );

			restore_current_blog();
		}

		WP_Ban_Options::flush_cache();

		return $site_ids;
	}

	/**
	 * Network activation seeds every site, not just the current one.
	 *
	 * The rows are read raw: WP_Ban_Options::get() answers with the defaults
	 * whether the row exists or not, which is exactly the difference under test.
	 *
	 * @return void
	 */
	public function test_network_activation_seeds_every_site() {
		$site_ids = $this->seed_network( 3 );

		WP_Ban::activate( true );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );

			$this->assertIsArray(
				get_option( WP_Ban_Options::OPTION ),
				"Site {$site_id} never had its settings row created."
			);

			$this->assertSame(
				array(
					'plugin' => WP_BAN_VERSION,
					'db'     => WP_BAN_DB_VERSION,
				),
				get_option( WP_Ban_Options::VERSION ),
				"Site {$site_id} was never stamped with the running version."
			);

			restore_current_blog();
		}
	}

	/**
	 * Activating on one site does not touch the rest of the network.
	 *
	 * @return void
	 */
	public function test_single_site_activation_leaves_other_sites_alone() {
		$site_ids = $this->seed_network( 1 );
		$other    = $site_ids[1];

		WP_Ban::activate( false );

		switch_to_blog( $other );

		$this->assertFalse(
			get_option( WP_Ban_Options::OPTION ),
			"A per-site activation seeded site {$other}."
		);
		$this->assertFalse(
			get_option( WP_Ban_Options::VERSION ),
			"A per-site activation stamped site {$other}."
		);

		restore_current_blog();
	}

	/**
	 * The site query is uncapped and asks only for IDs.
	 *
	 * Asserted by reading the arguments the query was given rather than by
	 * building a 101 site fixture: get_sites() defaults to 100, so a larger
	 * network silently skips every site past the hundredth, and the cheap
	 * version of that assertion is the only one worth running per suite.
	 *
	 * @return void
	 */
	public function test_network_activation_queries_sites_without_a_cap() {
		$this->seed_network( 2 );

		$captured = array();
		add_action(
			'pre_get_sites',
			function ( $query ) use ( &$captured ) {
				$captured[] = $query->query_vars;
			}
		);

		WP_Ban::activate( true );

		$this->assertNotEmpty( $captured, 'Activation never queried the site list.' );
		$this->assertSame( 0, (int) $captured[0]['number'], 'get_sites() was left at its default cap of 100 sites.' );
		$this->assertSame( 'ids', $captured[0]['fields'], 'Only the site IDs are needed.' );
	}

	/**
	 * The blog stack is left unwound and the original site is current.
	 *
	 * Calling switch_to_blog() pushes onto a stack. Restoring once after the loop
	 * rather than once per iteration leaves the stack short, so whatever runs next
	 * operates against the last site visited instead of the one it thinks it is on.
	 *
	 * @return void
	 */
	public function test_network_activation_unwinds_the_blog_stack() {
		$original = get_current_blog_id();
		$this->seed_network( 3 );

		WP_Ban::activate( true );

		$this->assertFalse( ms_is_switched(), 'The blog stack was left switched.' );
		$this->assertSame( $original, get_current_blog_id(), 'The original site is no longer current.' );
	}
}
