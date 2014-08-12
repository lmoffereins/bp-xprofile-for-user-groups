<?php

/**
 * The BP XProfile For User Groups Plugin
 *
 * Requires BP 2.1+
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
 * Version:           1.0.0
 * Author:            Laurens Offereins
 * Author URI:        https://github.com/lmoffereins
 * Text Domain:       bp-xprofile-for-user-groups
 * Domain Path:       /languages/
 * GitHub Plugin URI: lmoffereins/bp-xprofile-for-user-groups
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'BP_XProfile_For_User_Groups' ) ) :
/**
 * Main Plugin Class
 *
 * @since 1.0.0
 */
class BP_XProfile_For_User_Groups {

	/**
	 * Setup plugin structure and hooks
	 * 
	 * @since 1.0.0
	 *
	 * @uses add_filter()
	 * @uses add_action()
	 */
	public function __construct() {

		// Fields & fieldgroup filters
		add_filter( 'bp_xprofile_get_hidden_fields_for_user', array( $this, 'filter_hidden_fields' ), 10, 3 );
		add_filter( 'bp_xprofile_get_groups',                 array( $this, 'filter_fieldgroups'   ), 10, 2 );

		// Handle xprofile meta
		add_action( 'xprofile_group_after_submitbox', array( $this, 'fieldgroup_display_metabox' ) );
		add_action( 'xprofile_group_after_save',      array( $this, 'fieldgroup_save_metabox'    ) );
		add_action( 'xprofile_field_after_submitbox', array( $this, 'field_display_metabox'      ) );
		add_action( 'xprofile_field_after_save',      array( $this, 'field_save_metabox'         ) );
		add_action( 'bp_admin_head',                  array( $this, 'print_styles'               ) );
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
				if ( ! $this->is_user_field_member( $field_id, bp_displayed_user_id() ) ) {
					$hidden_fields[] = $field_id;
					continue;
				}

				// Is loggedin user not an admin and not a member? Hide field
				if ( ! bp_current_user_can( 'bp_moderate' ) && ! $this->is_user_field_member( $field_id, bp_loggedin_user_id() ) ) {
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

						// Get fieldgroup's fields
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
			if ( ! $this->is_user_fieldgroup_member( $group->id, bp_displayed_user_id() ) ) {
				unset( $groups[$k] );
				continue;
			}

			// Is loggedin user not an admin and not a member? Hide fieldgroup
			if ( ! bp_current_user_can( 'bp_moderate' ) && ! $this->is_user_fieldgroup_member( $group->id, bp_loggedin_user_id() ) ) {
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
	 * @uses BP_XProfile_For_User_Groups::get_fieldgroup_user_groups()
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
		if ( empty( $user_id ) )
			$user_id = bp_displayed_user_id();

		// Get user's memberships limited to fieldgroup's user groups
		$groups = groups_get_groups( array( 
			'user_id'         => $user_id, 
			'include'         => $this->get_fieldgroup_user_groups( $fieldgroup_id ), 
			'show_hidden'     => true,
			'per_page'        => false,
			'populate_extras' => false,
		) );

		return (bool) $groups['groups'];
	}

	/**
	 * Return whether the user is member of the field's user groups
	 *
	 * @since 1.0.0
	 *
	 * @uses bp_displayed_user_id()
	 * @uses BP_XProfile_For_User_Groups::is_user_fieldgroup_member()
	 * @uses groups_get_groups()
	 * @uses BP_XProfile_For_User_Groups::get_field_user_groups()
	 *
	 * @param int $fieldgroup_id Field group ID
	 * @param int $user_id Optional. User ID. Defaults to the displayed user.
	 * @param bool $check_fieldgroup Optional. Whether to do an early parent fieldgroup's membership check.
	 * @return bool User is field's user group member
	 */
	public function is_user_field_member( $field_id, $user_id = 0, $check_fieldgroup = true ) {

		// Bail if this is the primary field
		if ( 1 == $field_id )
			return true;

		// Default to displayed user
		if ( empty( $user_id ) )
			$user_id = bp_displayed_user_id();

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

		// Get user's memberships limited to field's user groups
		$groups = groups_get_groups( array( 
			'user_id'         => $user_id, 
			'include'         => $this->get_field_user_groups( $field_id ),
			'show_hidden'     => true,
			'per_page'        => false,
			'populate_extras' => false,
		) );
		
		return (bool) $groups['groups'];
	}

	/** Get/Update ************************************************************/

	/**
	 * Return the xprofile fieldgroup assigned user groups
	 *
	 * @since 1.0.0
	 * 
	 * @param int $fieldgroup_id Fieldgroup ID
	 * @return array Fieldgroup user group ids
	 */
	public function get_fieldgroup_user_groups( $fieldgroup_id ) {
		$meta = bp_xprofile_get_meta( $fieldgroup_id, 'group', 'for-user-groups' );

		// Sanitize meta		
		if ( empty( $meta ) )
			$meta = array();

		return (array) $meta;
	}

	/**
	 * Return the xprofile field assigned user groups
	 *
	 * @since 1.0.0
	 * 
	 * @param int $field_id Field ID
	 * @return array Field user field ids
	 */
	public function get_field_user_groups( $field_id ) {
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
	 * @param array $groups Assigned user group ids
	 */
	public function update_fieldgroup_user_groups( $fieldgroup_id, $groups ) {

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
	 * @param array $groups Assigned user group ids
	 */
	public function update_field_user_groups( $field_id, $groups ) {

		// Sanitize input
		$groups = array_map( 'intval', (array) $groups );

		// Update group meta
		bp_xprofile_update_field_meta( $field_id, 'for-user-groups', $groups );
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

		// The primary fieldgroup cannot is for all, so bail
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

		// Get all and assigned user groups
		$groups = groups_get_groups( $args );
		$assgnd = ! empty( $fieldgroup->id ) ? $this->get_fieldgroup_user_groups( $fieldgroup->id ) : array(); ?>

		<div id="for_user_groups" class="postbox">
			<h3><?php _e( 'Assigned User Groups', 'bp-xprofile-for-user-groups' ); ?></h3>
			<div class="inside">
				<p><?php _e( 'Assign user groups to the profile field group to limit its applicability to the members of that group.', 'bp-xprofile-for-user-groups' ); ?></p>

				<ul class="user_groups">
					<?php foreach ( $groups['groups'] as $group ) : ?>

					<li><label><input name="for-user-groups[]" type="checkbox" value="<?php echo $group->id; ?>" <?php checked( in_array( $group->id, $assgnd ) ); ?> /> <?php echo $group->name; ?></label></li>

					<?php endforeach; ?>
				</ul>
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

		// Check the nonce
		wp_verify_nonce( 'for-user-groups', '_wpnonce_for_user_groups' );

		// Sanitize input
		if ( isset( $_REQUEST['for-user-groups'] ) ) {
			$groups = array_map( 'intval', (array) $_REQUEST['for-user-groups'] );

		// No user groups selected
		} else {
			$groups = array();
		}

		// Bail if nothing changed
		if ( $this->get_fieldgroup_user_groups( $fieldgroup->id ) == $groups ) {
			return;

		// Update group user groups
		} else {
			$this->update_fieldgroup_user_groups( $fieldgroup->id, $groups );
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

		// Field 1 is the fullname field, which cannot be un-assigned
		if ( 1 == $field->id )
			return;

		// Query args for user groups from the parent field group
		$args = array(
			'orderby'         => 'name',
			'order'           => 'ASC',
			'show_hidden'     => true,
			'include'         => $this->get_fieldgroup_user_groups( $field->group_id ),
			'per_page'        => false,
			'populate_extras' => false,
		);

		// Get all and assigned user groups
		$groups = groups_get_groups( $args );
		$assgnd = ! empty( $field->id ) ? $this->get_field_user_groups( $field->id ) : array(); ?>

		<div id="for_user_groups" class="postbox">
			<h3><?php _e( 'Assigned User Groups', 'bp-xprofile-for-user-groups' ); ?></h3>
			<div class="inside">
				<p>
					<?php _e( 'Assign user groups to the profile field to limit its applicability to the members of that group. Selectable groups are limited to the ones assigned to the parent field group.', 'bp-xprofile-for-user-groups' ); ?>
				</p>

				<ul class="user_groups">
					<?php foreach ( $groups['groups'] as $group ) : ?>

					<li><label><input name="for-user-groups[]" type="checkbox" value="<?php echo $group->id; ?>" <?php checked( in_array( $group->id, $assgnd ) ); ?> /> <?php echo $group->name; ?></label></li>

					<?php endforeach; ?>
				</ul>
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

		// Check the nonce
		wp_verify_nonce( 'for-user-groups', '_wpnonce_for_user_groups' );

		// Sanitize input
		if ( isset( $_REQUEST['for-user-groups'] ) ) {
			$groups = array_map( 'intval', (array) $_REQUEST['for-user-groups'] );

		// No user groups selected
		} else {
			$groups = array();
		}

		// Bail if nothing changed
		if ( $this->get_field_user_groups( $field->id ) == $groups ) {
			return;

		// Update field user groups
		} else {
			$this->update_field_user_groups( $field->id, $groups );
		}
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
			#for_user_groups .inside ul.user_groups {
				padding: 2px 5px 0;
				margin: 0;
				border: 1px solid #ddd;
				height: 12em;
				overflow-y: scroll;
			}
		</style>

		<?php
	}
}

/**
 * Initiate plugin class
 *
 * @since 1.0.0
 *
 * @uses bp_is_active()
 * @uses BP_XProfile_For_User_Groups
 */
function bp_xprofile_for_user_groups() {

	// Bail if groups or xprofile component is not active
	if ( ! bp_is_active( 'groups' ) || ! bp_is_active( 'xprofile' ) )
		return;

	new BP_XProfile_For_User_Groups;
}

// Fire it up!
add_action( 'bp_loaded', 'bp_xprofile_for_user_groups' );

endif; // class_exists
