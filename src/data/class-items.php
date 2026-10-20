<?php
/**
 * Items in wishlists.
 *
 * @package WishCraft
 */

namespace WishCraft\Data;

use WishCraft\Settings;

/**
 * An item is a post (product, property, article...) and, for variable WooCommerce products, one
 * of its variations. The same item is in a list at most once.
 */
final class Items {

	/**
	 * Typed row.
	 *
	 * @param object|array $row Database row.
	 * @return array
	 */
	private static function row( $row ) {
		$row = (array) $row;
		return array(
			'id'           => (int) $row['id'],
			'list_id'      => (int) $row['list_id'],
			'object_id'    => (int) $row['object_id'],
			'variation_id' => (int) $row['variation_id'],
			'quantity'     => max( 1, (int) $row['quantity'] ),
			'note'         => (string) $row['note'],
			'added_by'     => (int) $row['added_by'],
			'added'        => (string) $row['added'],
			'position'     => (int) $row['position'],
		);
	}

	/**
	 * Clean a note.
	 *
	 * @param string $note Note.
	 * @return string
	 */
	private static function note( $note ) {
		$note = sanitize_textarea_field( (string) $note );
		return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 500 ) : substr( $note, 0, 500 );
	}

	/**
	 * Whether a post can go in a wishlist: published (or private and readable) and of a supported
	 * post type. A variation must belong to the product.
	 *
	 * @param int $object_id    Post ID.
	 * @param int $variation_id Variation ID.
	 * @return bool
	 */
	public static function allowed( $object_id, $variation_id = 0 ) {
		$post = get_post( $object_id );
		if ( ! $post || ! Settings::supports( $post->post_type ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
			return false;
		}
		if ( $variation_id ) {
			$variation = get_post( $variation_id );
			if ( ! $variation || 'product_variation' !== $variation->post_type || (int) $variation->post_parent !== (int) $post->ID ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Add an item (or return the one already there).
	 *
	 * @param int    $list_id      List ID.
	 * @param int    $object_id    Post ID.
	 * @param int    $variation_id Variation ID.
	 * @param int    $quantity     Quantity.
	 * @param string $note         Note.
	 * @param int    $added_by     User ID (0 for guests).
	 * @return int Item ID (0 when the list is full).
	 */
	public static function add( $list_id, $object_id, $variation_id = 0, $quantity = 1, $note = '', $added_by = 0 ) {
		global $wpdb;
		$existing = self::find( $list_id, $object_id, $variation_id );
		if ( $existing ) {
			return $existing['id'];
		}
		if ( self::count( $list_id ) >= Settings::get( 'max_items' ) ) {
			return 0;
		}
		$table = Schema::table( 'items' );
		$next  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position), 0) + 1 FROM {$table} WHERE list_id = %d", $list_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'list_id'      => (int) $list_id,
				'object_id'    => (int) $object_id,
				'variation_id' => (int) $variation_id,
				'quantity'     => max( 1, min( 9999, (int) $quantity ) ),
				'note'         => self::note( $note ),
				'added_by'     => (int) $added_by,
				'added'        => current_time( 'mysql', true ),
				'position'     => $next,
			)
		);
		Lists::touch( $list_id );
		$id = (int) $wpdb->insert_id;
		/**
		 * Fires after an item is added to a wishlist.
		 *
		 * @param int $id        Item ID.
		 * @param int $list_id   List ID.
		 * @param int $object_id Post ID.
		 */
		do_action( 'wishcraft_item_added', $id, (int) $list_id, (int) $object_id );
		return $id;
	}

	/**
	 * One item.
	 *
	 * @param int $id Item ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Schema::table( 'items' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return $row ? self::row( $row ) : null;
	}

	/**
	 * An item by what it is.
	 *
	 * @param int $list_id      List ID.
	 * @param int $object_id    Post ID.
	 * @param int $variation_id Variation ID.
	 * @return array|null
	 */
	public static function find( $list_id, $object_id, $variation_id = 0 ) {
		global $wpdb;
		$table = Schema::table( 'items' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE list_id = %d AND object_id = %d AND variation_id = %d", $list_id, $object_id, $variation_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return $row ? self::row( $row ) : null;
	}

	/**
	 * Items of a list, in order.
	 *
	 * @param int $list_id List ID.
	 * @return array[]
	 */
	public static function for_list( $list_id ) {
		global $wpdb;
		$table = Schema::table( 'items' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE list_id = %d ORDER BY position ASC, id ASC", $list_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return array_map( array( self::class, 'row' ), $rows );
	}

	/**
	 * Number of items in a list.
	 *
	 * @param int $list_id List ID.
	 * @return int
	 */
	public static function count( $list_id ) {
		global $wpdb;
		$table = Schema::table( 'items' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE list_id = %d", $list_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
	}

	/**
	 * Which of the given lists contain each object.
	 *
	 * @param int[] $list_ids   Lists.
	 * @param int[] $object_ids Posts.
	 * @return array object ID => list IDs.
	 */
	public static function membership( array $list_ids, array $object_ids ) {
		global $wpdb;
		$list_ids   = array_filter( array_map( 'intval', $list_ids ) );
		$object_ids = array_filter( array_map( 'intval', $object_ids ) );
		if ( ! $list_ids || ! $object_ids ) {
			return array();
		}
		$table = Schema::table( 'items' );
		$lists = implode( ',', $list_ids );
		$posts = implode( ',', array_slice( $object_ids, 0, 500 ) );
		$rows  = $wpdb->get_results( "SELECT object_id, list_id FROM {$table} WHERE list_id IN ({$lists}) AND object_id IN ({$posts})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- integers only, plugin table.
		$out   = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->object_id ][] = (int) $row->list_id;
		}
		return array_map( 'array_values', array_map( 'array_unique', $out ) );
	}

	/**
	 * Update quantity, note or position.
	 *
	 * @param int   $id     Item ID.
	 * @param array $fields Fields.
	 * @return array|null
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$data = array();
		if ( isset( $fields['quantity'] ) ) {
			$data['quantity'] = max( 1, min( 9999, (int) $fields['quantity'] ) );
		}
		if ( isset( $fields['note'] ) ) {
			$data['note'] = self::note( $fields['note'] );
		}
		if ( isset( $fields['position'] ) ) {
			$data['position'] = max( 0, (int) $fields['position'] );
		}
		$item = self::get( $id );
		if ( $data && $item ) {
			$wpdb->update( Schema::table( 'items' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Lists::touch( $item['list_id'] );
		}
		return self::get( $id );
	}

	/**
	 * Move an item to another list (merging with the same item there).
	 *
	 * @param int $id      Item ID.
	 * @param int $list_id Target list.
	 * @return array|null Item in the target list.
	 */
	public static function move( $id, $list_id ) {
		$item = self::get( $id );
		if ( ! $item || $item['list_id'] === (int) $list_id ) {
			return $item;
		}
		$new = self::add( $list_id, $item['object_id'], $item['variation_id'], $item['quantity'], $item['note'], $item['added_by'] );
		if ( ! $new ) {
			return null;
		}
		self::remove( $id );
		return self::get( $new );
	}

	/**
	 * Remove an item.
	 *
	 * @param int $id Item ID.
	 */
	public static function remove( $id ) {
		global $wpdb;
		$item = self::get( $id );
		if ( ! $item ) {
			return;
		}
		$wpdb->delete( Schema::table( 'items' ), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Lists::touch( $item['list_id'] );
		/**
		 * Fires after an item is removed from a wishlist.
		 *
		 * @param array $item Item.
		 */
		do_action( 'wishcraft_item_removed', $item );
	}

	/**
	 * Most wishlisted posts.
	 *
	 * @param int $limit Number.
	 * @return array[] { object_id, lists }
	 */
	public static function popular( $limit = 20 ) {
		global $wpdb;
		$table = Schema::table( 'items' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, COUNT(DISTINCT list_id) AS lists FROM {$table} GROUP BY object_id ORDER BY lists DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return array_map(
			static function ( $row ) {
				return array(
					'object_id' => (int) $row->object_id,
					'lists'     => (int) $row->lists,
				);
			},
			$rows
		);
	}
}
