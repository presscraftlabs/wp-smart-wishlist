<?php
/**
 * Database tables.
 *
 * @package WishCraft
 */

namespace WishCraft\Data;

/**
 * Lists, their items and their members live in three tables: a site can hold many thousands of
 * items, and lists are looked up by owner, guest key and share token.
 */
final class Schema {

	/**
	 * Bump when a table changes (dbDelta runs again).
	 */
	const VERSION = 1;

	const OPTION = 'wishcraft_schema';

	/**
	 * Table names.
	 *
	 * @return string[] lists, items, members.
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'lists'   => $wpdb->prefix . 'wishcraft_lists',
			'items'   => $wpdb->prefix . 'wishcraft_items',
			'members' => $wpdb->prefix . 'wishcraft_members',
		);
	}

	/**
	 * One table name.
	 *
	 * @param string $name lists, items or members.
	 * @return string
	 */
	public static function table( $name ) {
		return self::tables()[ $name ];
	}

	/**
	 * Create or update the tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = self::tables();
		dbDelta(
			"CREATE TABLE {$t['lists']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner bigint(20) unsigned NOT NULL DEFAULT 0,
			guest_key char(64) NOT NULL DEFAULT '',
			title varchar(190) NOT NULL DEFAULT '',
			description text NOT NULL,
			privacy varchar(10) NOT NULL DEFAULT 'private',
			share_token char(32) NOT NULL DEFAULT '',
			is_default tinyint(1) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY owner (owner),
			KEY guest_key (guest_key),
			KEY share_token (share_token),
			KEY updated (updated)
			) $charset;
			CREATE TABLE {$t['items']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			list_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			quantity int(10) unsigned NOT NULL DEFAULT 1,
			note varchar(500) NOT NULL DEFAULT '',
			added_by bigint(20) unsigned NOT NULL DEFAULT 0,
			added datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			position int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY list_object (list_id,object_id,variation_id),
			KEY object_id (object_id)
			) $charset;
			CREATE TABLE {$t['members']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			list_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(100) NOT NULL DEFAULT '',
			invite_key char(64) NOT NULL DEFAULT '',
			role varchar(20) NOT NULL DEFAULT 'editor',
			status varchar(20) NOT NULL DEFAULT 'invited',
			invited datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY list_id (list_id),
			KEY user_id (user_id),
			KEY invite_key (invite_key)
			) $charset;"
		);
		update_option( self::OPTION, self::VERSION, true );
	}

	/**
	 * Create the tables on sites updated without activation.
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::OPTION, 0 ) < self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Drop the tables (uninstall).
	 */
	public static function uninstall() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- fixed table names.
		}
		delete_option( self::OPTION );
	}
}
