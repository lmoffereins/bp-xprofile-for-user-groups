<?php

/**
 * The BP XProfile For User Groups Plugin
 *
 * Requires BP 2.1
 *
 * @package BP XProfile For User Groups
 * @subpackage Main
 *
 * @todo Field visibility status: 'This field can be seen by everyone' is not true
 *        for fields that are assigned to user groups (or for their fieldgroup)
 * @todo Support BP Group Hierarchy
 */

/**
 * Plugin Name:       BP XProfile For User Groups
 * Description:       Manage user group specific profile field(group)s in BuddyPress
 * Plugin URI:        https://github.com/lmoffereins/bp-xprofile-for-user-groups
 * Version:           1.1.0
 * Author:            Laurens Offereins
 * Author URI:        https://github.com/lmoffereins
 * Text Domain:       bp-xprofile-for-user-groups
 * Domain Path:       /languages/
 * GitHub Plugin URI: lmoffereins/bp-xprofile-for-user-groups
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BP_XProfile_For_User_Groups' ) ) :
/**
 * Main Plugin Class
 *
 * @since 1.0.0
 */
final class BP_XProfile_For_User_Groups {

	/**
	 * Setup and return the singleton pattern
	 *
	 * @since 1.1.0
	 *
	 * @uses BP_XProfile_For_User_Groups::setup_actions()
	 * @return BP_XProfile_For_User_Groups
	 */
	public static function instance() {

		// Store the instance locally
		static $instance = null;

		if ( null === $instance ) {
			$instance = new BP_XProfile_For_User_Groups;
			$instance->setup_globals();
			$instance->setup_actions();
		}

		// Always return the instance
		return $instance;
	}

	/**
	 * Setup plugin structure and hooks
	 *
	 * @since 1.0.0
	 */
	private function __construct() { /* Do nothing here */ }

	/**
	 * Setup default class globals
	 *
	 * @since 1.1.0
	 */
	private function setup_globals() {

		/** Version **************************************************/

		$this->version      = '1.1.0';

		/** Plugin ***************************************************/

		$this->file         = __FILE__;
		$this->basename     = plugin_basename( $this->file );
		$this->plugin_dir   = plugin_dir_path( $this->file );
		$this->plugin_url   = plugin_dir_url(  $this->file );

		// Languages
		$this->lang_dir     = trailingslashit( $this->plugin_dir . 'languages' );

		/** Misc *****************************************************/

		$this->domain = 'bp-xprofile-for-user-groups';
	}

	/**
	 * Setup default plugin actions and filters
	 *
	 * @since 1.1.0
	 *
	 * @uses bp_is_active() To check if groups or xprofile component is active
	 */
	private function setup_actions() {

		// Bail when BP < 2.1 or when the groups and xprofile component are not active
		if ( version_compare( buddypress()->version, '2.1', '<' ) || ! bp_is_active( 'groups' ) || ! bp_is_active( 'xprofile' ) )
			return;

		// Plugin
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Fields & fieldgroup filters
		add_filter( 'bp_xprofile_get_hidden_fields_for_user', array( $this, 'filter_hidden_fields' ), 10, 3 );
		add_filter( 'bp_xprofile_get_groups',                 array( $this, 'filter_fieldgroups'   ), 10, 2 );

		// Handle xprofile meta
		add_action( 'xprofile_group_after_submitbox', array( $this, 'fieldgroup_display_metabox' ) );
		add_action( 'xprofile_group_after_save',      array( $this, 'fieldgroup_save_metabox'    ) );
		add_action( 'xprofile_field_after_submitbox', array( $this, 'field_display_metabox'      ) );
		add_action( 'xprofile_field_after_save',      array( $this, 'field_save_metabox'         ) );
		add_action( 'bp_admin_head',                  array( $this, 'print_styles'               ) );

		// Fire plugin loaded hook
		do_action( 'bp_xprofile_for_user_groups_loaded' );
	}

	/** Plugin ****************************************************************/

