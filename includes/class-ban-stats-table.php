<?php
/**
 * The ban statistics list table.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Ban attempts, on core's list table.
 *
 * Replaces the hand-rolled table the plugin carried before 2.0.0, which
 * rendered every recorded address on one page: a site that had turned away a
 * few thousand bots got a settings screen it could not load.
 */
class Ban_Stats_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ban-stat',
				// Ban_Settings::STATS_NONCE is derived from this; the bulk
				// nonce WP_List_Table emits is "bulk-{$plural}".
				'plural'   => Ban_Settings::STATS_PLURAL,
				'ajax'     => false,
			)
		);
	}

	/**
	 * The table's columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'ip'       => __( 'IP', 'wp-ban' ),
			'hostname' => __( 'Host Name', 'wp-ban' ),
			'attempts' => __( 'Attempts', 'wp-ban' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'ip'       => array( 'ip', false ),
			'attempts' => array( 'attempts', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'reset' => __( 'Reset ban stats', 'wp-ban' ) );
	}

	/**
	 * Message shown when nothing has been turned away yet.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No attempts.', 'wp-ban' );
	}

	/**
	 * Build, sort and paginate the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$stats = Ban_Stats::get();
		$items = array();

		foreach ( $stats['users'] as $ip => $attempts ) {
			$items[] = array(
				'ip'       => (string) $ip,
				'attempts' => (int) $attempts,
			);
		}

		$orderby = 'attempts';
		$order   = 'desc';

		// Reading the sort from the query string to decide what to render is
		// not a state change, so there is no nonce to check here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['orderby'] ) ) {
			$candidate = sanitize_key( wp_unslash( $_GET['orderby'] ) );
			$orderby   = in_array( $candidate, array( 'ip', 'attempts' ), true ) ? $candidate : $orderby;
		}

		if ( isset( $_GET['order'] ) ) {
			$order = 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'asc' : 'desc';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		usort(
			$items,
			static function ( $a, $b ) use ( $orderby ) {
				if ( 'ip' === $orderby ) {
					return strnatcasecmp( $a['ip'], $b['ip'] );
				}

				return $a['attempts'] <=> $b['attempts'];
			}
		);

		if ( 'desc' === $order ) {
			$items = array_reverse( $items );
		}

		$total    = count( $items );
		$per_page = $this->get_items_per_page( 'ban_stats_per_page', 50 );
		$page     = $this->get_pagenum();

		$this->items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'ip' );
	}

	/**
	 * The row selection checkbox.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ips[]" value="%s" />', esc_attr( $item['ip'] ) );
	}

	/**
	 * The IP column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_ip( $item ) {
		return '<code>' . esc_html( $item['ip'] ) . '</code>';
	}

	/**
	 * The host name column.
	 *
	 * Resolved per row, so it is only paid for the rows actually on screen --
	 * which is the other reason this table is paginated.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_hostname( $item ) {
		$hostname = Ban_IP::hostname( $item['ip'] );

		return '' === $hostname || $hostname === $item['ip'] ? '&#8212;' : esc_html( $hostname );
	}

	/**
	 * The attempts column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_attempts( $item ) {
		return esc_html( number_format_i18n( $item['attempts'] ) );
	}

	/**
	 * Fallback for any column without a handler.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}
}
