<?php

/**
 * Class Dfrpswc_Plugin_Dependency
 *
 * USAGE:
 * ++++++++++++++++++++++++++++++++++++++++
 * $dependency = new Dfrpswc_Plugin_Dependency( 'WooCommerce', 'woocommerce/woocommerce.php', '3.2.0' );
 * $action = $dependency->action_required();
 * if ( $action ) {
 *      echo '<div class="notice notice-error"><p>';
 *      echo $dependency->msg( 'Datafeedr API' );
 *      echo $dependency->link();
 *      echo '</p></div>';
 * }
 */
class Dfrpswc_Plugin_Dependency {

	/**
	 * Name of required plugin.
	 *
	 * Example: WooCommerce
	 *
	 * @since 1.0.0
	 * @access protected
	 * @var string $name
	 */
	protected $name;

	/**
	 * File name of plugin.
	 *
	 * Example: woocommerce/woocommerce.php
	 *
	 * @since 1.0.0
	 * @access protected
	 * @var string $file
	 */
	protected $file;

	/**
	 * The minimum version of the plugin required.
	 *
	 * Example: 3.2.6
	 *
	 * @since 1.0.0
	 * @access protected
	 * @var string $version
	 */
	protected $version;

	/**
	 * Whether the plugin is required.
	 *
	 * If the plugin is not required, only a version check will be performed.
	 *
	 * @since 1.0.0
	 * @access protected
	 * @var bool $required
	 */
	protected $required;

	/**
	 * Data returned by get_plugin_data() about the required plugin.
	 *
	 * @since 1.0.0
	 * @access protected
	 * @var array $data
	 */
	protected $data;

	/**
	 * Datafeedr_Plugin_Dependency constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name
	 * @param string $file
	 * @param string $version
	 * @param bool $required
	 */
	public function __construct( $name, $file, $version, $required = true ) {
		$this->name     = $name;
		$this->file     = $file;
		$this->version  = $version;
		$this->required = $required;
		$this->set_data();
	}

	/**
	 * Sets the $this->data field.
	 *
	 * @since 1.0.0
	 */
	protected function set_data() {
		$this->data = ( $this->is_installed() ) ? get_plugin_data( $this->plugin_path() ) : array();
	}

	/**
	 * Returns "install", "activate" or "update" if an action is required for the required plugin.
	 *
	 * If no action is required, returns false.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|string Returns "install", "activate" or "update" or false.
	 */
	public function action_required() {

		if ( ! $this->is_installed() && $this->required ) {
			return 'install';
		}

		if ( ! $this->is_active() && $this->required ) {
			return 'activate';
		}

		if ( ! $this->version_is_compatible() && $this->is_active() ) {
			return 'update';
		}

		return false;
	}

	/**
	 * Returns the required plugin's name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function plugin_name() {
		return $this->name;
	}

	/**
	 * Returns true if the required plugin is installed otherwise returns false.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_installed() {
		return ( file_exists( $this->plugin_path() ) );
	}

	/**
	 * Returns true if the required plugin is activated otherwise returns false.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_active() {
		return ( is_plugin_active( $this->file ) );
	}

	/**
	 * Returns true if the required plugin meets the minimum version required otherwise returns false.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function version_is_compatible() {
		return ( version_compare( $this->current_version(), $this->required_version(), '>=' ) );
	}

	/**
	 * Returns the "installation" url if the user can install plugins. Otherwise returns an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function install_url() {

		if ( ! current_user_can( 'install_plugins' ) ) {
			return '';
		}

		$plugin_name = $this->plugin_action_name();

		$url = add_query_arg( array(
			'action' => 'install-plugin',
			'plugin' => $plugin_name
		), wp_nonce_url( admin_url( 'update.php' ), 'install-plugin_' . $plugin_name ) );

		return $url;
	}

	/**
	 * Returns the "activation" url if the user can activate this plugin. Otherwise returns an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function activate_url() {

		if ( ! current_user_can( 'activate_plugin', $this->file ) ) {
			return '';
		}

		$url = add_query_arg( array(
			'action' => 'activate',
			'plugin' => urlencode( $this->file ),
			'paged'  => '1',
			's'      => '',
		), wp_nonce_url( admin_url( 'plugins.php' ), 'activate-plugin_' . $this->file ) );

		return $url;
	}

	/**
	 * Returns the "update" url if the user can update plugins. Otherwise returns an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function update_url() {

		if ( ! current_user_can( 'update_plugins' ) ) {
			return '';
		}

		$url = add_query_arg( array(
			'action' => 'upgrade-plugin',
			'plugin' => urlencode( $this->file )
		), wp_nonce_url( admin_url( 'update.php' ), 'upgrade-plugin_' . $this->file ) );

		return $url;
	}

	/**
	 * Returns the current version of the installed plugin of 0 if plugin does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function current_version() {
		$data = $this->plugin_data();

		return ( isset( $data['Version'] ) ) ? $data['Version'] : '0';
	}

	/**
	 * Returns the minimum version of the required plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function required_version() {
		return $this->version;
	}

	/**
	 * Retuns the message to display to the user if the required plugins needs to
	 * be installed, activated or updated.
	 *
	 * @since 1.0.0
	 *
	 * @see install_msg(), activate_msg(), update_msg()
	 *
	 * @param string $request_plugin Name of the requesting plugin.
	 *
	 * @return string
	 */
	public function msg( $request_plugin ) {

		$action = $this->action_required();

		if ( ! $action ) {
			return '';
		}

		$func = $action . '_msg';

		return $this->$func( $request_plugin );
	}