	/**
	 * Load the translation file for current language
	 *
	 * Note that custom translation files inside the Plugin folder will
	 * be removed on Plugin updates. If you're creating custom translation
	 * files, please use the global language folder.
	 *
	 * @since 1.1.0
	 *
	 * @uses apply_filters() Calls 'plugin_locale' with {@link get_locale()} value
	 * @uses load_textdomain() To load the textdomain
	 * @uses load_plugin_textdomain() To load the plugin textdomain
	 */
	public function load_textdomain() {

		// Traditional WordPress plugin locale filter
		$locale        = apply_filters( 'plugin_locale', get_locale(), $this->domain );
		$mofile        = sprintf( '%1$s-%2$s.mo', $this->domain, $locale );

		// Setup paths to current locale file
		$mofile_local  = $this->lang_dir . $mofile;
		$mofile_global = WP_LANG_DIR . '/bp-xprofile-for-user-groups/' . $mofile;

		// Look in global /wp-content/languages/bp-xprofile-for-user-groups folder first
		load_textdomain( $this->domain, $mofile_global );

		// Look in global /wp-content/languages/plugins/ and local plugin languages folder
		load_plugin_textdomain( $this->domain, false, 'bp-xprofile-for-user-groups/languages' );
	}

	/** Field & Fieldgroup Filters ********************************************/

	/**
	 * Return the field ids that are not visible for the displayed and current user
	 *
	 * First, the displayed user must be a member of both the fieldgroup's and the
	 * field's user groups in order to show the field. Second, the loggedin user
	 * must be a member of both to show the field. If either one fails the user
	 * group membership, the field is added to the hidden fields collection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $hidden_fields Hidden field ids
	 * @param int $displayed_user_id Displayed user ID
	 * @param int $current_user_id Loggedin user ID
	 * @return array Hidden field ids
	 */
	public function filter_hidden_fields( $hidden_fields, $displayed_user_id, $current_user_id ) {
		global $wpdb, $bp;

		// Current user is logged in
		if ( ! empty( $current_user_id ) ) {

			// Hidden = All - Visible for displayed user AND current user
			$all_fields = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$bp->profile->table_name_fields}" ) );

			foreach ( $all_fields as $k => $field_id ) {

				// Is displayed user not a member? Remove field
				if ( ! $this->is_user_field_member( $field_id, $displayed_user_id ) ) {
					$hidden_fields[] = $field_id;
					continue;
				}

				// Is loggedin user not an admin and he cannot view the field? Hide field
				if ( ! bp_current_user_can( 'bp_moderate' ) && ! $this->can_user_view_field( $field_id, $current_user_id ) ) {
					$hidden_fields[] = $field_id;
					continue;
				}
			}

		// Current user is not logged in, so exclude all user group assigned fields
		} else {

			// Query all fieldgroups and fields with for-user-groups meta
			$objects = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$bp->profile->table_name_meta} WHERE ( object_type = %s OR object_type = %s ) AND meta_key = %s", 'group', 'field', 'for-user-groups' ) );

