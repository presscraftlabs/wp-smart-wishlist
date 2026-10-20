<?php
/**
 * Wishlists.
 *
 * @package WishCraft
 */

namespace WishCraft\Data;

/**
 * Rows of the lists table as arrays with typed values. Privacy is private (owner and members),
 * link (anyone with the share link) or public (anyone with the link, and findable in the future
 * public directory).
 */
final class Lists {

	const PRIVACY = array( 'private', 'link', 'public' );

	/**
	 * Typed row.
	 *
	 * @param object|array|null $row Database row.
	 * @return array|null
	 */
	private static function row( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row = (array) $row;
		return array(
			'id'          => (int) $row['id'],
			'owner'       => (int) $row['owner'],
			'guest_key'   => (string) $row['guest_key'],
			'title'       => (string) $row['title'],
			'description' => (string) $row['description'],
			'privacy'     => in_array( $row['privacy'], self::PRIVACY, true ) ? $row['privacy'] : 'private',
			'share_token' => (string) $row['share_token'],
			'is_default'  => (bool) $row['is_default'],
			'created'     => (string) $row['created'],
			'updated'     => (string) $row['updated'],
		);
	}

	/**
	 * Clean writable fields.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private static function clean( array $fields ) {
		$out = array();
		if ( array_key_exists( 'title', $fields ) ) {
			$title        = sanitize_text_field( (string) $fields['title'] );
			$out['title'] = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 190 ) : substr( $title, 0, 190 );
		}
		if ( array_key_exists( 'description', $fields ) ) {
			$out['description'] = sanitize_textarea_field( (string) $fields['description'] );
		}
		if ( array_key_exists( 'privacy', $fields ) ) {
			$out['privacy'] = in_array( $fields['privacy'], self::PRIVACY, true ) ? $fields['privacy'] : 'private';
		}
		foreach ( array( 'owner', 'is_default' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$out[ $key ] = absint( $fields[ $key ] );
			}
		}
		if ( array_key_exists( 'guest_key', $fields ) ) {
			$out['guest_key'] = preg_match( '/^[a-f0-9]{64}$/', (string) $fields['guest_key'] ) ? $fields['guest_key'] : '';
		}
		return $out;
	}

	/**
	 * Create a list.
	 *
	 * @param array $fields owner, guest_key, title, description, privacy, is_default.
	 * @return int List ID.
	 */
	public static function create( array $fields ) {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			array(
				'owner'       => 0,
				'guest_key'   => '',
				'title'       => __( 'My wishlist', 'wp-smart-wishlist' ),
				'description' => '',
				'privacy'     => 'private',
				'is_default'  => 0,
			),
			self::clean( $fields ),
			array(
				'share_token' => self::new_token(),
				'created'     => $now,
				'updated'     => $now,
			)
		);
		if ( '' === trim( $data['title'] ) ) {
			$data['title'] = __( 'My wishlist', 'wp-smart-wishlist' );
		}
		$wpdb->insert( Schema::table( 'lists' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Random share token.
	 *
	 * @return string
	 */
	public static function new_token() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * One list.
	 *
	 * @param int $id List ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Schema::table( 'lists' );
		return self::row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
	}

	/**
	 * A list by its share token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		$table    = Schema::table( 'lists' );
		$wishlist = self::row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE share_token = %s", $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return $wishlist && hash_equals( $wishlist['share_token'], $token ) ? $wishlist : null;
	}

	/**
	 * Lists of a user, default first.
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$table = Schema::table( 'lists' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner = %d ORDER BY is_default DESC, id ASC", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return array_map( array( self::class, 'row' ), $rows );
	}

	/**
	 * Lists of a guest.
	 *
	 * @param string $guest_key Guest key.
	 * @return array[]
	 */
	public static function for_guest( $guest_key ) {
		global $wpdb;
		if ( '' === $guest_key ) {
			return array();
		}
		$table = Schema::table( 'lists' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner = 0 AND guest_key = %s ORDER BY is_default DESC, id ASC", $guest_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return array_map( array( self::class, 'row' ), $rows );
	}

	/**
	 * A user's default list.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $create  Create it when missing.
	 * @return array|null
	 */
	public static function default_for_user( $user_id, $create = false ) {
		foreach ( self::for_user( $user_id ) as $wishlist ) {
			if ( $wishlist['is_default'] ) {
				return $wishlist;
			}
		}
		if ( ! $create ) {
			return null;
		}
		return self::get(
			self::create(
				array(
					'owner'      => $user_id,
					'is_default' => 1,
				)
			)
		);
	}

	/**
	 * Update a list.
	 *
	 * @param int   $id     List ID.
	 * @param array $fields Fields.
	 * @return array|null Updated list.
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$data = self::clean( $fields );
		if ( isset( $data['title'] ) && '' === trim( $data['title'] ) ) {
			unset( $data['title'] );
		}
		if ( array_key_exists( 'share_token', $fields ) ) {
			$data['share_token'] = self::new_token();
		}
		$data['updated'] = current_time( 'mysql', true );
		$wpdb->update( Schema::table( 'lists' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::get( $id );
	}

	/**
	 * Mark a list as changed.
	 *
	 * @param int $id List ID.
	 */
	public static function touch( $id ) {
		global $wpdb;
		$wpdb->update( Schema::table( 'lists' ), array( 'updated' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete a list with its items and members.
	 *
	 * @param int $id List ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		foreach ( array( 'items', 'members' ) as $table ) {
			$wpdb->delete( Schema::table( $table ), array( 'list_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->delete( Schema::table( 'lists' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Guest lists not changed for a number of days.
	 *
	 * @param int $days  Days.
	 * @param int $limit Most to return.
	 * @return int[] List IDs.
	 */
	public static function stale_guest_lists( $days, $limit = 200 ) {
		global $wpdb;
		$table  = Schema::table( 'lists' );
		$before = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * (int) $days );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE owner = 0 AND updated < %s LIMIT %d", $before, $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
	}
}
