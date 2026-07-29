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
class WP_Ban_Stats_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wp-ban-stat',
				// WP_Ban_Settings::STATS_NONCE is derived from this; the bulk
				// nonce WP_List_Table emits is "bulk-{$plural}".
				'plural'   => WP_Ban_Settings::STATS_PLURAL,
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
	 * The sort this request asked for, checked against the sortable columns.
	 *
	 * A sortable column header is a link, and core builds it by swapping
	 * orderby and order on the current URL -- so it carries no nonce, there is
	 * none to verify, and every core list table reads these same two keys the
	 * same way. Both values are navigation: they decide the order rows are
	 * printed in and nothing else, and neither reaches anything but the
	 * comparison below, having first been checked against a fixed list.
	 *
	 * That is why phpcs.xml relaxes WordPress.Security.NonceVerification for
	 * *-table.php across all nineteen plugins. The exclusion is scoped to list
	 * tables so it cannot quietly cover a real handler; anything in this file
	 * that did change state would still need its nonce checked by hand.
	 *
	 * @return array{0:string,1:string} Column key, and 'asc' or 'desc'.
	 */
	private static function requested_sort() {
		$request = wp_unslash( $_GET );

		$candidate = isset( $request['orderby'] ) ? sanitize_key( $request['orderby'] ) : '';
		$orderby   = in_array( $candidate, array( 'ip', 'attempts' ), true ) ? $candidate : 'attempts';

		$candidate = isset( $request['order'] ) ? sanitize_key( $request['order'] ) : '';
		$order     = 'asc' === $candidate ? 'asc' : 'desc';

		return array( $orderby, $order );
	}

	/**
	 * Build, sort and paginate the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$stats = WP_Ban_Stats::get();
		$items = array();

		foreach ( $stats['users'] as $ip => $attempts ) {
			$items[] = array(
				'ip'       => (string) $ip,
				'attempts' => (int) $attempts,
			);
		}

		list( $orderby, $order ) = self::requested_sort();

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
		$per_page = $this->get_items_per_page( 'wp_ban_stats_per_page', 20 );
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
		$hostname = WP_Ban_IP::hostname( $item['ip'] );

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
