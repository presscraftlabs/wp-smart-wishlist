<?php
/**
 * Collaborators of a list.
 *
 * @package WishCraft
 */

namespace WishCraft\Data;

/**
 * A member is invited by email and becomes active when a logged-in user accepts the invitation.
 * Only a hash of the invitation key is stored. Members are editors: they add, change and remove
 * items, but only the owner changes the list itself and its members.
 */
final class Members {

	const ROLES = array( 'editor' );

	/**
	 * Typed row.
	 *
	 * @param object|array $row Database row.
	 * @return array
	 */
	private static function row( $row ) {
		$row = (array) $row;
		return array(
			'id'      => (int) $row['id'],
			'list_id' => (int) $row['list_id'],
			'user_id' => (int) $row['user_id'],
			'email'   => (string) $row['email'],
			'role'    => in_array( $row['role'], self::ROLES, true ) ? $row['role'] : 'editor',
			'status'  => 'active' === $row['status'] ? 'active' : 'invited',
			'invited' => (string) $row['invited'],
		);
	}

	/**
	 * Stored form of an invitation key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function hash( $key ) {
		return hash_hmac( 'sha256', (string) $key, wp_salt( 'nonce' ) );
	}

	/**
	 * Invite an email address to a list.
	 *
	 * @param int    $list_id List ID.
	 * @param string $email   Email.
	 * @return array|null { id, key } with the plain key for the invitation link, or null.
	 */
	public static function invite( $list_id, $email ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return null;
		}
		$key      = bin2hex( random_bytes( 32 ) );
		$table    = Schema::table( 'members' );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE list_id = %d AND email = %s", $list_id, $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		$data     = array(
			'invite_key' => self::hash( $key ),
			'invited'    => current_time( 'mysql', true ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $existing;
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				$data + array(
					'list_id' => (int) $list_id,
					'email'   => $email,
					'role'    => 'editor',
					'status'  => 'invited',
				)
			);
			$id = (int) $wpdb->insert_id;
		}
		return array(
			'id'  => $id,
			'key' => $key,
		);
	}

	/**
	 * Accept an invitation as a user.
	 *
	 * @param string $key     Plain invitation key.
	 * @param int    $user_id User ID.
	 * @return array|null Membership.
	 */
	public static function accept( $key, $user_id ) {
		global $wpdb;
		if ( ! $user_id || ! preg_match( '/^[a-f0-9]{64}$/', (string) $key ) ) {
			return null;
		}
		$table = Schema::table( 'members' );
		$hash  = self::hash( $key );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE invite_key = %s AND status = 'invited'", $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		if ( ! $row || ! hash_equals( $row->invite_key, $hash ) ) {
			return null;
		}
		$wishlist = Lists::get( $row->list_id );
		if ( ! $wishlist || (int) $wishlist['owner'] === (int) $user_id ) {
			return null;
		}
		$current = self::find( $row->list_id, $user_id );
		if ( $current ) {
			// Already a member through another invitation.
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $current;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'user_id'    => (int) $user_id,
				'invite_key' => '',
				'status'     => 'active',
			),
			array( 'id' => (int) $row->id )
		);
		return self::get( $row->id );
	}

	/**
	 * One membership.
	 *
	 * @param int $id Member ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Schema::table( 'members' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return $row ? self::row( $row ) : null;
	}

	/**
	 * Active membership of a user in a list.
	 *
	 * @param int $list_id List ID.
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	public static function find( $list_id, $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return null;
		}
		$table = Schema::table( 'members' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE list_id = %d AND user_id = %d AND status = 'active'", $list_id, $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return $row ? self::row( $row ) : null;
	}

	/**
	 * Members and invitations of a list.
	 *
	 * @param int $list_id List ID.
	 * @return array[]
	 */
	public static function for_list( $list_id ) {
		global $wpdb;
		$table = Schema::table( 'members' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE list_id = %d ORDER BY id ASC", $list_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
		return array_map( array( self::class, 'row' ), $rows );
	}

	/**
	 * IDs of the lists a user collaborates on.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function list_ids_for_user( $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return array();
		}
		$table = Schema::table( 'members' );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT list_id FROM {$table} WHERE user_id = %d AND status = 'active' ORDER BY list_id ASC", $user_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin table.
	}

	/**
	 * Remove a membership or invitation.
	 *
	 * @param int $id Member ID.
	 */
	public static function remove( $id ) {
		global $wpdb;
		$wpdb->delete( Schema::table( 'members' ), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Remove every membership of a user (account deleted).
	 *
	 * @param int $user_id User ID.
	 */
	public static function remove_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( Schema::table( 'members' ), array( 'user_id' => (int) $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