	/**
	 * Returns the full <a href="..."></a> link for an install/activate/update action.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $button Whether to display the link as a button.
	 * @param string $text Override the link's anchor text.
	 *
	 * @return string HTML.
	 */
	public function link( $button = true, $text = '' ) {

		$action = $this->action_required();

		if ( ! $action ) {
			return '';
		}

		// Calls install_url(), activate_url() or update_url().
		$func = $action . '_url';
		$url  = $this->$func();

		// Create the title attribute.
		$title = sprintf(
			__( '%1$s the %2$s plugin now.', 'datafeedr' ),
			esc_attr( ucwords( strtolower( $action ) ) ),
			esc_attr( $this->plugin_name() )
		);

		// Link CSS class.
		$class = ( $button ) ? 'button button-small button-primary' : '';

		// The anchor text for the link.
		$text = ( ! empty( $text ) ) ? esc_html( $text ) : esc_html( ucwords( strtolower( $action ) ) );

		// The HTML to return;
		$html = ' <a href="%1$s" title="%2$s" class="%3$s">%4$s</a>';

		return sprintf( $html, $url, $title, $class, $text );
	}

	/**
	 * Returns the message notifying the user that the required plugin must be installed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $request_plugin Name of the requesting plugin.
	 *
	 * @return string Message to user.
	 */
	protected function install_msg( $request_plugin ) {
		$msg = __(
			'The %1$s plugin requires the <strong>%2$s</strong> plugin to be installed.',
			'datafeedr'
		);

		return sprintf( $msg, $request_plugin, $this->wporg_link(), $this->plugin_name() );
	}

	/**
	 * Returns the message notifying the user that the required plugin must be activated.
	 *
	 * @since 1.0.0
	 *
	 * @param string $request_plugin Name of the requesting plugin.
	 *
	 * @return string Message to user.
	 */
	protected function activate_msg( $request_plugin ) {
		$msg = __(
			'The %1$s plugin requires the <strong>%2$s</strong> plugin to be activated.',
			'datafeedr'
		);

		return sprintf( $msg, $request_plugin, $this->wporg_link() );
	}

	/**
	 * Returns the message notifying the user that the required plugin must be updated.
	 *
	 * @since 1.0.0
	 *
	 * @param string $request_plugin Name of the requesting plugin.
	 *
	 * @return string Message to user.
	 */
	protected function update_msg( $request_plugin ) {
		$msg = __(
			'The %1$s plugin requires <strong>%2$s version %3$s</strong> or greater.',
			'datafeedr'
		);

		return sprintf( $msg, $request_plugin, $this->wporg_link( $this->plugin_name() ),
			$this->required_version() );
	}

	/**
	 * Returns an link <a href="..." target="_blank"></a> to the required plugin on wordpress.org's site.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Optional. Override anchor text.
	 *
	 * @return string HTML
	 */
	public function wporg_link( $text = '' ) {
		$url   = $this->wporg_url();
		$title = __( 'View this plugin on wordpress.org', 'datafeedr' );
		$text  = ( empty( $text ) ) ? esc_html( $this->plugin_name() ) : esc_html( $text );
		$html  = '<a href="%1$s" title="%2$s" target="_blank">%3$s</a>';

		return sprintf( $html, $url, $title, $text );
	}

	/**
	 * Returns the URL to the required plugin's wordpress.org page.
	 *
	 * @since 1.0.0
	 *
	 * @return string URL
	 */
	public function wporg_url() {
		return sprintf( 'https://wordpress.org/plugins/%1$s/', $this->plugin_action_name() );
	}

	/**
	 * Returns the $this->data property.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function plugin_data() {
		return $this->data;
	}

	/**
	 * Returns the full path to the plugin file.
	 *
	 * Example: /home/username/public_html/wp-content/plugins/woocommerce/woocommerce.php
	 *
	 * @since 1.0.0
	 *
	 * @return string Full path
	 */
	protected function plugin_path() {
		return $this->plugins_dir() . $this->file;
	}

	/**
	 * Returns the full path to the WordPress plugin's directory WITH trailing slash.
	 *
	 * Example: /home/username/public_html/wp-content/plugins/
	 *
	 * @since 1.0.0
	 *
	 * @return string Full path with trailing slash.
	 */
	protected function plugins_dir() {
		return trailingslashit( WP_PLUGIN_DIR );
	}

	/**
	 * Returns the first part of the $this->file name.
	 *
	 * If the $this->file is "woocommerce/woocommerce.php" then this method will
	 * return "woocommerce".
	 *
	 * If the $this->file is "wordpress-seo/wp-seo.php", then this method will
	 * return "wordpress-seo".
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function plugin_action_name() {
		$name = explode( '/', $this->file );
		$name = str_replace( '.php', '', $name[0] );

		return $name;
	}
}
