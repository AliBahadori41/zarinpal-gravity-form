<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

register_activation_hook( __FILE__, array( 'Zarinpal_GF_Payment', "add_permissions" ) );
add_action( 'init', array( 'Zarinpal_GF_Payment', 'init' ) );

require_once( 'database.php' );
require_once( 'chart.php' );

class Zarinpal_GF_Payment {

	public static $author = "bahadori";

	private static $version = "2.3.0";
	private static $min_gravityforms_version = "1.9.10";
	private static $config = null;

	
	public static function init() {
		if ( ! class_exists( "GFPersian_Payments" ) || ! defined( 'GF_PERSIAN_VERSION' ) || version_compare( GF_PERSIAN_VERSION, '2.3.1', '<' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'admin_notice_persian_gf' ) );

			return false;
		}

		if ( ! self::is_gravityforms_supported() ) {
			add_action( 'admin_notices', array( __CLASS__, 'admin_notice_gf_support' ) );

			return false;
		}

		add_filter( 'members_get_capabilities', array( __CLASS__, "members_get_capabilities" ) );

		if ( is_admin() && self::has_access() ) {

			add_filter( 'gform_tooltips', array( __CLASS__, 'tooltips' ) );
			add_filter( 'gform_addon_navigation', array( __CLASS__, 'menu' ) );
			add_action( 'gform_entry_info', array( __CLASS__, 'payment_entry_detail' ), 4, 2 );
			add_action( 'gform_after_update_entry', array( __CLASS__, 'update_payment_entry' ), 4, 2 );

			if ( get_option( "gf_zarinpal_configured" ) ) {
				add_filter( 'gform_form_settings_menu', array( __CLASS__, 'toolbar' ), 10, 2 );
				add_action( 'gform_form_settings_page_zarinpal', array( __CLASS__, 'feed_page' ) );
			}

			if ( rgget( "page" ) == "gf_settings" ) {
				RGForms::add_settings_page( array(
						'name'      => 'gf_zarinpal',
						'tab_label' => __( 'درگاه زرین پال', 'gravityformszarinpal' ),
						'title'     => __( 'تنظیمات درگاه زرین پال', 'gravityformszarinpal' ),
						'handler'   => array( __CLASS__, 'settings_page' ),
					)
				);
			}

			if ( self::is_zarinpal_page() ) {
				wp_enqueue_script( array( "sack" ) );
				self::setup();
			}

			add_action( 'wp_ajax_gf_zarinpal_update_feed_active', array( __CLASS__, 'update_feed_active' ) );
		}
		if ( get_option( "gf_zarinpal_configured" ) ) {
			add_filter( "gform_disable_post_creation", array( __CLASS__, "delay_posts" ), 10, 3 );
			add_filter( "gform_is_delayed_pre_process_feed", array( __CLASS__, "delay_addons" ), 10, 4 );

			add_filter( "gform_confirmation", array( __CLASS__, "Request" ), 1000, 4 );
			add_action( 'wp', array( __CLASS__, 'Verify' ), 5 );
		}