			// Loop the objects with meta
			foreach ( $objects as $item ) {

				// Skip primary field(group)
				if ( 1 == $item->object_id )
					continue;

				// Skip if field is already hidden
				if ( 'field' == $item->object_type && in_array( $item->object_id, $hidden_fields ) )
					continue;

				// Prepare array meta value
				$groups = maybe_unserialize( $item->meta_value );

				// Bail when no user groups are assigned
				if ( empty( $groups ) )
					continue;

				// Object has user groups. Check object type to take action
				switch ( $item->object_type ) {

					// Fieldgroup
					case 'group' :

						// Get fieldgroup's field ids
						$fields = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$bp->profile->table_name_fields} WHERE group_id = %d", $item->object_id ) );

						// Hide all its fields
						foreach ( $fields as $field_id ) {
							$hidden_fields[] = $field_id;
						}

						break;

					// Field
					case 'field' :

						// Hide single field
						$hidden_fields[] = $item->object_id;

						break;
				}
			}
		}

		// Sanitize return value
		$hidden_fields = array_unique( $hidden_fields );

		return $hidden_fields;
	}

	/**
	 * Return the fieldgroups filtered for the current user's group membership
	 *
	 * First, the displayed user must be a member to show the fieldgroup. Second,
	 * the loggedin user must be a member to show the fieldgroup. If either one
	 * fails the fieldgroup's user group membership, it's removed.
	 *
	 * Since BP 2.1.0.
	 *
	 * @since 1.0.0
	 *
	 * @uses BP_XProfile_For_User_Groups::is_user_fieldgroup_member()
	 * @uses bp_displayed_user_id()
	 * @uses bp_loggedin_user_id()
	 *
	 * @param array $groups Fieldgroup objects
	 * @param array $args Query arguments
	 * @return array Field groups
	 */
	public function filter_fieldgroups( $groups, $args ) {

		// Loop all groups
		foreach ( $groups as $k => $group ) {

			// Keep the primary fieldgroup
			if ( 1 == $group->id )
				continue;

			// Is displayed user not a member? Remove fieldgroup
			if ( ! $this->is_user_fieldgroup_member( $group->id, isset( $args['user_id'] ) ? $args['user_id'] : bp_displayed_user_id() ) ) {
				unset( $groups[$k] );
				continue;
			}

			// Is loggedin user not an admin and he cannot view the fieldgroup? Hide fieldgroup
			if ( ! bp_current_user_can( 'bp_moderate' ) && ! $this->can_user_view_fieldgroup( $group->id ) ) {
				unset( $groups[$k] );
				continue;
			}
		}

		// Reorder nicely
		$groups = array_values( $groups );

		return $groups;
	}

	/** Is User Member ********************************************************/

	/**
	 * Return whether the user is member of the fieldgroup's user groups
	 *
	 * @since 1.0.0
	 *
	 * @uses bp_displayed_user_id()
	 * @uses groups_get_groups()
	 * @uses BP_XProfile_For_User_Groups::get_fieldgroup_user_groups_having()
	 *
	 * @param int $fieldgroup_id Field group ID
	 * @param int $user_id Optional. User ID. Defaults to the displayed user.
	 * @return bool User is field group's user group member
	 */
	public function is_user_fieldgroup_member( $fieldgroup_id, $user_id = 0 ) {

		// Bail if this is the primary fieldgroup
		if ( 1 == $fieldgroup_id )
			return true;

		// Default to displayed user
		if ( ! is_numeric( $user_id ) ) {
			$user_id = bp_displayed_user_id();
		}

		// Get fieldgroups' having groups ids
		$group_ids = $this->get_fieldgroup_user_groups_having( $fieldgroup_id );

		// Groups are assigned
		if ( ! empty( $group_ids ) ) {

			// Get user's memberships limited to fieldgroup's user groups
			$groups = groups_get_groups( array(
				'user_id'         => $user_id,
				'include'         => $group_ids,
				'show_hidden'     => true,
				'per_page'        => false,
				'populate_extras' => false,
			) );

			return (bool) $groups['groups'];

		// No groups were assigned, so user has access
		} else {
			return true;
		}
	}

	/**
	 * Return whether the user is member of the field's user groups
	 *
	 * @since 1.0.0
	 *
	 * @uses bp_displayed_user_id()
	 * @uses BP_XProfile_For_User_Groups::is_user_fieldgroup_member()
	 * @uses groups_get_groups()
	 * @uses BP_XProfile_For_User_Groups::get_field_user_groups_having()
	 *
	 * @param int $fieldgroup_id Field group ID
	 * @param int $user_id Optional. User ID. Defaults to the displayed user.
	 * @param bool $check_fieldgroup Optional. Whether to do an early parent fieldgroup's membership check.
	 * @return bool User is field's user group member
	 */
	public function is_user_field_member( $field_id, $user_id = 0, $check_fieldgroup = true ) {

		// Bail if this is the primary field
		if ( 1 == $field_id || ( is_object( $field_id ) && 1 == $field_id->id ) )
			return true;

		// Default to displayed user
		if ( ! is_numeric( $user_id ) ) {
			$user_id = bp_displayed_user_id();
		}

		// Check parent fieldgroup membership
		if ( true === $check_fieldgroup ) {

			// Get the field object to find the fieldgroup ID
			if ( is_object( $field_id ) ) {
				$field = $field_id;
			} else {
				$field = new BP_XProfile_Field( $field_id );
			}

			// Bail early if user is not member of fieldgroup's user groups
			if ( ! $this->is_user_fieldgroup_member( $field->group_id, $user_id ) ) {
				return false;
			}
		}

		// Get field ID
		if ( is_object( $field_id ) )
			$field_id = $field_id->id;

		// Get field's having groups ids
		$group_ids = $this->get_field_user_groups_having( $field_id );

		// Groups are assigned
		if ( ! empty( $group_ids ) ) {

			// Get user's memberships limited to field's user groups
			$groups = groups_get_groups( array(
				'user_id'         => $user_id,
				'include'         => $group_ids,
				'show_hidden'     => true,
				'per_page'        => false,
				'populate_extras' => false,
			) );

			return (bool) $groups['groups'];

		// No groups were assigned, so user has access
		} else {
			return true;
		}
	}

	/** Can User View *********************************************************/

	/**
	 * Return whether the user can view the fieldgroup
	 *
	 * @since 1.1.0
	 *
	 * @uses bp_loggedin_user_id()
	 * @uses groups_get_groups()
	 * @uses BP_XProfile_For_User_Groups::is_user_fieldgroup_member()
	 * @uses BP_XProfile_For_User_Groups::get_fieldgroup_user_groups_viewing()
	 *
	 * @param int $fieldgroup_id Field group ID
	 * @param int $user_id Optional. User ID. Defaults to the current user.
	 * @return bool User can view the fieldgroup
	 */
	public function can_user_view_fieldgroup( $fieldgroup_id, $user_id = 0 ) {

		// Bail if this is the primary fieldgroup
		if ( 1 == $fieldgroup_id )
			return true;

		// Default to current user
		if ( empty( $user_id ) || ! is_numeric( $user_id ) ) {
			$user_id = bp_loggedin_user_id();
		}

		// Fieldgroup members can always view their data
		if ( $this->is_user_fieldgroup_member( $fieldgroup_id, $user_id ) )
			return true;

		// Get fieldgroups' viewing groups ids
		$group_ids = $this->get_fieldgroup_user_groups_viewing( $fieldgroup_id );

		// Groups are assigned
		if ( ! empty( $group_ids ) ) {

			// Get user's memberships limited to fieldgroup's user groups
			$groups = groups_get_groups( array(
				'user_id'         => $user_id,
				'include'         => $group_ids,
				'show_hidden'     => true,
				'per_page'        => false,
				'populate_extras' => false,
			) );

			return (bool) $groups['groups'];

		// No groups were assigned, so user has access
		} else {
			return true;
		}
	}

	/**
	 * Return whether the user can view the field
	 *
	 * @since 1.1.0
	 *
	 * @uses bp_loggedin_user_id()
	 * @uses BP_XProfile_For_User_Groups::can_user_view_fieldgroup()
	 * @uses groups_get_groups()
	 * @uses BP_XProfile_For_User_Groups::is_user_field_member()
	 * @uses BP_XProfile_For_User_Groups::get_field_user_groups_viewing()
	 *
	 * @param int $fieldgroup_id Field group ID
	 * @param int $user_id Optional. User ID. Defaults to the current user.
	 * @param bool $check_fieldgroup Optional. Whether to do an early parent fieldgroup's membership check.
	 * @return bool User is field's user group member
	 */
	public function can_user_view_field( $field_id, $user_id = 0, $check_fieldgroup = true ) {

		// Bail if this is the primary field
		if ( 1 == $field_id || ( is_object( $field_id ) && 1 == $field_id->id ) )
			return true;

		// Default to current user
		if ( ! is_numeric( $user_id ) ) {
			$user_id = bp_loggedin_user_id();
		}

		// Check parent fieldgroup membership
		if ( true === $check_fieldgroup ) {

			// Get the field object to find the fieldgroup ID
			if ( is_object( $field_id ) ) {
				$field = $field_id;
			} else {
				$field = new BP_XProfile_Field( $field_id );
			}

			// Bail early if user cannot view the fieldgroup
			if ( ! $this->can_user_view_fieldgroup( $field->group_id, $user_id ) ) {
				return false;
			}
		}

		// Get field ID
		if ( is_object( $field_id ) )
			$field_id = $field_id->id;

		// Field members can always view their data
		if ( $this->is_user_field_member( $field_id, $user_id ) )
			return true;

		// Get field's viewing groups ids
		$group_ids = $this->get_field_user_groups_viewing( $field_id );

		// Groups are assigned
		if ( ! empty( $group_ids ) ) {

			// Get user's memberships limited to field's user groups
			$groups = groups_get_groups( array(
				'user_id'         => $user_id,
				'include'         => $group_ids,
				'show_hidden'     => true,
				'per_page'        => false,
				'populate_extras' => false,
			) );

			return (bool) $groups['groups'];

		// No groups were assigned, so user has access
		} else {
			return true;
		}
	}

	/** Groups Having *********************************************************/

	/**
	 * Return the xprofile fieldgroup assigned user groups that have the fieldgroup
	 *
	 * @since 1.0.0
	 *
	 * @param int $fieldgroup_id Fieldgroup ID
	 * @return array Fieldgroup having user group ids
	 */
	public function get_fieldgroup_user_groups_having( $fieldgroup_id ) {
		$meta = bp_xprofile_get_meta( $fieldgroup_id, 'group', 'for-user-groups' );

		// Sanitize meta
		if ( empty( $meta ) )
			$meta = array();

		return (array) $meta;
	}

	/**
	 * Return the xprofile field assigned user groups that have the field
	 *
	 * @since 1.0.0
	 *
	 * @param int $field_id Field ID
	 * @return array Field having user field ids
	 */
	public function get_field_user_groups_having( $field_id ) {
		$meta = bp_xprofile_get_meta( $field_id, 'field', 'for-user-groups' );

		// Sanitize meta
		if ( empty( $meta ) )
			$meta = array();

		return (array) $meta;
	}

	/**
	 * Update the fieldgroup's user groups meta value
	 *
	 * @since 1.0.0
	 *
	 * @uses bp_xprofile_update_fieldgroup_meta()
	 * @param int $fieldgroup_id Fieldgroup ID
	 * @param array $groups Assigned having user group ids
	 */
	public function update_fieldgroup_user_groups_having( $fieldgroup_id, $groups ) {

		// Sanitize input
		$groups = array_map( 'intval', (array) $groups );

		// Update group meta
		bp_xprofile_update_fieldgroup_meta( $fieldgroup_id, 'for-user-groups', $groups );
	}

	/**
	 * Update the field's user groups meta value
	 *
	 * @since 1.0.0
	 *
	 * @uses bp_xprofile_update_field_meta()
	 * @param int $field_id Field ID
	 * @param array $groups Assigned having user group ids
	 */
	public function update_field_user_groups_having( $field_id, $groups ) {

		// Sanitize input
		$groups = array_map( 'intval', (array) $groups );

		// Update group meta
		bp_xprofile_update_field_meta( $field_id, 'for-user-groups', $groups );
	}

	/** Groups Viewing ********************************************************/

	/**
	 * Return the xprofile fieldgroup assigned user groups that can view the fieldgroup
	 *
	 * @since 1.1.0
	 *
	 * @param int $fieldgroup_id Fieldgroup ID
	 * @return array Fieldgroup viewing user group ids
	 */
	public function get_fieldgroup_user_groups_viewing( $fieldgroup_id ) {
		$meta = bp_xprofile_get_meta( $fieldgroup_id, 'group', 'for-user-groups-viewing' );

		// Sanitize meta
		if ( empty( $meta ) )
			$meta = array();

		return (array) $meta;
	}

	/**
	 * Return the xprofile field assigned user groups that can view the field
	 *
	 * @since 1.1.0
	 *
	 * @param int $field_id Field ID
	 * @return array Field viewing user field ids
	 */
	public function get_field_user_groups_viewing( $field_id ) {
		$meta = bp_xprofile_get_meta( $field_id, 'field', 'for-user-groups-viewing' );

		// Sanitize meta
		if ( empty( $meta ) )
			$meta = array();

		return (array) $meta;
	}

	/**
	 * Update the fieldgroup's viewing user groups meta value
	 *
	 * @since 1.1.0
	 *
	 * @uses bp_xprofile_update_fieldgroup_meta()
	 * @param int $fieldgroup_id Fieldgroup ID
	 * @param array $groups Assigned viewing user group ids
	 */
	public function update_fieldgroup_user_groups_viewing( $fieldgroup_id, $groups ) {

		// Sanitize input
		$groups = array_map( 'intval', (array) $groups );

		// Update group meta
		bp_xprofile_update_fieldgroup_meta( $fieldgroup_id, 'for-user-groups-viewing', $groups );
	}

	/**
	 * Update the field's viewing user groups meta value
	 *
	 * @since 1.1.0
	 *
	 * @uses bp_xprofile_update_field_meta()
	 * @param int $field_id Field ID
	 * @param array $groups Assigned viewing user group ids
	 */
	public function update_field_user_groups_viewing( $field_id, $groups ) {

		// Sanitize input
		$groups = array_map( 'intval', (array) $groups );

		// Update group meta
		bp_xprofile_update_field_meta( $field_id, 'for-user-groups-viewing', $groups );
	}

	/** Metaboxes *************************************************************/

	/**
	 * Output the metabox for fieldgroup assigned user groups
	 *
	 * Since BP 2.1.0.
	 *
	 * @since 1.0.0
	 *
	 * @param BP_XProfile_Group $fieldgroup Current xprofile fieldgroup
	 */
	public function fieldgroup_display_metabox( $fieldgroup ) {

		// The primary fieldgroup is for all, so bail
		if ( 1 == $fieldgroup->id )
			return;

		// Setup user group query args
		$args = array(
			'orderby'         => 'name',
			'order'           => 'ASC',
			'show_hidden'     => true,
			'per_page'        => false,
			'populate_extras' => false,
		);

		// Get all, having and viewing user groups
		$groups  = groups_get_groups( $args );
		$class   = array( 'user_groups' );
		$class[] = count( $groups['groups'] ) > 6 ? 'scroll' : '';
		$having  = ! empty( $fieldgroup->id ) ? $this->get_fieldgroup_user_groups_having(  $fieldgroup->id ) : array();
		$viewing = ! empty( $fieldgroup->id ) ? $this->get_fieldgroup_user_groups_viewing( $fieldgroup->id ) : array();

		?>

		<div id="for_user_groups" class="postbox">
			<h3><?php _e( 'User Groups', 'bp-xprofile-for-user-groups' ); ?> <?php $this->the_info_toggler(); ?></h3>
			<div class="inside">
				<p class="metabox-info">
					<?php _e( 'Assign user groups to the profile field group to limit its applicability to the members of that group.', 'bp-xprofile-for-user-groups' ); ?>
				</p>

				<p><span class="description"><?php _e( 'Restrict the applicability of this fieldgroup', 'bp-xprofile-for-user-groups' ); ?>:</span></p>

				<ul class="groups_having <?php echo implode( ' ', $class ); ?>">
					<?php foreach ( $groups['groups'] as $group ) : ?>

					<li><label><input name="for-user-groups[]" type="checkbox" value="<?php echo $group->id; ?>" <?php checked( in_array( $group->id, $having ) ); ?> /> <?php echo $group->name; ?></label></li>

					<?php endforeach; ?>
				</ul><!-- .groups_having -->

				<p><span class="description"><?php _e( 'Restrict the visibility of this fieldgroup', 'bp-xprofile-for-user-groups' ); ?>:</span></p>

				<ul class="groups_viewing <?php echo implode( ' ', $class ); ?>">
					<?php foreach ( $groups['groups'] as $group ) : ?>

					<li><label><input name="for-user-groups-viewing[]" type="checkbox" value="<?php echo $group->id; ?>" <?php checked( in_array( $group->id, $viewing ) ); ?> /> <?php echo $group->name; ?></label></li>

					<?php endforeach; ?>
				</ul><!-- .groups_viewing -->
			</div>

			<?php wp_nonce_field( 'for-user-groups', '_wpnonce_for_user_groups' ); ?>
		</div>

		<?php
	}

	/**
	 * Save the metabox for fieldgroup assigned user groups
	 *
	 * @since 1.0.0
	 *
	 * @param BP_XProfile_Group $fieldgroup Saved xprofile group
	 */
	public function fieldgroup_save_metabox( $fieldgroup ) {

		// Bail if nonce does not verify
		if ( ! wp_verify_nonce( $_REQUEST['_wpnonce_for_user_groups'], 'for-user-groups' ) )
			return;

		// Walk for having and viewing groups
		foreach ( array(
			'having'  => 'for-user-groups',
			'viewing' => 'for-user-groups-viewing'
		) as $type => $name ) {

			// Sanitize input
			if ( isset( $_REQUEST[ $name ] ) ) {
				$groups = array_map( 'intval', (array) $_REQUEST[ $name ] );

			// No user groups selected
			} else {
				$groups = array();
			}

			// Update if something changed
			if ( call_user_func_array( array( $this, "get_fieldgroup_user_groups_{$type}" ), array( $fieldgroup->id ) ) == $groups ) {
				call_user_func_array( array( $this, "update_fieldgroup_user_groups_{$type}" ), array( $fieldgroup->id, $groups ) );
			}
		}
	}

	/**
	 * Output the metabox for field assigned user groups
	 *
	 * Since BP 2.1.0.
	 *
	 * @since 1.0.0
	 *
	 * @param BP_XProfile_Field $field Current xprofile field
	 */
	public function field_display_metabox( $field ) {

		// The primary field is for all, so bail
		if ( 1 == $field->id )
			return;

		// Query args for user groups from the parent field group
		$args = array(
			'orderby'         => 'name',
			'order'           => 'ASC',
			'show_hidden'     => true,
			'include'         => $this->get_fieldgroup_user_groups_having( $field->group_id ),
			'per_page'        => false,
			'populate_extras' => false,
		);

		// Get all, having and viewing user groups
		$groups  = groups_get_groups( $args );
		$class   = array( 'user_groups' );
		$class[] = count( $groups['groups'] ) > 6 ? 'scroll' : '';
		$having  = ! empty( $field->id ) ? $this->get_field_user_groups_having(  $field->id ) : array();
		$viewing = ! empty( $field->id ) ? $this->get_field_user_groups_viewing( $field->id ) : array();

		?>

		<div id="for_user_groups" class="postbox">
			<h3><?php _e( 'User Groups', 'bp-xprofile-for-user-groups' ); ?> <?php $this->the_info_toggler(); ?></h3>
			<div class="inside">
				<p class="metabox-info">
					<?php _e( 'Assign user groups to the profile field to limit its applicability to the members of that group. Selectable groups are limited to the ones assigned to the parent field group.', 'bp-xprofile-for-user-groups' ); ?>
				</p>

				<p><span class="description"><?php _e( 'Restrict the applicability of this field', 'bp-xprofile-for-user-groups' ); ?>:</span></p>

				<ul class="groups_having <?php echo implode( ' ', $class ); ?>">
					<?php foreach ( $groups['groups'] as $group ) : ?>

					<li><label><input name="for-user-groups[]" type="checkbox" value="<?php echo $group->id; ?>" <?php checked( in_array( $group->id, $having ) ); ?> /> <?php echo $group->name; ?></label></li>

					<?php endforeach; ?>
				</ul><!-- .groups_having -->

				<p><span class="description"><?php _e( 'Restrict the visibility of this field', 'bp-xprofile-for-user-groups' ); ?>:</span></p>

				<ul class="groups_viewing <?php echo implode( ' ', $class ); ?>">
					<?php foreach ( $groups['groups'] as $group ) : ?>

					<li><label><input name="for-user-groups-viewing[]" type="checkbox" value="<?php echo $group->id; ?>" <?php checked( in_array( $group->id, $viewing ) ); ?> /> <?php echo $group->name; ?></label></li>

					<?php endforeach; ?>
				</ul><!-- .groups_viewing -->
			</div>

			<?php wp_nonce_field( 'for-user-groups', '_wpnonce_for_user_groups' ); ?>
		</div>

		<?php
	}

	/**
	 * Save the metabox for field assigned user groups
	 *
	 * @since 1.0.0
	 *
	 * @param BP_XProfile_Field $field Saved xprofile field
	 */
	public function field_save_metabox( $field ) {

		// Bail if nonce does not verify
		if ( ! wp_verify_nonce( $_REQUEST['_wpnonce_for_user_groups'], 'for-user-groups' ) )
			return;

		// Walk for having and viewing groups
		foreach ( array(
			'having'  => 'for-user-groups',
			'viewing' => 'for-user-groups-viewing'
		) as $type => $name ) {

			// Sanitize input
			if ( isset( $_REQUEST[ $name ] ) ) {
				$groups = array_map( 'intval', (array) $_REQUEST[ $name ] );

			// No user groups selected
			} else {
				$groups = array();
			}

			// Update if something changed
			if ( call_user_func_array( array( $this, "get_field_user_groups_{$type}" ), array( $field->id ) ) != $groups ) {
				call_user_func_array( array( $this, "update_field_user_groups_{$type}" ), array( $field->id, $groups ) );
			}
		}
	}

	/**
	 * Output the metabox information toggle button
	 *
	 * @since 1.0.1
	 */
	public function the_info_toggler() {
		printf( '<i class="dashicons-before dashicons-info" title="%s"></i>', __( 'Toggle metabox information', 'bp-xprofile-for-user-groups' ) );
	}

	/**
	 * Output specific metabox styles for the xprofile admin
	 *
	 * @since 1.0.0
	 */
	public function print_styles() {

		// Bail when this is not an xprofile admin page
		if ( ! isset( get_current_screen()->id ) || 'users_page_bp-profile-setup' != get_current_screen()->id )
			return; ?>

		<style type="text/css">
			#for_user_groups .inside ul.user_groups.scroll {
				padding: 2px 5px 0;
				margin: 1em 0 0 0;
				border: 1px solid #ddd;
				height: 12em;
				overflow-y: scroll;
			}

			#for_user_groups .inside .metabox-info:visible + ul.user_groups.scroll {
				margin: 0;
			}

			#for_user_groups .inside .metabox-info {
				display: none;
			}

			#for_user_groups i.dashicons-info {
				float: right;
				cursor: pointer;
				color: #444;
			}
				#for_user_groups i.dashicons-info:hover {
					color: #000;
				}
		</style>

		<script type="text/javascript">
			jQuery('document').ready( function( $ ) {
				// Toggle metabox information
				var $box = $('#for_user_groups');
				$box.on( 'click', 'i.dashicons-info', function() {
					$box.find( 'p.metabox-info' ).toggle();
				});
			});
		</script>

		<?php
	}
}

/**
 * Initiate plugin class and return singleton
 *
 * @since 1.0.0
 *
 * @return BP_XProfile_For_User_Groups
 */
function bp_xprofile_for_user_groups() {
	return BP_XProfile_For_User_Groups::instance();
}

// Fire it up!
add_action( 'bp_loaded', 'bp_xprofile_for_user_groups' );

endif; // class_exists