		add_filter( "gform_logging_supported", array( __CLASS__, "set_logging_supported" ) );

		
		add_filter( 'gf_payment_gateways', array( __CLASS__, 'gravityformszarinpal' ), 2 );
		do_action( 'gravityforms_gateways' );
		do_action( 'gravityforms_zarinpal' );
		
	}


	
	public static function admin_notice_persian_gf() {
		$class   = 'notice notice-error';
		$message = sprintf( __( "برای استفاده از نسخه جدید درگاه های پرداخت گرویتی فرم نصب بسته فارسی ساز نسخه 2.3.1 به بالا الزامی است. برای نصب فارسی ساز %sکلیک کنید%s.", "gravityformszarinpal" ), '<a href="' . admin_url( "plugin-install.php?tab=plugin-information&plugin=persian-gravity-forms&TB_iframe=true&width=772&height=884" ) . '">', '</a>' );
		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
	}

	
	public static function admin_notice_gf_support() {
		$class   = 'notice notice-error';
		$message = sprintf( __( "درگاه زرین پال نیاز به گرویتی فرم نسخه %s به بالا دارد. برای بروز رسانی هسته گرویتی فرم به %sسایت گرویتی فرم فارسی%s مراجعه نمایید .", "gravityformszarinpal" ), self::$min_gravityforms_version, "<a href='http://gravityforms.ir/11378' target='_blank'>", "</a>" );
		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
	}


	public static function gravityformszarinpal( $form, $entry ) {
		$zarinpal = array(
			'class' => ( __CLASS__ . '|' . self::$author ),
			'title' => __( 'زرین پال', 'gravityformszarinpal' ),
			'param' => array(
				'email'  => __( 'ایمیل', 'gravityformszarinpal' ),
				'mobile' => __( 'موبایل', 'gravityformszarinpal' ),
				'desc'   => __( 'توضیحات', 'gravityformszarinpal' )
			)
		);

		return apply_filters( self::$author . '_gf_zarinpal_detail', apply_filters( self::$author . '_gf_gateway_detail', $zarinpal, $form, $entry ), $form, $entry );
	}

	
	public static function add_permissions() {
		global $wp_roles;
		$editable_roles = get_editable_roles();
		foreach ( (array) $editable_roles as $role => $details ) {
			if ( $role == 'administrator' || in_array( 'gravityforms_edit_forms', $details['capabilities'] ) ) {
				$wp_roles->add_cap( $role, 'gravityforms_zarinpal' );
				$wp_roles->add_cap( $role, 'gravityforms_zarinpal_uninstall' );
			}
		}
	}

	
	public static function members_get_capabilities( $caps ) {
		return array_merge( $caps, array( "gravityforms_zarinpal", "gravityforms_zarinpal_uninstall" ) );
	}

	
	private static function setup() {
		if ( get_option( "gf_zarinpal_version" ) != self::$version ) {
			Zarinpal_GF_DB::update_table();
			update_option( "gf_zarinpal_version", self::$version );
		}
	}

	
	public static function tooltips( $tooltips ) {
		$tooltips["gateway_name"] = __( "تذکر مهم : این قسمت برای نمایش به بازدید کننده می باشد و لطفا جهت جلوگیری از مشکل و تداخل آن را فقط یکبار تنظیم نمایید و از تنظیم مکرر آن خود داری نمایید .", "gravityformszarinpal" );

		return $tooltips;
	}

	
	public static function menu( $menus ) {
		$permission = "gravityforms_zarinpal";
		if ( ! empty( $permission ) ) {
			$menus[] = array(
				"name"       => "gf_zarinpal",
				"label"      => __( "زرین پال", "gravityformszarinpal" ),
				"callback"   => array( __CLASS__, "zarinpal_page" ),
				"permission" => $permission
			);
		}

		return $menus;
	}

	
	public static function toolbar( $menu_items ) {
		$menu_items[] = array(
			'name'  => 'zarinpal',
			'label' => __( 'زرین پال', 'gravityformszarinpal' )
		);

		return $menu_items;
	}

	
	private static function is_gravityforms_supported() {
		if ( class_exists( "GFCommon" ) ) {
			$is_correct_version = version_compare( GFCommon::$version, self::$min_gravityforms_version, ">=" );

			return $is_correct_version;
		} else {
			return false;
		}
	}

	
	protected static function has_access( $required_permission = 'gravityforms_zarinpal' ) {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			include( ABSPATH . "wp-includes/pluggable.php" );
		}

		return GFCommon::current_user_can_any( $required_permission );
	}

	
	protected static function get_base_url() {
		return plugins_url( null, __FILE__ );
	}

	
	protected static function get_base_path() {
		$folder = basename( dirname( __FILE__ ) );

		return WP_PLUGIN_DIR . "/" . $folder;
	}

	
	public static function set_logging_supported( $plugins ) {
		$plugins[ basename( dirname( __FILE__ ) ) ] = "ZarinPal";

		return $plugins;
	}

	
	public static function uninstall() {
		if ( ! self::has_access( "gravityforms_zarinpal_uninstall" ) ) {
			die( __( "شما مجوز کافی برای این کار را ندارید . سطح دسترسی شما پایین تر از حد مجاز است . ", "gravityformszarinpal" ) );
		}
		Zarinpal_GF_DB::drop_tables();
		delete_option( "gf_zarinpal_settings" );
		delete_option( "gf_zarinpal_configured" );
		delete_option( "gf_zarinpal_version" );
		$plugin = basename( dirname( __FILE__ ) ) . "/index.php";
		deactivate_plugins( $plugin );
		update_option( 'recently_activated', array( $plugin => time() ) + (array) get_option( 'recently_activated' ) );
	}

	
	private static function is_zarinpal_page() {
		$current_page    = in_array( trim( strtolower( rgget( "page" ) ) ), array( 'gf_zarinpal', 'zarinpal' ) );
		$current_view    = in_array( trim( strtolower( rgget( "view" ) ) ), array( 'gf_zarinpal', 'zarinpal' ) );
		$current_subview = in_array( trim( strtolower( rgget( "subview" ) ) ), array( 'gf_zarinpal', 'zarinpal' ) );

		return $current_page || $current_view || $current_subview;
	}

	
	public static function feed_page() {
		GFFormSettings::page_header(); ?>
        <h3>
			<span><i class="fa fa-credit-card"></i> <?php esc_html_e( 'زرین پال', 'gravityformszarinpal' ) ?>
                <a id="add-new-confirmation" class="add-new-h2"
                   href="<?php echo esc_url( admin_url( 'admin.php?page=gf_zarinpal&view=edit&fid=' . absint( rgget( "id" ) ) ) ) ?>"><?php esc_html_e( 'افزودن فید جدید', 'gravityformszarinpal' ) ?></a></span>
            <a class="add-new-h2"
               href="admin.php?page=gf_zarinpal&view=stats&id=<?php echo absint( rgget( "id" ) ) ?>"><?php _e( "نمودار ها", "gravityformszarinpal" ) ?></a>
        </h3>
		<?php self::list_page( 'per-form' ); ?>
		<?php GFFormSettings::page_footer();
	}

	
	public static function has_zarinpal_condition( $form, $config ) {

		if ( empty( $config['meta'] ) ) {
			return false;
		}

		if ( empty( $config['meta']['zarinpal_conditional_enabled'] ) ) {
			return true;
		}

		if ( ! empty( $config['meta']['zarinpal_conditional_field_id'] ) ) {
			$condition_field_ids = $config['meta']['zarinpal_conditional_field_id'];
			if ( ! is_array( $condition_field_ids ) ) {
				$condition_field_ids = array( '1' => $condition_field_ids );
			}
		} else {
			return true;
		}

		if ( ! empty( $config['meta']['zarinpal_conditional_value'] ) ) {
			$condition_values = $config['meta']['zarinpal_conditional_value'];
			if ( ! is_array( $condition_values ) ) {
				$condition_values = array( '1' => $condition_values );
			}
		} else {
			$condition_values = array( '1' => '' );
		}

		if ( ! empty( $config['meta']['zarinpal_conditional_operator'] ) ) {
			$condition_operators = $config['meta']['zarinpal_conditional_operator'];
			if ( ! is_array( $condition_operators ) ) {
				$condition_operators = array( '1' => $condition_operators );
			}
		} else {
			$condition_operators = array( '1' => 'is' );
		}

		$type = ! empty( $config['meta']['zarinpal_conditional_type'] ) ? strtolower( $config['meta']['zarinpal_conditional_type'] ) : '';
		$type = $type == 'all' ? 'all' : 'any';

		foreach ( $condition_field_ids as $i => $field_id ) {

			if ( empty( $field_id ) ) {
				continue;
			}

			$field = RGFormsModel::get_field( $form, $field_id );
			if ( empty( $field ) ) {
				continue;
			}

			$value    = ! empty( $condition_values[ '' . $i . '' ] ) ? $condition_values[ '' . $i . '' ] : '';
			$operator = ! empty( $condition_operators[ '' . $i . '' ] ) ? $condition_operators[ '' . $i . '' ] : 'is';

			$is_visible     = ! RGFormsModel::is_field_hidden( $form, $field, array() );
			$field_value    = RGFormsModel::get_field_value( $field, array() );
			$is_value_match = RGFormsModel::is_value_match( $field_value, $value, $operator );
			$check          = $is_value_match && $is_visible;

			if ( $type == 'any' && $check ) {
				return true;
			} else if ( $type == 'all' && ! $check ) {
				return false;
			}
		}

		if ( $type == 'any' ) {
			return false;
		} else {
			return true;
		}
	}

	
	public static function get_config_by_entry( $entry ) {
		$feed_id = gform_get_meta( $entry["id"], "zarinpal_feed_id" );
		$feed    = ! empty( $feed_id ) ? Zarinpal_GF_DB::get_feed( $feed_id ) : '';
		$return  = ! empty( $feed ) ? $feed : false;

		return apply_filters( self::$author . '_gf_zarinpal_get_config_by_entry', apply_filters( self::$author . '_gf_gateway_get_config_by_entry', $return, $entry ), $entry );
	}

	
	public static function delay_posts( $is_disabled, $form, $entry ) {

		$config = self::get_active_config( $form );

		if ( ! empty( $config ) && is_array( $config ) && $config ) {
			return true;
		}

		return $is_disabled;
	}

	
	public static function delay_addons( $is_delayed, $form, $entry, $slug ) {

		$config = self::get_active_config( $form );

		if ( ! empty( $config["meta"] ) && is_array( $config["meta"] ) && $config = $config["meta"] ) {

			$user_registration_slug = apply_filters( 'gf_user_registration_slug', 'gravityformsuserregistration' );

			if ( $slug != $user_registration_slug && ! empty( $config["addon"] ) && $config["addon"] == 'true' ) {
				$flag = true;
			} elseif ( $slug == $user_registration_slug && ! empty( $config["type"] ) && $config["type"] == "subscription" ) {
				$flag = true;
			}

			if ( ! empty( $flag ) ) {
				$fulfilled = gform_get_meta( $entry['id'], $slug . '_is_fulfilled' );
				$processed = gform_get_meta( $entry['id'], 'processed_feeds' );

				$is_delayed = empty( $fulfilled ) && rgempty( $slug, $processed );
			}
		}

		return $is_delayed;
	}

	
	private static function redirect_confirmation( $url, $ajax ) {

		if ( headers_sent() || $ajax ) {
			$confirmation = "<script type=\"text/javascript\">" . apply_filters( 'gform_cdata_open', '' ) . " function gformRedirect(){document.location.href='$url';}";
			if ( ! $ajax ) {
				$confirmation .= 'gformRedirect();';
			}
			$confirmation .= apply_filters( 'gform_cdata_close', '' ) . '</script>';
		} else {
			$confirmation = array( 'redirect' => $url );
		}

		return $confirmation;
	}

	
	public static function get_active_config( $form ) {

		if ( ! empty( self::$config ) ) {
			return self::$config;
		}

		$configs = Zarinpal_GF_DB::get_feed_by_form( $form["id"], true );

		$configs = apply_filters( self::$author . '_gf_zarinpal_get_active_configs', apply_filters( self::$author . '_gf_gateway_get_active_configs', $configs, $form ), $form );

		$return = false;

		if ( ! empty( $configs ) && is_array( $configs ) ) {

			foreach ( $configs as $config ) {
				if ( self::has_zarinpal_condition( $form, $config ) ) {
					$return = $config;
				}
				break;
			}
		}

		self::$config = apply_filters( self::$author . '_gf_zarinpal_get_active_config', apply_filters( self::$author . '_gf_gateway_get_active_config', $return, $form ), $form );

		return self::$config;
	}

	
	public static function zarinpal_page() {
		$view = rgget( "view" );
		if ( $view == "edit" ) {
			self::config_page();
		} else if ( $view == "stats" ) {
			Zarinpal_GF_Chart::stats_page();
		} else {
			self::list_page( '' );
		}
	}

	
	private static function list_page( $arg ) {

		if ( ! self::is_gravityforms_supported() ) {
			die( sprintf( __( "درگاه زرین پال نیاز به گرویتی فرم نسخه %s دارد. برای بروز رسانی هسته گرویتی فرم به %sسایت گرویتی فرم فارسی%s مراجعه نمایید .", "gravityformszarinpal" ), self::$min_gravityforms_version, "<a href='http://gravityforms.ir/11378' target='_blank'>", "</a>" ) );
		}

		if ( rgpost( 'action' ) == "delete" ) {
			check_admin_referer( "list_action", "gf_zarinpal_list" );
			$id = absint( rgpost( "action_argument" ) );
			Zarinpal_GF_DB::delete_feed( $id );
			?>
            <div class="updated fade" style="padding:6px"><?php _e( "فید حذف شد", "gravityformszarinpal" ) ?></div><?php
		} else if ( ! empty( $_POST["bulk_action"] ) ) {

			check_admin_referer( "list_action", "gf_zarinpal_list" );
			$selected_feeds = rgpost( "feed" );
			if ( is_array( $selected_feeds ) ) {
				foreach ( $selected_feeds as $feed_id ) {
					Zarinpal_GF_DB::delete_feed( $feed_id );
				}
			}

			?>
            <div class="updated fade" style="padding:6px"><?php _e( "فید ها حذف شدند", "gravityformszarinpal" ) ?></div>
			<?php
		}
		?>
        <div class="wrap">

			<?php if ( $arg != 'per-form' ) { ?>

                <h2>
					<?php _e( "فرم های زرین پال", "gravityformszarinpal" );
					if ( get_option( "gf_zarinpal_configured" ) ) { ?>
                        <a class="add-new-h2"
                           href="admin.php?page=gf_zarinpal&view=edit"><?php _e( "افزودن جدید", "gravityformszarinpal" ) ?></a>
						<?php
					} ?>
                </h2>

			<?php } ?>

            <form id="confirmation_list_form" method="post">
				<?php wp_nonce_field( 'list_action', 'gf_zarinpal_list' ) ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>
                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden"
                               for="bulk_action"><?php _e( "اقدام دسته جمعی", "gravityformszarinpal" ) ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e( "اقدامات دسته جمعی", "gravityformszarinpal" ) ?> </option>
                            <option value='delete'><?php _e( "حذف", "gravityformszarinpal" ) ?></option>
                        </select>
						<?php
						echo '<input type="submit" class="button" value="' . __( "اعمال", "gravityformszarinpal" ) . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __( "فید حذف شود ؟ ", "gravityformszarinpal" ) . __( "\'Cancel\' برای منصرف شدن, \'OK\' برای حذف کردن", "gravityformszarinpal" ) . '\')) { return false; } return true;"/>';
						?>
                        <a class="button button-primary"
                           href="admin.php?page=gf_settings&subview=gf_zarinpal"><?php _e( 'تنظیمات حساب زرین پال', 'gravityformszarinpal' ) ?></a>
                    </div>
                </div>
                <table class="wp-list-table widefat fixed striped toplevel_page_gf_edit_forms" cellspacing="0">
                    <thead>
                    <tr>
                        <th scope="col" id="cb" class="manage-column column-cb check-column"
                            style="padding:13px 3px;width:30px"><input type="checkbox"/></th>
                        <th scope="col" id="active" class="manage-column"
                            style="width:<?php echo $arg != 'per-form' ? '50px' : '20px' ?>"><?php echo $arg != 'per-form' ? __( 'وضعیت', 'gravityformszarinpal' ) : '' ?></th>
                        <th scope="col" class="manage-column"
                            style="width:<?php echo $arg != 'per-form' ? '65px' : '30%' ?>"><?php _e( " آیدی فید", "gravityformszarinpal" ) ?></th>
						<?php if ( $arg != 'per-form' ) { ?>
                            <th scope="col"
                                class="manage-column"><?php _e( "فرم متصل به درگاه", "gravityformszarinpal" ) ?></th>
						<?php } ?>
                        <th scope="col" class="manage-column"><?php _e( "نوع تراکنش", "gravityformszarinpal" ) ?></th>
                    </tr>
                    </thead>
                    <tfoot>
                    <tr>
                        <th scope="col" id="cb" class="manage-column column-cb check-column" style="padding:13px 3px;">
                            <input type="checkbox"/></th>
                        <th scope="col" id="active"
                            class="manage-column"><?php echo $arg != 'per-form' ? __( 'وضعیت', 'gravityformszarinpal' ) : '' ?></th>
                        <th scope="col" class="manage-column"><?php _e( "آیدی فید", "gravityformszarinpal" ) ?></th>
						<?php if ( $arg != 'per-form' ) { ?>
                            <th scope="col"
                                class="manage-column"><?php _e( "فرم متصل به درگاه", "gravityformszarinpal" ) ?></th>
						<?php } ?>
                        <th scope="col" class="manage-column"><?php _e( "نوع تراکنش", "gravityformszarinpal" ) ?></th>
                    </tr>
                    </tfoot>
                    <tbody class="list:user user-list">
					<?php
					if ( $arg != 'per-form' ) {
						$settings = Zarinpal_GF_DB::get_feeds();
					} else {
						$settings = Zarinpal_GF_DB::get_feed_by_form( rgget( 'id' ), false );
					}

					if ( ! get_option( "gf_zarinpal_configured" ) ) {
						?>
                        <tr>
                            <td colspan="5" style="padding:20px;">
								<?php echo sprintf( __( "برای شروع باید درگاه را فعال نمایید . به %sتنظیمات زرین پال%s بروید . ", "gravityformszarinpal" ), '<a href="admin.php?page=gf_settings&subview=gf_zarinpal">', "</a>" ); ?>
                            </td>
                        </tr>
						<?php
					} else if ( is_array( $settings ) && sizeof( $settings ) > 0 ) {
						foreach ( $settings as $setting ) {
							?>
                            <tr class='author-self status-inherit' valign="top">

                                <th scope="row" class="check-column"><input type="checkbox" name="feed[]"
                                                                            value="<?php echo $setting["id"] ?>"/></th>

                                <td><img style="cursor:pointer;width:25px"
                                         src="<?php echo esc_url( GFCommon::get_base_url() ) ?>/images/active<?php echo intval( $setting["is_active"] ) ?>.png"
                                         alt="<?php echo $setting["is_active"] ? __( "درگاه فعال است", "gravityformszarinpal" ) : __( "درگاه غیر فعال است", "gravityformszarinpal" ); ?>"
                                         title="<?php echo $setting["is_active"] ? __( "درگاه فعال است", "gravityformszarinpal" ) : __( "درگاه غیر فعال است", "gravityformszarinpal" ); ?>"
                                         onclick="ToggleActive(this, <?php echo $setting['id'] ?>); "/></td>

                                <td><?php echo $setting["id"] ?>
									<?php if ( $arg == 'per-form' ) { ?>
                                        <div class="row-actions">
                                                <span class="edit">
                                                    <a title="<?php _e( "ویرایش فید", "gravityformszarinpal" ) ?>"
                                                       href="admin.php?page=gf_zarinpal&view=edit&id=<?php echo $setting["id"] ?>"><?php _e( "ویرایش فید", "gravityformszarinpal" ) ?></a>
                                                    |
                                                </span>
                                            <span class="trash">
                                                    <a title="<?php _e( "حذف", "gravityformszarinpal" ) ?>"
                                                       href="javascript: if(confirm('<?php _e( "فید حذف شود؟ ", "gravityformszarinpal" ) ?> <?php _e( "\'Cancel\' برای انصراف, \'OK\' برای حذف کردن.", "gravityformszarinpal" ) ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e( "حذف", "gravityformszarinpal" ) ?></a>
                                                </span>
                                        </div>
									<?php } ?>
                                </td>

								<?php if ( $arg != 'per-form' ) { ?>
                                    <td class="column-title">
                                        <strong><a class="row-title"
                                                   href="admin.php?page=gf_zarinpal&view=edit&id=<?php echo $setting["id"] ?>"
                                                   title="<?php _e( "تنظیم مجدد درگاه", "gravityformszarinpal" ) ?>"><?php echo $setting["form_title"] ?></a></strong>

                                        <div class="row-actions">
                                            <span class="edit">
                                                <a title="<?php _e( "ویرایش فید", "gravityformszarinpal" ) ?>"
                                                   href="admin.php?page=gf_zarinpal&view=edit&id=<?php echo $setting["id"] ?>"><?php _e( "ویرایش فید", "gravityformszarinpal" ) ?></a>
                                                |
                                            </span>
                                            <span class="trash">
                                                <a title="<?php _e( "حذف فید", "gravityformszarinpal" ) ?>"
                                                   href="javascript: if(confirm('<?php _e( "فید حذف شود؟ ", "gravityformszarinpal" ) ?> <?php _e( "\'Cancel\' برای انصراف, \'OK\' برای حذف کردن.", "gravityformszarinpal" ) ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e( "حذف", "gravityformszarinpal" ) ?></a>
                                                |
                                            </span>
                                            <span class="view">
                                                <a title="<?php _e( "ویرایش فرم", "gravityformszarinpal" ) ?>"
                                                   href="admin.php?page=gf_edit_forms&id=<?php echo $setting["form_id"] ?>"><?php _e( "ویرایش فرم", "gravityformszarinpal" ) ?></a>
                                                |
                                            </span>
                                            <span class="view">
                                                <a title="<?php _e( "مشاهده صندوق ورودی", "gravityformszarinpal" ) ?>"
                                                   href="admin.php?page=gf_entries&view=entries&id=<?php echo $setting["form_id"] ?>"><?php _e( "صندوق ورودی", "gravityformszarinpal" ) ?></a>
                                                |
                                            </span>
                                            <span class="view">
                                                <a title="<?php _e( "نمودارهای فرم", "gravityformszarinpal" ) ?>"
                                                   href="admin.php?page=gf_zarinpal&view=stats&id=<?php echo $setting["form_id"] ?>"><?php _e( "نمودارهای فرم", "gravityformszarinpal" ) ?></a>
                                            </span>
                                        </div>
                                    </td>
								<?php } ?>


                                <td class="column-date">
									<?php
									if ( isset( $setting["meta"]["type"] ) && $setting["meta"]["type"] == 'subscription' ) {
										_e( "عضویت", "gravityformszarinpal" );
									} else {
										_e( "محصول معمولی یا فرم ارسال پست", "gravityformszarinpal" );
									}
									?>
                                </td>
                            </tr>
							<?php
						}
					} else {
						?>
                        <tr>
                            <td colspan="5" style="padding:20px;">
								<?php
								if ( $arg == 'per-form' ) {
									echo sprintf( __( "شما هیچ فید زرین پالی ندارید . %sیکی بسازید%s .", "gravityformszarinpal" ), '<a href="admin.php?page=gf_zarinpal&view=edit&fid=' . absint( rgget( "id" ) ) . '">', "</a>" );
								} else {
									echo sprintf( __( "شما هیچ فید زرین پالی ندارید . %sیکی بسازید%s .", "gravityformszarinpal" ), '<a href="admin.php?page=gf_zarinpal&view=edit">', "</a>" );
								}
								?>
                            </td>
                        </tr>
						<?php
					}
					?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id) {
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#confirmation_list_form")[0].submit();
            }

            function ToggleActive(img, feed_id) {
                var is_active = img.src.indexOf("active1.png") >= 0;
                if (is_active) {
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title', '<?php _e( "درگاه غیر فعال است", "gravityformszarinpal" ) ?>').attr('alt', '<?php _e( "درگاه غیر فعال است", "gravityformszarinpal" ) ?>');
                }
                else {
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title', '<?php _e( "درگاه فعال است", "gravityformszarinpal" ) ?>').attr('alt', '<?php _e( "درگاه فعال است", "gravityformszarinpal" ) ?>');
                }
                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar("action", "gf_zarinpal_update_feed_active");
                mysack.setVar("gf_zarinpal_update_feed_active", "<?php echo wp_create_nonce( "gf_zarinpal_update_feed_active" ) ?>");
                mysack.setVar("feed_id", feed_id);
                mysack.setVar("is_active", is_active ? 0 : 1);
                mysack.onError = function () {
                    alert('<?php _e( "خطای Ajax رخ داده است", "gravityformszarinpal" ) ?>')
                };
                mysack.runAJAX();
                return true;
            }
        </script>
		<?php
	}

	
	public static function update_feed_active() {
		check_ajax_referer( 'gf_zarinpal_update_feed_active', 'gf_zarinpal_update_feed_active' );
		$id   = absint( rgpost( 'feed_id' ) );
		$feed = Zarinpal_GF_DB::get_feed( $id );
		Zarinpal_GF_DB::update_feed( $id, $feed["form_id"], $_POST["is_active"], $feed["meta"] );
	}

	
	private static function Return_URL( $form_id, $entry_id ) {

		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		if ( $_SERVER['SERVER_PORT'] != '80' ) {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}

		$arr_params = array( 'id', 'entry', 'no', 'Authority', 'Status' );
		$pageURL    = esc_url( remove_query_arg( $arr_params, $pageURL ) );

		$pageURL = str_replace( '#038;', '&', add_query_arg( array(
			'id'    => $form_id,
			'entry' => $entry_id
		), $pageURL ) );

		return apply_filters( self::$author . '_zarinpal_return_url', apply_filters( self::$author . '_gateway_return_url', $pageURL, $form_id, $entry_id, __CLASS__ ), $form_id, $entry_id, __CLASS__ );
	}

	
	public static function get_order_total( $form, $entry ) {

		$total = GFCommon::get_order_total( $form, $entry );
		$total = ( ! empty( $total ) && $total > 0 ) ? $total : 0;

		return apply_filters( self::$author . '_zarinpal_get_order_total', apply_filters( self::$author . '_gateway_get_order_total', $total, $form, $entry ), $form, $entry );
	}

	
	private static function get_mapped_field_list( $field_name, $selected_field, $fields ) {
		$str = "<select name='$field_name' id='$field_name'><option value=''></option>";
		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				$field_id    = $field[0];
				$field_label = esc_html( GFCommon::truncate_middle( $field[1], 40 ) );
				$selected    = $field_id == $selected_field ? "selected='selected'" : "";
				$str         .= "<option value='" . $field_id . "' " . $selected . ">" . $field_label . "</option>";
			}
		}
		$str .= "</select>";

		return $str;
	}

	
	private static function get_form_fields( $form ) {
		$fields = array();
		if ( is_array( $form["fields"] ) ) {
			foreach ( $form["fields"] as $field ) {
				if ( isset( $field["inputs"] ) && is_array( $field["inputs"] ) ) {
					foreach ( $field["inputs"] as $input ) {
						$fields[] = array( $input["id"], GFCommon::get_label( $field, $input["id"] ) );
					}
				} else if ( ! rgar( $field, 'displayOnly' ) ) {
					$fields[] = array( $field["id"], GFCommon::get_label( $field ) );
				}
			}
		}

		return $fields;
	}

	private static function get_customer_information_desc( $form, $config = null ) {
		$form_fields    = self::get_form_fields( $form );
		$selected_field = ! empty( $config["meta"]["customer_fields_desc"] ) ? $config["meta"]["customer_fields_desc"] : '';

		return self::get_mapped_field_list( 'zarinpal_customer_field_desc', $selected_field, $form_fields );
	}

	private static function get_customer_information_email( $form, $config = null ) {
		$form_fields    = self::get_form_fields( $form );
		$selected_field = ! empty( $config["meta"]["customer_fields_email"] ) ? $config["meta"]["customer_fields_email"] : '';

		return self::get_mapped_field_list( 'zarinpal_customer_field_email', $selected_field, $form_fields );
	}

	private static function get_customer_information_mobile( $form, $config = null ) {
		$form_fields    = self::get_form_fields( $form );
		$selected_field = ! empty( $config["meta"]["customer_fields_mobile"] ) ? $config["meta"]["customer_fields_mobile"] : '';

		return self::get_mapped_field_list( 'zarinpal_customer_field_mobile', $selected_field, $form_fields );
	}
	


	
	public static function payment_entry_detail( $form_id, $entry ) {

		$payment_gateway = rgar( $entry, "payment_method" );

		if ( ! empty( $payment_gateway ) && $payment_gateway == "zarinpal" ) {

			do_action( 'gf_gateway_entry_detail' );

			?>
            <hr/>
            <strong>
				<?php _e( 'اطلاعات تراکنش :', 'gravityformszarinpal' ) ?>
            </strong>
            <br/>
            <br/>
			<?php

			$transaction_type = rgar( $entry, "transaction_type" );
			$payment_status   = rgar( $entry, "payment_status" );
			$payment_amount   = rgar( $entry, "payment_amount" );

			if ( empty( $payment_amount ) ) {
				$form           = RGFormsModel::get_form_meta( $form_id );
				$payment_amount = self::get_order_total( $form, $entry );
			}

			$transaction_id = rgar( $entry, "transaction_id" );
			$payment_date   = rgar( $entry, "payment_date" );

			$date = new DateTime( $payment_date );
			$tzb  = get_option( 'gmt_offset' );
			$tzn  = abs( $tzb ) * 3600;
			$tzh  = intval( gmdate( "H", $tzn ) );
			$tzm  = intval( gmdate( "i", $tzn ) );

			if ( intval( $tzb ) < 0 ) {
				$date->sub( new DateInterval( 'P0DT' . $tzh . 'H' . $tzm . 'M' ) );
			} else {
				$date->add( new DateInterval( 'P0DT' . $tzh . 'H' . $tzm . 'M' ) );
			}

			$payment_date = $date->format( 'Y-m-d H:i:s' );
			$payment_date = GF_jdate( 'Y-m-d H:i:s', strtotime( $payment_date ), '', date_default_timezone_get(), 'en' );

			if ( $payment_status == 'Paid' ) {
				$payment_status_persian = __( 'موفق', 'gravityformszarinpal' );
			}

			if ( $payment_status == 'Active' ) {
				$payment_status_persian = __( 'موفق', 'gravityformszarinpal' );
			}

			if ( $payment_status == 'Cancelled' ) {
				$payment_status_persian = __( 'منصرف شده', 'gravityformszarinpal' );
			}

			if ( $payment_status == 'Failed' ) {
				$payment_status_persian = __( 'ناموفق', 'gravityformszarinpal' );
			}

			if ( $payment_status == 'Processing' ) {
				$payment_status_persian = __( 'معلق', 'gravityformszarinpal' );
			}

			if ( ! strtolower( rgpost( "save" ) ) || RGForms::post( "screen_mode" ) != "edit" ) {
				echo __( 'وضعیت پرداخت : ', 'gravityformszarinpal' ) . $payment_status_persian . '<br/><br/>';
				echo __( 'تاریخ پرداخت : ', 'gravityformszarinpal' ) . '<span style="">' . $payment_date . '</span><br/><br/>';
				echo __( 'مبلغ پرداختی : ', 'gravityformszarinpal' ) . GFCommon::to_money( $payment_amount, rgar( $entry, "currency" ) ) . '<br/><br/>';
				echo __( 'کد رهگیری : ', 'gravityformszarinpal' ) . $transaction_id . '<br/><br/>';
				echo __( 'درگاه پرداخت : زرین پال', 'gravityformszarinpal' );
			} else {
				$payment_string = '';
				$payment_string .= '<select id="payment_status" name="payment_status">';
				$payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status_persian . '</option>';

				if ( $transaction_type == 1 ) {
					if ( $payment_status != "Paid" ) {
						$payment_string .= '<option value="Paid">' . __( 'موفق', 'gravityformszarinpal' ) . '</option>';
					}
				}

				if ( $transaction_type == 2 ) {
					if ( $payment_status != "Active" ) {
						$payment_string .= '<option value="Active">' . __( 'موفق', 'gravityformszarinpal' ) . '</option>';
					}
				}

				if ( ! $transaction_type ) {

					if ( $payment_status != "Paid" ) {
						$payment_string .= '<option value="Paid">' . __( 'موفق', 'gravityformszarinpal' ) . '</option>';
					}

					if ( $payment_status != "Active" ) {
						$payment_string .= '<option value="Active">' . __( 'موفق', 'gravityformszarinpal' ) . '</option>';
					}
				}

				if ( $payment_status != "Failed" ) {
					$payment_string .= '<option value="Failed">' . __( 'ناموفق', 'gravityformszarinpal' ) . '</option>';
				}

				if ( $payment_status != "Cancelled" ) {
					$payment_string .= '<option value="Cancelled">' . __( 'منصرف شده', 'gravityformszarinpal' ) . '</option>';
				}

				if ( $payment_status != "Processing" ) {
					$payment_string .= '<option value="Processing">' . __( 'معلق', 'gravityformszarinpal' ) . '</option>';
				}

				$payment_string .= '</select>';

				echo __( 'وضعیت پرداخت :', 'gravityformszarinpal' ) . $payment_string . '<br/><br/>';
				?>
                <div id="edit_payment_status_details" style="display:block">
                    <table>
                        <tr>
                            <td><?php _e( 'تاریخ پرداخت :', 'gravityformszarinpal' ) ?></td>
                            <td><input type="text" id="payment_date" name="payment_date"
                                       value="<?php echo $payment_date ?>"></td>
                        </tr>
                        <tr>
                            <td><?php _e( 'مبلغ پرداخت :', 'gravityformszarinpal' ) ?></td>
                            <td><input type="text" id="payment_amount" name="payment_amount"
                                       value="<?php echo $payment_amount ?>"></td>
                        </tr>
                        <tr>
                            <td><?php _e( 'شماره تراکنش :', 'gravityformszarinpal' ) ?></td>
                            <td><input type="text" id="zarinpal_transaction_id" name="zarinpal_transaction_id"
                                       value="<?php echo $transaction_id ?>"></td>
                        </tr>

                    </table>
                    <br/>
                </div>
				<?php
				echo __( 'درگاه پرداخت : زرین پال (غیر قابل ویرایش)', 'gravityformszarinpal' );
			}

			echo '<br/>';
		}
	}

	
	public static function update_payment_entry( $form, $entry_id ) {

		check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' );

		do_action( 'gf_gateway_update_entry' );

		$entry = GFPersian_Payments::get_entry( $entry_id );

		$payment_gateway = rgar( $entry, "payment_method" );

		if ( empty( $payment_gateway ) ) {
			return;
		}

		if ( $payment_gateway != "zarinpal" ) {
			return;
		}

		$payment_status = rgpost( "payment_status" );
		if ( empty( $payment_status ) ) {
			$payment_status = rgar( $entry, "payment_status" );
		}

		$payment_amount       = rgpost( "payment_amount" );
		$payment_transaction  = rgpost( "zarinpal_transaction_id" );
		$payment_date_Checker = $payment_date = rgpost( "payment_date" );

		list( $date, $time ) = explode( " ", $payment_date );
		list( $Y, $m, $d ) = explode( "-", $date );
		list( $H, $i, $s ) = explode( ":", $time );
		$miladi = GF_jalali_to_gregorian( $Y, $m, $d );

		$date         = new DateTime( "$miladi[0]-$miladi[1]-$miladi[2] $H:$i:$s" );
		$payment_date = $date->format( 'Y-m-d H:i:s' );

		if ( empty( $payment_date_Checker ) ) {
			if ( ! empty( $entry["payment_date"] ) ) {
				$payment_date = $entry["payment_date"];
			} else {
				$payment_date = rgar( $entry, "date_created" );
			}
		} else {
			$payment_date = date( "Y-m-d H:i:s", strtotime( $payment_date ) );
			$date         = new DateTime( $payment_date );
			$tzb          = get_option( 'gmt_offset' );
			$tzn          = abs( $tzb ) * 3600;
			$tzh          = intval( gmdate( "H", $tzn ) );
			$tzm          = intval( gmdate( "i", $tzn ) );
			if ( intval( $tzb ) < 0 ) {
				$date->add( new DateInterval( 'P0DT' . $tzh . 'H' . $tzm . 'M' ) );
			} else {
				$date->sub( new DateInterval( 'P0DT' . $tzh . 'H' . $tzm . 'M' ) );
			}
			$payment_date = $date->format( 'Y-m-d H:i:s' );
		}

		global $current_user;
		$user_id   = 0;
		$user_name = __( "مهمان", 'gravityformszarinpal' );
		if ( $current_user && $user_data = get_userdata( $current_user->ID ) ) {
			$user_id   = $current_user->ID;
			$user_name = $user_data->display_name;
		}

		$entry["payment_status"] = $payment_status;
		$entry["payment_amount"] = $payment_amount;
		$entry["payment_date"]   = $payment_date;
		$entry["transaction_id"] = $payment_transaction;
		if ( $payment_status == 'Paid' || $payment_status == 'Active' ) {
			$entry["is_fulfilled"] = 1;
		} else {
			$entry["is_fulfilled"] = 0;
		}
		GFAPI::update_entry( $entry );

		$new_status = '';
		switch ( rgar( $entry, "payment_status" ) ) {
			case "Active" :
				$new_status = __( 'موفق', 'gravityformszarinpal' );
				break;

			case "Paid" :
				$new_status = __( 'موفق', 'gravityformszarinpal' );
				break;

			case "Cancelled" :
				$new_status = __( 'منصرف شده', 'gravityformszarinpal' );
				break;

			case "Failed" :
				$new_status = __( 'ناموفق', 'gravityformszarinpal' );
				break;

			case "Processing" :
				$new_status = __( 'معلق', 'gravityformszarinpal' );
				break;
		}

		RGFormsModel::add_note( $entry["id"], $user_id, $user_name, sprintf( __( "اطلاعات تراکنش به صورت دستی ویرایش شد . وضعیت : %s - مبلغ : %s - کد رهگیری : %s - تاریخ : %s", "gravityformszarinpal" ), $new_status, GFCommon::to_money( $entry["payment_amount"], $entry["currency"] ), $payment_transaction, $entry["payment_date"] ) );

	}

	
	public static function settings_page() {

		if ( rgpost( "uninstall" ) ) {
			check_admin_referer( "uninstall", "gf_zarinpal_uninstall" );
			self::uninstall();
			echo '<div class="updated fade" style="padding:20px;">' . __( "درگاه با موفقیت غیرفعال شد و اطلاعات مربوط به آن نیز از بین رفت برای فعالسازی مجدد میتوانید از طریق افزونه های وردپرس اقدام نمایید .", "gravityformszarinpal" ) . '</div>';

			return;
		} else if ( isset( $_POST["gf_zarinpal_submit"] ) ) {

			check_admin_referer( "update", "gf_zarinpal_update" );
			$settings = array(
				"merchent" => rgpost( 'gf_zarinpal_merchent' ),
				"currency"   => rgpost( 'gf_zarinpal_currency' ),
				"gname"    => rgpost( 'gf_zarinpal_gname' ),
			);
			update_option( "gf_zarinpal_settings", array_map( 'sanitize_text_field', $settings ) );
			if ( isset( $_POST["gf_zarinpal_configured"] ) ) {
				update_option( "gf_zarinpal_configured", sanitize_text_field( $_POST["gf_zarinpal_configured"] ) );
			} else {
				delete_option( "gf_zarinpal_configured" );
			}
		} else {
			$settings = get_option( "gf_zarinpal_settings" );
		}

		if ( ! empty( $_POST ) ) {

			if ( isset( $_POST["gf_zarinpal_configured"] ) && ( $Response = self::Request( 'valid_checker', '', '', '' ) ) && $Response != false ) {

				if ( $Response === true ) {
					echo '<div class="updated fade" style="padding:6px">' . __( "ارتباط با درگاه برقرار شد و اطلاعات وارد شده صحیح است .", "gravityformszarinpal" ) . '</div>';
				} else if ( $Response == 'sandbox' ) {
					echo '<div class="updated fade" style="padding:6px">' . __( "در حالت تستی نیاز به ورود اطلاعات صحیح نمی باشد .", "gravityformszarinpal" ) . '</div>';
				} else {
					echo '<div class="error fade" style="padding:6px">' . $Response . '</div>';
				}

			} else {
				echo '<div class="updated fade" style="padding:6px">' . __( "تنظیمات ذخیره شدند .", "gravityformszarinpal" ) . '</div>';
			}
		} else if ( isset( $_GET['subview'] ) && $_GET['subview'] == 'gf_zarinpal' && isset( $_GET['updated'] ) ) {
			echo '<div class="updated fade" style="padding:6px">' . __( "تنظیمات ذخیره شدند .", "gravityformszarinpal" ) . '</div>';
		}
		?>

        <form action="" method="post">

			<?php wp_nonce_field( "update", "gf_zarinpal_update" ) ?>

            <h3>
				<span>
				<i class="fa fa-credit-card"></i>
					<?php _e( "تنظیمات زرین پال", "gravityformszarinpal" ) ?>
				</span>
            </h3>

            <table class="form-table">

                <tr>
                    <th scope="row"><label
                                for="gf_zarinpal_configured"><?php _e( "فعالسازی", "gravityformszarinpal" ); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" name="gf_zarinpal_configured"
                               id="gf_zarinpal_configured" <?php echo get_option( "gf_zarinpal_configured" ) ? "checked='checked'" : "" ?>/>
                        <label class="inline"
                               for="gf_zarinpal_configured"><?php _e( "بله", "gravityformszarinpal" ); ?></label>
                    </td>
                </tr>


                <tr>
                    <th scope="row"><label
                                for="gf_zarinpal_currency"><?php _e( "ارز درگاه", "gravityformszarinpal" ); ?></label>
                    </th>
                    <td>

                        <input type="radio" name="gf_zarinpal_currency" required
                               value="IRT" <?php echo rgar( $settings, 'currency' ) == "IRT" ? "checked='checked'" : "" ?>/>
						<?php _e( "تومان", "gravityformszarinpal" ); ?>

                        <input type="radio" name="gf_zarinpal_currency" required
                               value="IRR" <?php echo rgar( $settings, 'currency' ) == "IRR" ? "checked='checked'" : "" ?>/>
						<?php _e( "ریال", "gravityformszarinpal" ); ?>

                    </td>
                </tr>

                <tr>
                    <th scope="row"><label
                                for="gf_zarinpal_merchent"><?php _e( "مرچنت", "gravityformszarinpal" ); ?></label></th>
                    <td>
                        <input style="width:350px;text-align:left;direction:ltr !important" type="text"
                               id="gf_zarinpal_merchent" name="gf_zarinpal_merchent"
                               value="<?php echo sanitize_text_field( rgar( $settings, 'merchent' ) ) ?>"/>
                    </td>
                </tr>

				<?php

				$gateway_title = __( "زرین پال", "gravityformszarinpal" );

				if ( sanitize_text_field( rgar( $settings, 'gname' ) ) ) {
					$gateway_title = sanitize_text_field( $settings["gname"] );
				}

				?>
                <tr>
                    <th scope="row">
                        <label for="gf_zarinpal_gname">
							<?php _e( "عنوان", "gravityformszarinpal" ); ?>
							<?php gform_tooltip( 'gateway_name' ) ?>
                        </label>
                    </th>
                    <td>
                        <input style="width:350px;" type="text" id="gf_zarinpal_gname" name="gf_zarinpal_gname"
                               value="<?php echo $gateway_title; ?>"/>
                    </td>
                </tr>

                <tr>
                    <td colspan="2"><input style="font-family:tahoma !important;" type="submit"
                                           name="gf_zarinpal_submit" class="button-primary"
                                           value="<?php _e( "ذخیره تنظیمات", "gravityformszarinpal" ) ?>"/></td>
                </tr>

            </table>

        </form>

        <form action="" method="post">
			<?php

			wp_nonce_field( "uninstall", "gf_zarinpal_uninstall" );

			if ( self::has_access( "gravityforms_zarinpal_uninstall" ) ) {

				?>
                <div class="hr-divider"></div>
                <div class="delete-alert alert_red">

                    <h3>
                        <i class="fa fa-exclamation-triangle gf_invalid"></i>
						<?php _e( "غیر فعالسازی افزونه دروازه پرداخت زرین پال", "gravityformszarinpal" ); ?>
                    </h3>

                    <div
                            class="gf_delete_notice"><?php _e( "تذکر : بعد از غیرفعالسازی تمامی اطلاعات مربوط به زرین پال حذف خواهد شد", "gravityformszarinpal" ) ?></div>

					<?php
					$uninstall_button = '<input  style="font-family:tahoma !important;" type="submit" name="uninstall" value="' . __( "غیر فعال سازی درگاه زرین پال", "gravityformszarinpal" ) . '" class="button" onclick="return confirm(\'' . __( "تذکر : بعد از غیرفعالسازی تمامی اطلاعات مربوط به زرین پال حذف خواهد شد . آیا همچنان مایل به غیر فعالسازی میباشید؟", "gravityformszarinpal" ) . '\');"/>';
					echo apply_filters( "gform_zarinpal_uninstall_button", $uninstall_button );
					?>

                </div>

			<?php } ?>
        </form>
		<?php
	}


	
	public static function get_gname() {
		$settings = get_option( "gf_zarinpal_settings" );
		if ( isset( $settings["gname"] ) ) {
			$gname = $settings["gname"];
		} else {
			$gname = __( 'زرین پال', 'gravityformszarinpal' );
		}

		return $gname;
	}

	
	private static function get_merchent() {
		$settings = get_option( "gf_zarinpal_settings" );
		$merchent = isset( $settings["merchent"] ) ? $settings["merchent"] : '';

		return trim( $merchent );
	}

	
	private static function get_currency() {
		$settings = get_option( "gf_zarinpal_settings" );
		$server   = isset( $settings["currency"] ) ? $settings["currency"] : '';

		return $server;
	}


	private static function config_page() {

		wp_register_style( 'gform_admin_zarinpal', GFCommon::get_base_url() . '/css/admin.css' );
		wp_print_styles( array( 'jquery-ui-styles', 'gform_admin_zarinpal', 'wp-pointer' ) ); ?>

		<?php if ( is_rtl() ) { ?>
            <style type="text/css">
                table.gforms_form_settings th {
                    text-align: right !important;
                }
            </style>
		<?php } ?>

        <div class="wrap gforms_edit_form gf_browser_gecko">

			<?php
			$id        = ! rgempty( "zarinpal_setting_id" ) ? rgpost( "zarinpal_setting_id" ) : absint( rgget( "id" ) );
			$config    = empty( $id ) ? array(
				"meta"      => array(),
				"is_active" => true
			) : Zarinpal_GF_DB::get_feed( $id );
			$get_feeds = Zarinpal_GF_DB::get_feeds();
			$form_name = '';


			$_get_form_id = rgget( 'fid' ) ? rgget( 'fid' ) : ( ! empty( $config["form_id"] ) ? $config["form_id"] : '' );

			foreach ( (array) $get_feeds as $get_feed ) {
				if ( $get_feed['id'] == $id ) {
					$form_name = $get_feed['form_title'];
				}
			}
			?>


            <h2 class="gf_admin_page_title"><?php _e( "پیکربندی درگاه زرین پال", "gravityformszarinpal" ) ?>

				<?php if ( ! empty( $_get_form_id ) ) { ?>
                    <span class="gf_admin_page_subtitle">
					<span
                            class="gf_admin_page_formid"><?php echo sprintf( __( "فید: %s", "gravityformszarinpal" ), $id ) ?></span>
					<span
                            class="gf_admin_page_formname"><?php echo sprintf( __( "فرم: %s", "gravityformszarinpal" ), $form_name ) ?></span>
				</span>
				<?php } ?>

            </h2>
            <a class="button add-new-h2" href="admin.php?page=gf_settings&subview=gf_zarinpal"
               style="margin:8px 9px;"><?php _e( "تنظیمات حساب زرین پال", "gravityformszarinpal" ) ?></a>

			<?php
			if ( ! rgempty( "gf_zarinpal_submit" ) ) {
				// ------------------
				check_admin_referer( "update", "gf_zarinpal_feed" );

				$config["form_id"]                     = absint( rgpost( "gf_zarinpal_form" ) );
				$config["meta"]["type"]                = rgpost( "gf_zarinpal_type" );
				$config["meta"]["addon"]               = rgpost( "gf_zarinpal_addon" );
				$config["meta"]["update_post_action1"] = rgpost( 'gf_zarinpal_update_action1' );
				$config["meta"]["update_post_action2"] = rgpost( 'gf_zarinpal_update_action2' );

				// ------------------
				$config["meta"]["zarinpal_conditional_enabled"]  = rgpost( 'gf_zarinpal_conditional_enabled' );
				$config["meta"]["zarinpal_conditional_field_id"] = rgpost( 'gf_zarinpal_conditional_field_id' );
				$config["meta"]["zarinpal_conditional_operator"] = rgpost( 'gf_zarinpal_conditional_operator' );
				$config["meta"]["zarinpal_conditional_value"]    = rgpost( 'gf_zarinpal_conditional_value' );
				$config["meta"]["zarinpal_conditional_type"]     = rgpost( 'gf_zarinpal_conditional_type' );

				// ------------------
				$config["meta"]["desc_pm"]                = rgpost( "gf_zarinpal_desc_pm" );
				$config["meta"]["customer_fields_desc"]   = rgpost( "zarinpal_customer_field_desc" );
				$config["meta"]["customer_fields_email"]  = rgpost( "zarinpal_customer_field_email" );
				$config["meta"]["customer_fields_mobile"] = rgpost( "zarinpal_customer_field_mobile" );


				$safe_data = array();
				foreach ( $config["meta"] as $key => $val ) {
					if ( ! is_array( $val ) ) {
						$safe_data[ $key ] = sanitize_text_field( $val );
					} else {
						$safe_data[ $key ] = array_map( 'sanitize_text_field', $val );
					}
				}
				$config["meta"] = $safe_data;

				$config = apply_filters( self::$author . '_gform_gateway_save_config', $config );
				$config = apply_filters( self::$author . '_gform_zarinpal_save_config', $config );

				$id = Zarinpal_GF_DB::update_feed( $id, $config["form_id"], $config["is_active"], $config["meta"] );
				if ( ! headers_sent() ) {
					wp_redirect( admin_url( 'admin.php?page=gf_zarinpal&view=edit&id=' . $id . '&updated=true' ) );
					exit;
				} else {
					echo "<script type='text/javascript'>window.onload = function () { top.location.href = '" . admin_url( 'admin.php?page=gf_zarinpal&view=edit&id=' . $id . '&updated=true' ) . "'; };</script>";
					exit;
				}
				?>
                <div class="updated fade"
                     style="padding:6px"><?php echo sprintf( __( "فید به روز شد . %sبازگشت به لیست%s.", "gravityformszarinpal" ), "<a href='?page=gf_zarinpal'>", "</a>" ) ?></div>
				<?php
			}

			$_get_form_id = rgget( 'fid' ) ? rgget( 'fid' ) : ( ! empty( $config["form_id"] ) ? $config["form_id"] : '' );

			$form = array();
			if ( ! empty( $_get_form_id ) ) {
				$form = RGFormsModel::get_form_meta( $_get_form_id );
			}

			if ( rgget( 'updated' ) == 'true' ) {

				$id = empty( $id ) && isset( $_GET['id'] ) ? rgget( 'id' ) : $id;
				$id = absint( $id ); ?>

                <div class="updated fade"
                     style="padding:6px"><?php echo sprintf( __( "فید به روز شد . %sبازگشت به لیست%s . ", "gravityformszarinpal" ), "<a href='?page=gf_zarinpal'>", "</a>" ) ?></div>

				<?php
			}


			if ( ! empty( $_get_form_id ) ) { ?>

                <div id="gf_form_toolbar">
                    <ul id="gf_form_toolbar_links">

						<?php
						$menu_items = apply_filters( 'gform_toolbar_menu', GFForms::get_toolbar_menu_items( $_get_form_id ), $_get_form_id );
						echo GFForms::format_toolbar_menu_items( $menu_items ); ?>

                        <li class="gf_form_switcher">
                            <label for="export_form"><?php _e( 'یک فید انتخاب کنید', 'gravityformszarinpal' ) ?></label>
							<?php
							$feeds = Zarinpal_GF_DB::get_feeds();
							if ( RG_CURRENT_VIEW != 'entry' ) { ?>
                                <select name="form_switcher" id="form_switcher"
                                        onchange="GF_SwitchForm(jQuery(this).val());">
                                    <option value=""><?php _e( 'تغییر فید زرین پال', 'gravityformszarinpal' ) ?></option>
									<?php foreach ( $feeds as $feed ) {
										$selected = $feed["id"] == $id ? "selected='selected'" : ""; ?>
                                        <option
                                                value="<?php echo $feed["id"] ?>" <?php echo $selected ?> ><?php echo sprintf( __( 'فرم: %s (فید: %s)', 'gravityformszarinpal' ), $feed["form_title"], $feed["id"] ) ?></option>
									<?php } ?>
                                </select>
								<?php
							}
							?>
                        </li>
                    </ul>
                </div>
			<?php } ?>

			<?php
			$condition_field_ids = array( '1' => '' );
			$condition_values    = array( '1' => '' );
			$condition_operators = array( '1' => 'is' );
			?>

            <div id="gform_tab_group" class="gform_tab_group vertical_tabs">
				<?php if ( ! empty( $_get_form_id ) ) { ?>
                    <ul id="gform_tabs" class="gform_tabs">
						<?php
						$title        = '';
						$get_form     = GFFormsModel::get_form_meta( $_get_form_id );
						$current_tab  = rgempty( 'subview', $_GET ) ? 'settings' : rgget( 'subview' );
						$current_tab  = ! empty( $current_tab ) ? $current_tab : ' ';
						$setting_tabs = GFFormSettings::get_tabs( $get_form['id'] );
						if ( ! $title ) {
							foreach ( $setting_tabs as $tab ) {
								$query = array(
									'page'    => 'gf_edit_forms',
									'view'    => 'settings',
									'subview' => $tab['name'],
									'id'      => $get_form['id']
								);
								$url   = add_query_arg( $query, admin_url( 'admin.php' ) );
								echo $tab['name'] == 'zarinpal' ? '<li class="active">' : '<li>';
								?>
                                <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $tab['label'] ) ?></a>
                                <span></span>
                                </li>
								<?php
							}
						}
						?>
                    </ul>
				<?php }
				$has_product = false;
				if ( isset( $form["fields"] ) ) {
					foreach ( $form["fields"] as $field ) {
						$shipping_field = GFAPI::get_fields_by_type( $form, array( 'shipping' ) );
						if ( $field["type"] == "product" || ! empty( $shipping_field ) ) {
							$has_product = true;
							break;
						}
					}
				} else if ( empty( $_get_form_id ) ) {
					$has_product = true;
				}
				?>
                <div id="gform_tab_container_<?php echo $_get_form_id ? $_get_form_id : 1 ?>"
                     class="gform_tab_container">
                    <div class="gform_tab_content" id="tab_<?php echo ! empty( $current_tab ) ? $current_tab : '' ?>">
                        <div id="form_settings" class="gform_panel gform_panel_form_settings">
                            <h3>
								<span>
									<i class="fa fa-credit-card"></i>
									<?php _e( "پیکربندی درگاه زرین پال", "gravityformszarinpal" ); ?>
								</span>
                            </h3>
                            <form method="post" action="" id="gform_form_settings">

								<?php wp_nonce_field( "update", "gf_zarinpal_feed" ) ?>


                                <input type="hidden" name="zarinpal_setting_id" value="<?php echo $id ?>"/>
                                <table class="form-table gforms_form_settings" cellspacing="0" cellpadding="0">
                                    <tbody>

                                    <tr style="<?php echo rgget( 'id' ) || rgget( 'fid' ) ? 'display:none !important' : ''; ?>">
                                        <th>
											<?php _e( "انتخاب فرم", "gravityformszarinpal" ); ?>
                                        </th>
                                        <td>
                                            <select id="gf_zarinpal_form" name="gf_zarinpal_form"
                                                    onchange="GF_SwitchFid(jQuery(this).val());">
                                                <option
                                                        value=""><?php _e( "یک فرم انتخاب نمایید", "gravityformszarinpal" ); ?> </option>
												<?php
												$available_forms = Zarinpal_GF_DB::get_available_forms();
												foreach ( $available_forms as $current_form ) {
													$selected = absint( $current_form->id ) == $_get_form_id ? 'selected="selected"' : ''; ?>
                                                    <option
                                                            value="<?php echo absint( $current_form->id ) ?>" <?php echo $selected; ?>><?php echo esc_html( $current_form->title ) ?></option>
													<?php
												}
												?>
                                            </select>
                                            <img
                                                    src="<?php echo esc_url( GFCommon::get_base_url() ) ?>/images/spinner.gif"
                                                    id="zarinpal_wait" style="display: none;"/>
                                        </td>
                                    </tr>

                                    </tbody>
                                </table>

								<?php if ( empty( $has_product ) || ! $has_product ) { ?>
                                    <div id="gf_zarinpal_invalid_product_form" class="gf_zarinpal_invalid_form"
                                         style="background-color:#FFDFDF; margin-top:4px; margin-bottom:6px;padding:18px; border:1px dotted #C89797;">
										<?php _e( "فرم انتخاب شده هیچ گونه فیلد قیمت گذاری ندارد، لطفا پس از افزودن این فیلدها مجددا اقدام نمایید.", "gravityformszarinpal" ) ?>
                                    </div>
								<?php } else { ?>
                                    <table class="form-table gforms_form_settings"
                                           id="zarinpal_field_group" <?php echo empty( $_get_form_id ) ? "style='display:none;'" : "" ?>
                                           cellspacing="0" cellpadding="0">
                                        <tbody>

                                        <tr>
                                            <th>
												<?php _e( "فرم ثبت نام", "gravityformszarinpal" ); ?>
                                            </th>
                                            <td>
                                                <input type="checkbox" name="gf_zarinpal_type"
                                                       id="gf_zarinpal_type_subscription"
                                                       value="subscription" <?php echo rgar( $config['meta'], 'type' ) == "subscription" ? "checked='checked'" : "" ?>/>
                                                <label for="gf_zarinpal_type"></label>
                                                <span
                                                        class="description"><?php _e( 'در صورتی که تیک بزنید عملیات ثبت نام که توسط افزونه User Registration انجام خواهد شد تنها برای پرداخت های موفق عمل خواهد کرد' ); ?></span>
                                            </td>
                                        </tr>

                                        <tr>
                                            <th>
												<?php _e( "توضیحات پرداخت", "gravityformszarinpal" ); ?>
                                            </th>
                                            <td>
                                                <input type="text" name="gf_zarinpal_desc_pm" id="gf_zarinpal_desc_pm"
                                                       class="fieldwidth-1"
                                                       value="<?php echo rgar( $config["meta"], "desc_pm" ) ?>"/>
                                                <span
                                                        class="description"><?php _e( "شورت کد ها : {form_id} , {form_title} , {entry_id}", "gravityformszarinpal" ); ?></span>
                                            </td>
                                        </tr>

                                        <tr>
                                            <th>
												<?php _e( "توضیح تکمیلی", "gravityformszarinpal" ); ?>
                                            </th>
                                            <td class="zarinpal_customer_fields_desc">
												<?php
												if ( ! empty( $form ) ) {
													echo self::get_customer_information_desc( $form, $config );
												}
												?>
                                            </td>
                                        </tr>

                                        <tr>
                                            <th>
												<?php _e( "ایمیل پرداخت کننده", "gravityformszarinpal" ); ?>
                                            </th>
                                            <td class="zarinpal_customer_fields_email">
												<?php
												if ( ! empty( $form ) ) {
													echo self::get_customer_information_email( $form, $config );
												}
												?>
                                            </td>
                                        </tr>

                                        <tr>
                                            <th>
												<?php _e( "تلفن همراه پرداخت کننده", "gravityformszarinpal" ); ?>
                                            </th>
                                            <td class="zarinpal_customer_fields_mobile">
												<?php
												if ( ! empty( $form ) ) {
													echo self::get_customer_information_mobile( $form, $config );
												}
												?>
                                            </td>
                                        </tr>


										<?php $display_post_fields = ! empty( $form ) ? GFCommon::has_post_field( $form["fields"] ) : false; ?>

                                        <tr <?php echo $display_post_fields ? "" : "style='display:none;'" ?>>
                                            <th>
												<?php _e( "نوشته بعد از پرداخت موفق", "gravityformszarinpal" ); ?>
                                            </th>
                                            <td>
                                                <select id="gf_zarinpal_update_action1"
                                                        name="gf_zarinpal_update_action1">
                                                    <option
                                                            value="default" <?php echo rgar( $config["meta"], "update_post_action1" ) == "default" ? "selected='selected'" : "" ?>><?php _e( "وضعیت پیشفرض فرم", "gravityformszarinpal" ) ?></option>
                                                    <option
                                                            value="publish" <?php echo rgar( $config["meta"], "update_post_action1" ) == "publish" ? "selected='selected'" : "" ?>><?php _e( "منتشر شده", "gravityformszarinpal" ) ?></option>
                                                    <option
                                                            value="draft" <?php echo rgar( $config["meta"], "update_post_action1" ) == "draft" ? "selected='selected'" : "" ?>><?php _e( "پیشنویس", "gravityformszarinpal" ) ?></option>
                                                    <option
                                                            value="pending" <?php echo rgar( $config["meta"], "update_post_action1" ) == "pending" ? "selected='selected'" : "" ?>><?php _e( "در انتظار بررسی", "gravityformszarinpal" ) ?></option>
                                                    <option
                                                            value="private" <?php echo rgar( $config["meta"], "update_post_action1" ) == "private" ? "selected='selected'" : "" ?>><?php _e( "خصوصی", "gravityformszarinpal" ) ?></option>
                                                </select>
                                            </td>
                                        </tr>

                                        <tr <?php echo $display_post_fields ? "" : "style='display:none;'" ?>>
                                            <th>
												<?php _e( "نوشته قبل از پرداخت موفق", "gravityformszarinpal" ); ?>
                                            </th>
                                            <td>
                                                <select id="gf_zarinpal_update_action2"
                                                        name="gf_zarinpal_update_action2">
                                                    <option
                                                            value="dont" <?php echo rgar( $config["meta"], "update_post_action2" ) == "dont" ? "selected='selected'" : "" ?>><?php _e( "عدم ایجاد پست", "gravityformszarinpal" ) ?></option>
                                                    <option
                                                            value="default" <?php echo rgar( $config["meta"], "update_post_action2" ) == "default" ? "selected='selected'" : "" ?>><?php _e( "وضعیت پیشفرض فرم", "gravityformszarinpal" ) ?></option>
                                                    <option
                                                            value="publish" <?php echo rgar( $config["meta"], "update_post_action2" ) == "publish" ? "selected='selected'" : "" ?>><?php _e( "منتشر شده", "gravityformszarinpal" ) ?></option>
                                                    <option
                                                            value="draft" <?php echo rgar( $config["meta"], "update_post_action2" ) == "draft" ? "selected='selected'" : "" ?>><?php _e( "پیشنویس", "gravityformszarinpal" ) ?></option>
                                                    <option
                                                            value="pending" <?php echo rgar( $config["meta"], "update_post_action2" ) == "pending" ? "selected='selected'" : "" ?>><?php _e( "در انتظار بررسی", "gravityformszarinpal" ) ?></option>
                                                    <option
                                                            value="private" <?php echo rgar( $config["meta"], "update_post_action2" ) == "private" ? "selected='selected'" : "" ?>><?php _e( "خصوصی", "gravityformszarinpal" ) ?></option>
                                                </select>
                                            </td>
                                        </tr>

                                        <tr>
                                            <th>
												<?php echo __( "سازگاری با افزودنی ها", "gravityformszarinpal" ); ?>
                                            </th>
                                            <td>
                                                <input type="checkbox" name="gf_zarinpal_addon"
                                                       id="gf_zarinpal_addon_true"
                                                       value="true" <?php echo rgar( $config['meta'], 'addon' ) == "true" ? "checked='checked'" : "" ?>/>
                                                <label for="gf_zarinpal_addon"></label>
                                                <span
                                                        class="description"><?php _e( 'برخی افزودنی های گرویتی فرم دارای متد add_delayed_payment_support هستند. در صورتی که میخواهید این افزودنی ها تنها در صورت تراکنش موفق عمل کنند این گزینه را تیک بزنید.', 'gravityformszarinpal' ); ?></span>
                                            </td>
                                        </tr>

										<?php
										do_action( self::$author . '_gform_gateway_config', $config, $form );
										do_action( self::$author . '_gform_zarinpal_config', $config, $form );
										?>

                                        <tr id="gf_zarinpal_conditional_option">
                                            <th>
												<?php _e( "منطق شرطی", "gravityformszarinpal" ); ?>
                                            </th>
                                            <td>
                                                <input type="checkbox" id="gf_zarinpal_conditional_enabled"
                                                       name="gf_zarinpal_conditional_enabled" value="1"
                                                       onclick="if(this.checked){jQuery('#gf_zarinpal_conditional_container').fadeIn('fast');} else{ jQuery('#gf_zarinpal_conditional_container').fadeOut('fast'); }" <?php echo rgar( $config['meta'], 'zarinpal_conditional_enabled' ) ? "checked='checked'" : "" ?>/>
                                                <label for="gf_zarinpal_conditional_enabled"><?php _e( "فعالسازی منطق شرطی", "gravityformszarinpal" ); ?></label><br/>
                                                <br>
                                                <table cellspacing="0" cellpadding="0">
                                                    <tr>
                                                        <td>
                                                            <div id="gf_zarinpal_conditional_container" <?php echo ! rgar( $config['meta'], 'zarinpal_conditional_enabled' ) ? "style='display:none'" : "" ?>>

                                                                <span><?php _e( "این درگاه را فعال کن اگر ", "gravityformszarinpal" ) ?></span>

                                                                <select name="gf_zarinpal_conditional_type">
                                                                    <option value="all" <?php echo rgar( $config['meta'], 'zarinpal_conditional_type' ) == 'all' ? "selected='selected'" : "" ?>><?php _e( "همه", "gravityformszarinpal" ) ?></option>
                                                                    <option value="any" <?php echo rgar( $config['meta'], 'zarinpal_conditional_type' ) == 'any' ? "selected='selected'" : "" ?>><?php _e( "حداقل یکی", "gravityformszarinpal" ) ?></option>
                                                                </select>
                                                                <span><?php _e( "مطابق گزینه های زیر باشند:", "gravityformszarinpal" ) ?></span>

																<?php
																if ( ! empty( $config["meta"]["zarinpal_conditional_field_id"] ) ) {
																	$condition_field_ids = $config["meta"]["zarinpal_conditional_field_id"];
																	if ( ! is_array( $condition_field_ids ) ) {
																		$condition_field_ids = array( '1' => $condition_field_ids );
																	}
																}

																if ( ! empty( $config["meta"]["zarinpal_conditional_value"] ) ) {
																	$condition_values = $config["meta"]["zarinpal_conditional_value"];
																	if ( ! is_array( $condition_values ) ) {
																		$condition_values = array( '1' => $condition_values );
																	}
																}

																if ( ! empty( $config["meta"]["zarinpal_conditional_operator"] ) ) {
																	$condition_operators = $config["meta"]["zarinpal_conditional_operator"];
																	if ( ! is_array( $condition_operators ) ) {
																		$condition_operators = array( '1' => $condition_operators );
																	}
																}

																ksort( $condition_field_ids );
																foreach ( $condition_field_ids as $i => $value ):?>

                                                                    <div class="gf_zarinpal_conditional_div"
                                                                         id="gf_zarinpal_<?php echo $i; ?>__conditional_div">

                                                                        <select class="gf_zarinpal_conditional_field_id"
                                                                                id="gf_zarinpal_<?php echo $i; ?>__conditional_field_id"
                                                                                name="gf_zarinpal_conditional_field_id[<?php echo $i; ?>]"
                                                                                title="">
                                                                        </select>

                                                                        <select class="gf_zarinpal_conditional_operator"
                                                                                id="gf_zarinpal_<?php echo $i; ?>__conditional_operator"
                                                                                name="gf_zarinpal_conditional_operator[<?php echo $i; ?>]"
                                                                                style="font-family:tahoma,serif !important"
                                                                                title="">
                                                                            <option value="is"><?php _e( "هست", "gravityformszarinpal" ) ?></option>
                                                                            <option value="isnot"><?php _e( "نیست", "gravityformszarinpal" ) ?></option>
                                                                            <option value=">"><?php _e( "بیشتر یا بزرگتر از", "gravityformszarinpal" ) ?></option>
                                                                            <option value="<"><?php _e( "کمتر یا کوچکتر از", "gravityformszarinpal" ) ?></option>
                                                                            <option value="contains"><?php _e( "شامل میشود", "gravityformszarinpal" ) ?></option>
                                                                            <option value="starts_with"><?php _e( "شروع می شود با", "gravityformszarinpal" ) ?></option>
                                                                            <option value="ends_with"><?php _e( "تمام میشود با", "gravityformszarinpal" ) ?></option>
                                                                        </select>

                                                                        <div id="gf_zarinpal_<?php echo $i; ?>__conditional_value_container"
                                                                             style="display:inline;">
                                                                        </div>

                                                                        <a class="add_new_condition gficon_link"
                                                                           href="#">
                                                                            <i class="gficon-add"></i>
                                                                        </a>

                                                                        <a class="delete_this_condition gficon_link"
                                                                           href="#">
                                                                            <i class="gficon-subtract"></i>
                                                                        </a>
                                                                    </div>
																<?php endforeach; ?>

                                                                <input type="hidden"
                                                                       value="<?php echo key( array_slice( $condition_field_ids, - 1, 1, true ) ); ?>"
                                                                       id="gf_zarinpal_conditional_counter">

                                                                <div id="gf_no_conditional_message"
                                                                     style="display:none;background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding:18px; border:1px dotted #C89797;">
																	<?php _e( "برای قرار دادن منطق شرطی، باید فیلدهای فرم شما هم قابلیت منطق شرطی را داشته باشند.", "gravityformszarinpal" ) ?>
                                                                </div>

                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td>
                                                <input type="submit" class="button-primary gfbutton"
                                                       name="gf_zarinpal_submit"
                                                       value="<?php _e( "ذخیره", "gravityformszarinpal" ); ?>"/>
                                            </td>
                                        </tr>
                                        </tbody>
                                    </table>
								<?php } ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <style type="text/css">
            .gforms_form_settings select {
                width: 180px !important;
            }

            .delete_this_condition, .add_new_condition {
                text-decoration: none !important;
                color: #000;
                outline: none !important;
            }

            #gf_zarinpal_conditional_container *, .delete_this_condition *, .add_new_condition * {
                outline: none !important;
            }

            .condition_field_value {
                width: 150px !important;
            }

            table.gforms_form_settings th {
                font-weight: 600;
                line-height: 1.3;
                font-size: 14px;
            }

            .gf_zarinpal_conditional_div {
                margin: 3px;
            }
        </style>
        <script type="text/javascript">
            function GF_SwitchFid(fid) {
                jQuery("#zarinpal_wait").show();
                document.location = "?page=gf_zarinpal&view=edit&fid=" + fid;
            }

            function GF_SwitchForm(id) {
                if (id.length > 0) {
                    document.location = "?page=gf_zarinpal&view=edit&id=" + id;
                }
            }

            var form = [];
            form = <?php echo ! empty( $form ) ? GFCommon::json_encode( $form ) : GFCommon::json_encode( array() ) ?>;

            jQuery(document).ready(function ($) {

                var delete_link, selectedField, selectedValue, selectedOperator;

                delete_link = $('.delete_this_condition');
                if (delete_link.length === 1)
                    delete_link.hide();

                $(document.body).on('change', '.gf_zarinpal_conditional_field_id', function () {
                    var id = $(this).attr('id');
                    id = id.replace('gf_zarinpal_', '').replace('__conditional_field_id', '');
                    var selectedOperator = $('#gf_zarinpal_' + id + '__conditional_operator').val();
                    $('#gf_zarinpal_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_zarinpal_" + id + "__conditional", jQuery(this).val(), selectedOperator, "", 20, id));
                }).on('change', '.gf_zarinpal_conditional_operator', function () {
                    var id = $(this).attr('id');
                    id = id.replace('gf_zarinpal_', '').replace('__conditional_operator', '');
                    var selectedOperator = $(this).val();
                    var field_id = $('#gf_zarinpal_' + id + '__conditional_field_id').val();
                    $('#gf_zarinpal_' + id + '__conditional_value_container').html(GetConditionalFieldValues("gf_zarinpal_" + id + "__conditional", field_id, selectedOperator, "", 20, id));
                }).on('click', '.add_new_condition', function () {
                    var parent_div = $(this).parent('.gf_zarinpal_conditional_div');
                    var counter = $('#gf_zarinpal_conditional_counter');
                    var new_id = parseInt(counter.val()) + 1;
                    var content = parent_div[0].outerHTML
                        .replace(new RegExp('gf_zarinpal_\\d+__', 'g'), ('gf_zarinpal_' + new_id + '__'))
                        .replace(new RegExp('\\[\\d+\\]', 'g'), ('[' + new_id + ']'));
                    counter.val(new_id);
                    counter.before(content);
                    //parent_div.after(content);
                    RefreshConditionRow("gf_zarinpal_" + new_id + "__conditional", "", "is", "", new_id);
                    $('.delete_this_condition').show();
                    return false;
                }).on('click', '.delete_this_condition', function () {
                    $(this).parent('.gf_zarinpal_conditional_div').remove();
                    var delete_link = $('.delete_this_condition');
                    if (delete_link.length === 1)
                        delete_link.hide();
                    return false;
                });

				<?php foreach ( $condition_field_ids as $i => $field_id ) : ?>
                selectedField = "<?php echo str_replace( '"', '\"', $field_id )?>";
                selectedValue = "<?php echo str_replace( '"', '\"', $condition_values[ '' . $i . '' ] )?>";
                selectedOperator = "<?php echo str_replace( '"', '\"', $condition_operators[ '' . $i . '' ] )?>";
                RefreshConditionRow("gf_zarinpal_<?php echo $i;?>__conditional", selectedField, selectedOperator, selectedValue, <?php echo $i;?>);
				<?php endforeach;?>
            });

            function RefreshConditionRow(input, selectedField, selectedOperator, selectedValue, index) {
                var field_id = jQuery("#" + input + "_field_id");
                field_id.html(GetSelectableFields(selectedField, 20));
                var optinConditionField = field_id.val();
                var checked = jQuery("#" + input + "_enabled").attr('checked');
                if (optinConditionField) {
                    jQuery("#gf_no_conditional_message").hide();
                    jQuery("#" + input + "_div").show();
                    jQuery("#" + input + "_value_container").html(GetConditionalFieldValues("" + input + "", optinConditionField, selectedOperator, selectedValue, 20, index));
                    jQuery("#" + input + "_value").val(selectedValue);
                    jQuery("#" + input + "_operator").val(selectedOperator);
                }
                else {
                    jQuery("#gf_no_conditional_message").show();
                    jQuery("#" + input + "_div").hide();
                }
                if (!checked) jQuery("#" + input + "_container").hide();
            }

            /**
             * @return {string}
             */
            function GetConditionalFieldValues(input, fieldId, selectedOperator, selectedValue, labelMaxCharacters, index) {
                if (!fieldId)
                    return "";
                var str = "";
                var name = (input.replace(new RegExp('_\\d+__', 'g'), '_')) + "_value[" + index + "]";
                var field = GetFieldById(fieldId);
                if (!field)
                    return "";

                var is_text = false;

                if (selectedOperator == '' || selectedOperator == 'is' || selectedOperator == 'isnot') {
                    if (field["type"] == "post_category" && field["displayAllCategories"]) {
                        str += '<?php $dd = wp_dropdown_categories( array(
							"class"        => "condition_field_value",
							"orderby"      => "name",
							"id"           => "gf_dropdown_cat_id",
							"name"         => "gf_dropdown_cat_name",
							"hierarchical" => true,
							"hide_empty"   => 0,
							"echo"         => false
						) ); echo str_replace( "\n", "", str_replace( "'", "\\'", $dd ) ); ?>';
                        str = str.replace("gf_dropdown_cat_id", "" + input + "_value").replace("gf_dropdown_cat_name", name);
                    }
                    else if (field.choices) {
                        var isAnySelected = false;
                        str += "<select class='condition_field_value' id='" + input + "_value' name='" + name + "'>";
                        for (var i = 0; i < field.choices.length; i++) {
                            var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                            var isSelected = fieldValue == selectedValue;
                            var selected = isSelected ? "selected='selected'" : "";
                            if (isSelected)
                                isAnySelected = true;
                            str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                        }
                        if (!isAnySelected && selectedValue) {
                            str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                        }
                        str += "</select>";
                    }
                    else {
                        is_text = true;
                    }
                }
                else {
                    is_text = true;
                }

                if (is_text) {
                    selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
                    str += "<input type='text' class='condition_field_value' style='padding:3px' placeholder='<?php _e( "یک مقدار وارد نمایید", "gravityformszarinpal" ); ?>' id='" + input + "_value' name='" + name + "' value='" + selectedValue + "'>";
                }
                return str;
            }

            /**
             * @return {string}
             */
            function GetSelectableFields(selectedFieldId, labelMaxCharacters) {
                var str = "";
                if (typeof form.fields !== "undefined") {
                    var inputType;
                    var fieldLabel;
                    for (var i = 0; i < form.fields.length; i++) {
                        fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                        inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                        if (IsConditionalLogicField(form.fields[i])) {
                            var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                            str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                        }
                    }
                }
                return str;
            }

            /**
             * @return {string}
             */
            function TruncateMiddle(text, maxCharacters) {
                if (!text)
                    return "";
                if (text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            /**
             * @return {object}
             */
            function GetFieldById(fieldId) {
                for (var i = 0; i < form.fields.length; i++) {
                    if (form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            /**
             * @return {boolean}
             */
            function IsConditionalLogicField(field) {
                var inputType = field.inputType ? field.inputType : field.type;
                var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                    "post_tags", "post_custom_field", "post_content", "post_excerpt"];
                var index = jQuery.inArray(inputType, supported_fields);
                return index >= 0;
            }
        </script>
		<?php
	}

	public static function Request( $confirmation, $form, $entry, $ajax ) {

		do_action( 'gf_gateway_request_1', $confirmation, $form, $entry, $ajax );
		do_action( 'gf_zarinpal_request_1', $confirmation, $form, $entry, $ajax );

		if ( apply_filters( 'gf_zarinpal_request_return', apply_filters( 'gf_gateway_request_return', false, $confirmation, $form, $entry, $ajax ), $confirmation, $form, $entry, $ajax ) ) {
			return $confirmation;
		}

		$valid_checker = $confirmation == 'valid_checker';
		$custom        = $confirmation == 'custom';

		global $current_user;
		$user_id   = 0;
		$user_name = __( 'مهمان', 'gravityformszarinpal' );

		if ( $current_user && $user_data = get_userdata( $current_user->ID ) ) {
			$user_id   = $current_user->ID;
			$user_name = $user_data->display_name;
		}

		if ( ! $valid_checker ) {

			$entry_id = $entry['id'];

			if ( ! $custom ) {

				if ( RGForms::post( "gform_submit" ) != $form['id'] ) {
					return $confirmation;
				}

				$config = self::get_active_config( $form );
				if ( empty( $config ) ) {
					return $confirmation;
				}

				gform_update_meta( $entry['id'], 'zarinpal_feed_id', $config['id'] );
				gform_update_meta( $entry['id'], 'payment_type', 'form' );
				gform_update_meta( $entry['id'], 'payment_gateway', self::get_gname() );

				switch ( $config["meta"]["type"] ) {
					case "subscription" :
						$transaction_type = 2;
						break;

					default :
						$transaction_type = 1;
						break;
				}

				if ( GFCommon::has_post_field( $form["fields"] ) ) {
					if ( ! empty( $config["meta"]["update_post_action2"] ) ) {
						if ( $config["meta"]["update_post_action2"] != 'dont' ) {
							if ( $config["meta"]["update_post_action2"] != 'default' ) {
								$form['postStatus'] = $config["meta"]["update_post_action2"];
							}
						} else {
							$dont_create = true;
						}
					}
					if ( empty( $dont_create ) ) {
						RGFormsModel::create_post( $form, $entry );
					}
				}

				$Amount = self::get_order_total( $form, $entry );
				$Amount = apply_filters( self::$author . "_gform_form_gateway_price_{$form['id']}", apply_filters( self::$author . "_gform_form_gateway_price", $Amount, $form, $entry ), $form, $entry );
				$Amount = apply_filters( self::$author . "_gform_form_zarinpal_price_{$form['id']}", apply_filters( self::$author . "_gform_form_zarinpal_price", $Amount, $form, $entry ), $form, $entry );
				$Amount = apply_filters( self::$author . "_gform_gateway_price_{$form['id']}", apply_filters( self::$author . "_gform_gateway_price", $Amount, $form, $entry ), $form, $entry );
				$Amount = apply_filters( self::$author . "_gform_zarinpal_price_{$form['id']}", apply_filters( self::$author . "_gform_zarinpal_price", $Amount, $form, $entry ), $form, $entry );

				if ( empty( $Amount ) || ! $Amount || $Amount == 0 ) {
					unset( $entry["payment_status"], $entry["payment_method"], $entry["is_fulfilled"], $entry["transaction_type"], $entry["payment_amount"], $entry["payment_date"] );
					$entry["payment_method"] = "zarinpal";
					GFAPI::update_entry( $entry );

					return self::redirect_confirmation( add_query_arg( array( 'no' => 'true' ), self::Return_URL( $form['id'], $entry['id'] ) ), $ajax );
				} else {

					$Desc1 = '';
					if ( ! empty( $config["meta"]["desc_pm"] ) ) {
						$Desc1 = str_replace( array( '{entry_id}', '{form_title}', '{form_id}' ), array(
							$entry['id'],
							$form['title'],
							$form['id']
						), $config["meta"]["desc_pm"] );
					}
					$Desc2 = '';
					if ( rgpost( 'input_' . str_replace( ".", "_", $config["meta"]["customer_fields_desc"] ) ) ) {
						$Desc2 = rgpost( 'input_' . str_replace( ".", "_", $config["meta"]["customer_fields_desc"] ) );
					}

					if ( ! empty( $Desc1 ) && ! empty( $Desc2 ) ) {
						$Description = $Desc1 . ' - ' . $Desc2;
					} else if ( ! empty( $Desc1 ) && empty( $Desc2 ) ) {
						$Description = $Desc1;
					} else if ( ! empty( $Desc2 ) && empty( $Desc1 ) ) {
						$Description = $Desc2;
					} else {
						$Description = ' ';
					}
					$Description = sanitize_text_field( $Description );

					$Email = '';
					if ( rgpost( 'input_' . str_replace( ".", "_", $config["meta"]["customer_fields_email"] ) ) ) {
						$Email = sanitize_text_field( rgpost( 'input_' . str_replace( ".", "_", $config["meta"]["customer_fields_email"] ) ) );
					}

					$Mobile = '';
					if ( rgpost( 'input_' . str_replace( ".", "_", $config["meta"]["customer_fields_mobile"] ) ) ) {
						$Mobile = sanitize_text_field( rgpost( 'input_' . str_replace( ".", "_", $config["meta"]["customer_fields_mobile"] ) ) );
					}

				}

			} else {

				$Amount = gform_get_meta( rgar( $entry, 'id' ), 'bahadori_part_price_' . $form['id'] );
				$Amount = apply_filters( self::$author . "_gform_custom_gateway_price_{$form['id']}", apply_filters( self::$author . "_gform_custom_gateway_price", $Amount, $form, $entry ), $form, $entry );
				$Amount = apply_filters( self::$author . "_gform_custom_zarinpal_price_{$form['id']}", apply_filters( self::$author . "_gform_custom_zarinpal_price", $Amount, $form, $entry ), $form, $entry );
				$Amount = apply_filters( self::$author . "_gform_gateway_price_{$form['id']}", apply_filters( self::$author . "_gform_gateway_price", $Amount, $form, $entry ), $form, $entry );
				$Amount = apply_filters( self::$author . "_gform_zarinpal_price_{$form['id']}", apply_filters( self::$author . "_gform_zarinpal_price", $Amount, $form, $entry ), $form, $entry );

				$Description = gform_get_meta( rgar( $entry, 'id' ), 'bahadori_part_desc_' . $form['id'] );
				$Description = apply_filters( self::$author . '_gform_zarinpal_gateway_desc_', apply_filters( self::$author . '_gform_custom_gateway_desc_', $Description, $form, $entry ), $form, $entry );

				$Paymenter = gform_get_meta( rgar( $entry, 'id' ), 'bahadori_part_name_' . $form['id'] );
				$Email     = gform_get_meta( rgar( $entry, 'id' ), 'bahadori_part_email_' . $form['id'] );
				$Mobile    = gform_get_meta( rgar( $entry, 'id' ), 'bahadori_part_mobile_' . $form['id'] );

				$entry_id = GFAPI::add_entry( $entry );
				$entry    = GFPersian_Payments::get_entry( $entry_id );

				do_action( 'gf_gateway_request_add_entry', $confirmation, $form, $entry, $ajax );
				do_action( 'gf_zarinpal_request_add_entry', $confirmation, $form, $entry, $ajax );

				gform_update_meta( $entry_id, 'payment_gateway', self::get_gname() );
				gform_update_meta( $entry_id, 'payment_type', 'custom' );
			}

			unset( $entry["payment_status"] );
			unset( $entry["payment_method"] );
			unset( $entry["is_fulfilled"] );
			unset( $entry["transaction_type"] );
			unset( $entry["payment_amount"] );
			unset( $entry["payment_date"] );
			unset( $entry["transaction_id"] );

			$entry["payment_status"] = "Processing";
			$entry["payment_method"] = "zarinpal";
			$entry["is_fulfilled"]   = 0;
			if ( ! empty( $transaction_type ) ) {
				$entry["transaction_type"] = $transaction_type;
			}
			GFAPI::update_entry( $entry );
			$entry = GFPersian_Payments::get_entry( $entry_id );


			$ReturnPath = self::Return_URL( $form['id'], $entry_id );
			$ResNumber  = apply_filters( 'gf_zarinpal_res_number', apply_filters( 'gf_gateway_res_number', $entry_id, $entry, $form ), $entry, $form );
		} else {

			$Amount      = 2000;
			$ReturnPath  = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
			$Email       = '';
			$Mobile      = '';
			$ResNumber   = rand( 1000, 9999 );
			$Description = __( 'جهت بررسی صحیح بودن تنظیمات درگاه گرویتی فرم زرین پال', 'gravityformszarinpal' );

		}
		$Mobile = GFPersian_Payments::fix_mobile( $Mobile );

		do_action( 'gf_gateway_request_2', $confirmation, $form, $entry, $ajax );
		do_action( 'gf_zarinpal_request_2', $confirmation, $form, $entry, $ajax );

		if ( ! $custom ) {
			$Amount = GFPersian_Payments::amount( $Amount, 'IRT', $form, $entry );
		}

		//$Email = !filter_var($Email, FILTER_VALIDATE_EMAIL) === false ? $Email : '';
		//$Mobile = preg_match('/^09[0-9]{9}/i', $Mobile) ? $Mobile : '';

		try {

			$params = [
				'merchant_id' => self::get_merchent(),
				'amount' => $Amount,
				'callback_url' => $ReturnPath,
				'currency' => self::get_currency(),
				'description' =>  $Description != '' ? $Description : __("پرداخت زرین پال برای فرم شماره : " .  $form['id'],'gravityformszarinpal'),
			];

			if ($Mobile !== '' || $Email !== '') {
				$metadata['metadata'] = [];
	
				if ($Mobile !== '') {
					array_push($metadata['metadata'], ['mobile' => $Mobile]);
				}
				if ($Email !== '') {
					array_push($metadata['metadata'], ['email' => $Email]);
				}

				array_push($metadata['metadata'], ['order_id' => $form['id']]);
	
				$params = array_merge($params, $metadata);
			}

			$result = self::zarinpalPayment('request', $params);

			if ($result['data']['code'] == 100 ) {

				$Payment_URL = 'https://www.zarinpal.com/pg/StartPay/' . $result['data']['authority'];

				if ( $valid_checker ) {
					return true;
				} else {
					return self::redirect_confirmation( $Payment_URL, $ajax );
				}

			} else {
				$Message = self::error_message($result['errors']['code']);
			}
		} catch ( Exception $ex ) {
			$Message = $ex->getMessage();
		}

		$Message = ! empty( $Message ) ? $Message : __( 'خطایی رخ داده است.', 'gravityformszarinpal' );

		$confirmation = __( 'متاسفانه نمیتوانیم به درگاه متصل شویم. علت : </br>', 'gravityformszarinpal' ) . $Message;

		if ( $valid_checker ) {
			return $Message;
		}

		$entry                   = GFPersian_Payments::get_entry( $entry_id );
		$entry['payment_status'] = 'Failed';
		GFAPI::update_entry( $entry );

		RGFormsModel::add_note( $entry_id, $user_id, $user_name, sprintf( __( 'خطا در اتصال به درگاه رخ داده است : %s', "gravityformszarinpal" ), $Message ) );

		if ( ! $custom ) {
			GFPersian_Payments::notification( $form, $entry );
		}

		$default_anchor = 0;
		$anchor         = gf_apply_filters( 'gform_confirmation_anchor', $form['id'], $default_anchor ) ? "<a id='gf_{$form['id']}' name='gf_{$form['id']}' class='gform_anchor' ></a>" : '';
		$nl2br          = ! empty( $form['confirmation'] ) && rgar( $form['confirmation'], 'disableAutoformat' ) ? false : true;
		$cssClass       = rgar( $form, 'cssClass' );

		return $confirmation = empty( $confirmation ) ? "{$anchor} " : "{$anchor}<div id='gform_confirmation_wrapper_{$form['id']}' class='gform_confirmation_wrapper {$cssClass}'><div id='gform_confirmation_message_{$form['id']}' class='gform_confirmation_message_{$form['id']} gform_confirmation_message'>" . GFCommon::replace_variables( $confirmation, $form, $entry, false, true, $nl2br ) . '</div></div>';
	}


	public static function Verify() {

		if ( apply_filters( 'gf_gateway_zarinpal_return', apply_filters( 'gf_gateway_verify_return', false ) ) ) {
			return;
		}

		if ( ! self::is_gravityforms_supported() ) {
			return;
		}

		if ( empty( $_GET['id'] ) || empty( $_GET['entry'] ) || ! is_numeric( rgget( 'id' ) ) || ! is_numeric( rgget( 'entry' ) ) ) {
			return;
		}

		$form_id  = $_GET['id'];
		$entry_id = $_GET['entry'];

		$entry = GFPersian_Payments::get_entry( $entry_id );

		if ( is_wp_error( $entry ) ) {
			return;
		}

		if ( isset( $entry["payment_method"] ) && $entry["payment_method"] == 'zarinpal' ) {

			$form = RGFormsModel::get_form_meta( $form_id );

			$payment_type = gform_get_meta( $entry["id"], 'payment_type' );
			gform_delete_meta( $entry['id'], 'payment_type' );

			if ( $payment_type != 'custom' ) {
				$config = self::get_config_by_entry( $entry );
				if ( empty( $config ) ) {
					return;
				}
			} else {
				$config = apply_filters( self::$author . '_gf_zarinpal_config', apply_filters( self::$author . '_gf_gateway_config', array(), $form, $entry ), $form, $entry );
			}


			if ( ! empty( $entry["payment_date"] ) ) {
				/*
                if( ! class_exists("GFFormDisplay") )
                    require_once(GFCommon::get_base_path() . "/form_display.php");

                $default_anchor = 0;
                $anchor         = gf_apply_filters( 'gform_confirmation_anchor', $form['id'], $default_anchor ) ? "<a id='gf_{$form['id']}' name='gf_{$form['id']}' class='gform_anchor' ></a>" : '';
                $nl2br          = !empty( $form['confirmation'] ) && rgar( rgar( $form, 'confirmation' ), 'disableAutoformat' ) ? false : true;
                $cssClass       = rgar( $form, 'cssClass' );
                $confirmation = __('نتیجه تراکنش قبلا مشخص شده است.' , 'gravityformszarinpal');
                $confirmation   = empty( $confirmation ) ? "{$anchor} " : "{$anchor}<div id='gform_confirmation_wrapper_{$form['id']}' class='gform_confirmation_wrapper {$cssClass}'><div id='gform_confirmation_message_{$form['id']}' class='gform_confirmation_message_{$form['id']} gform_confirmation_message'>" . GFCommon::replace_variables( $confirmation, $form, $entry, false, true, $nl2br ) . '</div></div>';
                GFFormDisplay::$submission[$form_id] = array("is_confirmation" => true, "confirmation_message" => $confirmation, "form" => $form, "entry" => $entry, "lead" => $entry,"page_number"=> 1);
				*/
				return;
			}

			global $current_user;
			$user_id   = 0;
			$user_name = __( "مهمان", "gravityformszarinpal" );
			if ( $current_user && $user_data = get_userdata( $current_user->ID ) ) {
				$user_id   = $current_user->ID;
				$user_name = $user_data->display_name;
			}

			$transaction_type = 1;
			if ( ! empty( $config["meta"]["type"] ) && $config["meta"]["type"] == 'subscription' ) {
				$transaction_type = 2;
			}

			if ( $payment_type == 'custom' ) {
				$Amount = $Total = gform_get_meta( $entry["id"], 'bahadori_part_price_' . $form_id );
			} else {
				$Amount = $Total = self::get_order_total( $form, $entry );
			}
			$Total_Money = GFCommon::to_money( $Total, $entry["currency"] );

			$free = false;
			if ( empty( $_GET['no'] ) || $_GET['no'] != 'true' ) {

				if ( isset( $_GET['Status'] ) && strtolower( $_GET['Status'] ) == 'ok' ) {

					$Authority  = isset( $_GET['Authority'] ) ? sanitize_text_field( $_GET['Authority'] ) : '';
					$MerchantID = self::get_merchent();

					try {
						$params = [
							'merchant_id' => $MerchantID,
							'authority'  => $Authority,
							'amount'     => $Amount
						];

						$result = self::zarinpalPayment('verify', $params);
						
						if ( $result['data'] &&  $result['data']['code'] === 100 ) {
							$Message = '';
							$Status  = 'completed';
						} elseif ($result['data'] && $result['data']['code'] === 101) {
							$Message = '';
							$Status  = 'completed';
						} else {
							$Message = self::error_message( $result['errors']['code'] );
							$Status  = 'failed';
						}


					} catch ( Exception $ex ) {
						$Message = $ex->getMessage();
						$Status  = 'failed';
					}
				} else {
					$Message = '';
					$Status  = 'cancelled';
				}
				$Transaction_ID = ! empty($result['data']) ? $result['data']['ref_id'] : '-';
				
			} else {
				$Status         = 'completed';
				$Message        = '';
				$Transaction_ID = apply_filters( self::$author . '_gf_rand_transaction_id', GFPersian_Payments::transaction_id( $entry ), $form, $entry );
				$free           = true;
			}

			$Status         = ! empty( $Status ) ? $Status : 'failed';
			$transaction_id = ! empty( $Transaction_ID ) ? $Transaction_ID : '';
			$transaction_id = apply_filters( self::$author . '_gf_real_transaction_id', $transaction_id, $Status, $form, $entry );

			$entry["payment_date"]     = gmdate( "Y-m-d H:i:s" );
			$entry["transaction_id"]   = $transaction_id;
			$entry["transaction_type"] = $transaction_type;

			if ( $Status == 'completed' ) {

				$entry["is_fulfilled"]   = 1;
				$entry["payment_amount"] = $Total;

				if ( $transaction_type == 2 ) {
					$entry["payment_status"] = "Active";
					RGFormsModel::add_note( $entry["id"], $user_id, $user_name, __( "تغییرات اطلاعات فیلدها فقط در همین پیام ورودی اعمال خواهد شد و بر روی وضعیت کاربر تاثیری نخواهد داشت .", "gravityformszarinpal" ) );
				} else {
					$entry["payment_status"] = "Paid";
				}

				if ( $free == true ) {
					//unset( $entry["payment_status"] );
					unset( $entry["payment_amount"] );
					unset( $entry["payment_method"] );
					unset( $entry["is_fulfilled"] );
					gform_delete_meta( $entry['id'], 'payment_gateway' );
					$Note = sprintf( __( 'وضعیت پرداخت : رایگان - بدون نیاز به درگاه پرداخت', "gravityformszarinpal" ) );
				} else {
					$Note = sprintf( __( 'وضعیت پرداخت : موفق - مبلغ پرداختی : %s - کد تراکنش : %s', "gravityformszarinpal" ), $Total_Money, $transaction_id );
				}

				GFAPI::update_entry( $entry );


				if ( apply_filters( self::$author . '_gf_zarinpal_post', apply_filters( self::$author . '_gf_gateway_post', ( $payment_type != 'custom' ), $form, $entry ), $form, $entry ) ) {

					$has_post = GFCommon::has_post_field( $form["fields"] ) ? true : false;

					if ( ! empty( $config["meta"]["update_post_action1"] ) && $config["meta"]["update_post_action1"] != 'default' ) {
						$new_status = $config["meta"]["update_post_action1"];
					} else {
						$new_status = rgar( $form, 'postStatus' );
					}

					if ( empty( $entry["post_id"] ) && $has_post ) {
						$form['postStatus'] = $new_status;
						RGFormsModel::create_post( $form, $entry );
						$entry = GFPersian_Payments::get_entry( $entry_id );
					}

					if ( ! empty( $entry["post_id"] ) && $has_post ) {
						$post = get_post( $entry["post_id"] );
						if ( is_object( $post ) ) {
							if ( $new_status != $post->post_status ) {
								$post->post_status = $new_status;
								wp_update_post( $post );
							}
						}
					}
				}

				if ( ! empty( $__params ) ) {
					GFPersian_Payments::set_verification( $entry, __CLASS__, $__params );
				}

				$user_registration_slug = apply_filters( 'gf_user_registration_slug', 'gravityformsuserregistration' );
				$paypal_config          = array( 'meta' => array() );
				if ( ! empty( $config["meta"]["addon"] ) && $config["meta"]["addon"] == 'true' ) {
					if ( class_exists( 'GFAddon' ) && method_exists( 'GFAddon', 'get_registered_addons' ) ) {
						$addons = GFAddon::get_registered_addons();
						foreach ( (array) $addons as $addon ) {
							if ( is_callable( array( $addon, 'get_instance' ) ) ) {
								$addon = call_user_func( array( $addon, 'get_instance' ) );
								if ( is_object( $addon ) && method_exists( $addon, 'get_slug' ) ) {
									$slug = $addon->get_slug();
									if ( $slug != $user_registration_slug ) {
										$paypal_config['meta'][ 'delay_' . $slug ] = true;
									}
								}
							}
						}
					}
				}
				if ( ! empty( $config["meta"]["type"] ) && $config["meta"]["type"] == "subscription" ) {
					$paypal_config['meta'][ 'delay_' . $user_registration_slug ] = true;
				}

				do_action( "gform_zarinpal_fulfillment", $entry, $config, $transaction_id, $Total );
				do_action( "gform_gateway_fulfillment", $entry, $config, $transaction_id, $Total );
				do_action( "gform_paypal_fulfillment", $entry, $paypal_config, $transaction_id, $Total );
			} else if ( $Status == 'cancelled' ) {
				$entry["payment_status"] = "Cancelled";
				$entry["payment_amount"] = 0;
				$entry["is_fulfilled"]   = 0;
				GFAPI::update_entry( $entry );

				$Note = sprintf( __( 'وضعیت پرداخت : منصرف شده - مبلغ قابل پرداخت : %s - کد تراکنش : %s', "gravityformszarinpal" ), $Total_Money, $transaction_id );
			} else {
				$entry["payment_status"] = "Failed";
				$entry["payment_amount"] = 0;
				$entry["is_fulfilled"]   = 0;
				GFAPI::update_entry( $entry );

				$Note = sprintf( __( 'وضعیت پرداخت : ناموفق - مبلغ قابل پرداخت : %s - کد تراکنش : %s - علت خطا : %s', "gravityformszarinpal" ), $Total_Money, $transaction_id, $Message );
			}

			$entry = GFPersian_Payments::get_entry( $entry_id );
			RGFormsModel::add_note( $entry["id"], $user_id, $user_name, $Note );
			do_action( 'gform_post_payment_status', $config, $entry, strtolower( $Status ), $transaction_id, '', $Total, '', '' );
			do_action( 'gform_post_payment_status_' . __CLASS__, $config, $form, $entry, strtolower( $Status ), $transaction_id, '', $Total, '', '' );


			if ( apply_filters( self::$author . '_gf_zarinpal_verify', apply_filters( self::$author . '_gf_gateway_verify', ( $payment_type != 'custom' ), $form, $entry ), $form, $entry ) ) {
				GFPersian_Payments::notification( $form, $entry );
				GFPersian_Payments::confirmation( $form, $entry, $Message );
			}
		}
	}


    /**
     * Zarinpal error message.
     * 
     * @param int $code
     * @return string
     */
    public static function error_message($code)
    {
        $message = null;

        switch ($code) {
            case $code == -9:
                $message = ('اطلاعات ارسال شده نادرست می باشد.');
                $message .= "<br>" . ('1- مرچنت کد داخل تنظیمات وارد نشده باشد');
                $message .= "<br>" . ('2- مبلغ پرداختی کمتر یا بیشتر از حد مجاز می باشد');
            break; 
            case $code == -10:
                $message = ('ای پی یا مرچنت كد پذیرنده صحیح نیست.');
            break; 
            case $code == -11:
                $message = ('مرچنت کد فعال نیست، پذیرنده مشکل خود را به امور مشتریان زرین‌پال ارجاع دهد.');
            break; 
            case $code == -12:
                $message = ('تلاش بیش از دفعات مجاز در یک بازه زمانی کوتاه به امور مشتریان زرین پال اطلاع دهید');
            break; 
            case $code == -15:
                $message = ('درگاه پرداخت به حالت تعلیق در آمده است، پذیرنده مشکل خود را به امور مشتریان زرین‌پال ارجاع دهد.');
            break; 
            case $code == -16:
                $message = ('سطح تایید پذیرنده پایین تر از سطح نقره ای است.');
            break; 
            case $code == -17:
                $message = ('محدودیت پذیرنده در سطح آبی');
            break; 
            case $code == -30:
                $message = ('پذیرنده اجازه دسترسی به سرویس تسویه اشتراکی شناور را ندارد.');
            break; 
            case $code == -31:
                $message = ('حساب بانکی تسویه را به پنل اضافه کنید. مقادیر وارد شده برای تسهیم درست نیست. پذیرنده جهت استفاده از خدمات سرویس تسویه اشتراکی شناور، باید حساب بانکی معتبری به پنل کاربری خود اضافه نماید.');
            break; 
            case $code == -32:
                $message = ('مبلغ وارد شده از مبلغ کل تراکنش بیشتر است.');
            break; 
            case $code == -33:
                $message = ('درصدهای وارد شده صحیح نیست.');
            break; 
            case $code == -34:
                $message = ('مبلغ وارد شده از مبلغ کل تراکنش بیشتر است.');
            break; 
            case $code == -35:
                $message = ('تعداد افراد دریافت کننده تسهیم بیش از حد مجاز است.');
            break; 
            case $code == -36:
                $message = ('حداقل مبلغ جهت تسهیم باید 10000 ریال باشد');
            break; 
            case $code == -37:
                $message = ('یک یا چند شماره شبای وارد شده برای تسهیم از سمت بانک غیر فعال است.');
            break; 
            case $code == -38:
                $message = ('خط،عدم تعریف صحیح شبا،لطفا دقایقی دیگر تلاش کنید.');
            break; 
            case $code == -39:
                $message = ('خطایی رخ داده است به امور مشتریان زرین پال اطلاع دهید');
            break; 
            case $code == -50:
                $message = ('مبلغ پرداخت شده با مقدار مبلغ ارسالی در متد وریفای متفاوت است.');
            break; 
            case $code == -51:
                $message = ('پرداخت ناموفق');
            break; 
            case $code == -52:
                $message = ('خطای غیر منتظره‌ای رخ داده است. پذیرنده مشکل خود را به امور مشتریان زرین‌پال ارجاع دهد.');
            break; 
            case $code == -53:
                $message = ('پرداخت متعلق به این مرچنت کد نیست.');
            break; 
            case $code == -54:
                $message = ('اتوریتی نامعتبر است.');
            break;
        }

        return $message;
    }

	private static function zarinpalPayment($action, array $params)
	{
		$jsonData = json_encode($params);
		$ch = curl_init("https://api.zarinpal.com/pg/v4/payment/$action.json");
		curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen($jsonData)
		));

		$result = curl_exec($ch);
		$result = json_decode($result, true, JSON_PRETTY_PRINT);
		curl_close($ch);
		return $result;
	}
}