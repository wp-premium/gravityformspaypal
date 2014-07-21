<?php
/*
Plugin Name: Gravity Forms PayPal Add-On
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with PayPal, enabling end users to purchase goods and services through Gravity Forms.
Version: 1.10.3
Author: rocketgenius
Author URI: http://www.rocketgenius.com

------------------------------------------------------------------------
Copyright 2009 rocketgenius
last updated: October 20, 2010

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('parse_request', array("GFPayPal", "process_ipn"));
add_action('wp',  array('GFPayPal', 'maybe_thankyou_page'), 5);

add_action('init',  array('GFPayPal', 'init'));
register_activation_hook( __FILE__, array("GFPayPal", "add_permissions"));

class GFPayPal {

    private static $path = "gravityformspaypal/paypal.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravityformspaypal";
    private static $version = "1.10.3";
    private static $min_gravityforms_version = "1.6.4";
    private static $production_url = "https://www.paypal.com/cgi-bin/webscr/";
    private static $sandbox_url = "https://www.sandbox.paypal.com/cgi-bin/webscr/";
    private static $supported_fields = array("checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
		                            "post_tags", "post_custom_field", "post_content", "post_excerpt");

    //Plugin starting point. Will load appropriate files
    public static function init(){
		//supports logging
		add_filter("gform_logging_supported", array("GFPayPal", "set_logging_supported"));

        if(basename($_SERVER['PHP_SELF']) == "plugins.php") {

            //loading translations
            load_plugin_textdomain('gravityformspaypal', FALSE, '/gravityformspaypal/languages' );

            add_action('after_plugin_row_' . self::$path, array('GFPayPal', 'plugin_row') );

        }

        if(!self::is_gravityforms_supported())
           return;

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravityformspaypal', FALSE, '/gravityformspaypal/languages' );

            //automatic upgrade hooks
            add_filter("transient_update_plugins", array('GFPayPal', 'check_update'));
            add_filter("site_transient_update_plugins", array('GFPayPal', 'check_update'));
            add_action('install_plugins_pre_plugin-information', array('GFPayPal', 'display_changelog'));
            add_action('gform_after_check_update', array("GFPayPal", 'flush_version_info'));

            //integrating with Members plugin
            if(function_exists('members_get_capabilities'))
                add_filter('members_get_capabilities', array("GFPayPal", "members_get_capabilities"));

            //creates the subnav left menu
            add_filter("gform_addon_navigation", array('GFPayPal', 'create_menu'));

            //add actions to allow the payment status to be modified
            add_action('gform_payment_status', array('GFPayPal','admin_edit_payment_status'), 3, 3);
            add_action('gform_entry_info', array('GFPayPal','admin_edit_payment_status_details'), 4, 2);
            add_action('gform_after_update_entry', array('GFPayPal','admin_update_payment'), 4, 2);


            if(self::is_paypal_page()){

                //loading Gravity Forms tooltips
                require_once(GFCommon::get_base_path() . "/tooltips.php");
                add_filter('gform_tooltips', array('GFPayPal', 'tooltips'));

                //enqueueing sack for AJAX requests
                wp_enqueue_script(array("sack"));

                //loading data lib
                require_once(self::get_base_path() . "/data.php");

                //loading upgrade lib
                if(!class_exists("RGPayPalUpgrade"))
                    require_once("plugin-upgrade.php");



                //runs the setup when version changes
                self::setup();

            }
            else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

                //loading data class
                require_once(self::get_base_path() . "/data.php");

                add_action('wp_ajax_gf_paypal_update_feed_active', array('GFPayPal', 'update_feed_active'));
                add_action('wp_ajax_gf_select_paypal_form', array('GFPayPal', 'select_paypal_form'));
                add_action('wp_ajax_gf_paypal_confirm_settings', array('GFPayPal', 'confirm_settings'));
                add_action('wp_ajax_gf_paypal_load_notifications', array('GFPayPal', 'load_notifications'));

            }
            else if(RGForms::get("page") == "gf_settings"){
                RGForms::add_settings_page("PayPal", array("GFPayPal", "settings_page"), self::get_base_url() . "/images/paypal_wordpress_icon_32.png");
            }
        }
        else{
            //loading data class
            require_once(self::get_base_path() . "/data.php");

            //handling post submission.
            add_filter("gform_confirmation", array("GFPayPal", "send_to_paypal"), 1000, 4);

            //setting some entry metas
            //add_action("gform_after_submission", array("GFPayPal", "set_entry_meta"), 5, 2);

            add_filter("gform_disable_post_creation", array("GFPayPal", "delay_post"), 10, 3);
            add_filter("gform_disable_user_notification", array("GFPayPal", "delay_autoresponder"), 10, 3);
            add_filter("gform_disable_admin_notification", array("GFPayPal", "delay_admin_notification"), 10, 3);
            add_filter("gform_disable_notification", array("GFPayPal", "delay_notification"), 10, 4);

            // ManageWP premium update filters
            add_filter( 'mwp_premium_update_notification', array('GFPayPal', 'premium_update_push') );
            add_filter( 'mwp_premium_perform_update', array('GFPayPal', 'premium_update') );
        }
    }

    public static function update_feed_active(){
        check_ajax_referer('gf_paypal_update_feed_active','gf_paypal_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFPayPalData::get_feed($id);
        GFPayPalData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //-------------- Automatic upgrade ---------------------------------------


    //Integration with ManageWP
    public static function premium_update_push( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['type'] = 'plugin';
            $plugin_data['slug'] = self::$path;
            $plugin_data['new_version'] = isset($update['version']) ? $update['version'] : false ;
            $premium_update[] = $plugin_data;
        }

        return $premium_update;
    }

    //Integration with ManageWP
    public static function premium_update( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['slug'] = self::$path;
            $plugin_data['type'] = 'plugin';
            $plugin_data['url'] = isset($update["url"]) ? $update["url"] : false; // OR provide your own callback function for managing the update

            array_push($premium_update, $plugin_data);
        }
        return $premium_update;
    }

    public static function flush_version_info(){
        if(!class_exists("RGPayPalUpgrade"))
            require_once("plugin-upgrade.php");

        RGPayPalUpgrade::set_version_info(false);
    }

    public static function plugin_row(){
    	if(!class_exists("RGPayPalUpgrade"))
            require_once("plugin-upgrade.php");
            
        if(!self::is_gravityforms_supported()){
            $message = sprintf(__("Gravity Forms " . self::$min_gravityforms_version . " is required. Activate it now or %spurchase it today!%s", "gravityformspaypal"), "<a href='http://www.gravityforms.com'>", "</a>");
            RGPayPalUpgrade::display_plugin_message($message, true);
        }
        else{
            $version_info = RGPayPalUpgrade::get_version_info(self::$slug, self::get_key(), self::$version, true );

            if(!$version_info["is_valid_key"]){
                $new_version = version_compare(self::$version, $version_info["version"], '<') ? __('There is a new version of Gravity Forms PayPal Add-On available.', 'gravityformspaypal') .' <a class="thickbox" title="Gravity Forms PayPal Add-On" href="plugin-install.php?tab=plugin-information&plugin=' . self::$slug . '&TB_iframe=true&width=640&height=808">'. sprintf(__('View version %s Details', 'gravityformspaypal'), $version_info["version"]) . '</a>. ' : '';
                $message = $new_version . sprintf(__('%sRegister%s your copy of Gravity Forms to receive access to automatic upgrades and support. Need a license key? %sPurchase one now%s.', 'gravityformspaypal'), '<a href="admin.php?page=gf_settings">', '</a>', '<a href="http://www.gravityforms.com">', '</a>') . '</div></td>';
                RGPayPalUpgrade::display_plugin_message($message);
            }
        }
    }

    //Displays current version details on Plugin's page
    public static function display_changelog(){
        if($_REQUEST["plugin"] != self::$slug)
            return;

        //loading upgrade lib
        if(!class_exists("RGPayPalUpgrade"))
            require_once("plugin-upgrade.php");

        RGPayPalUpgrade::display_changelog(self::$slug, self::get_key(), self::$version);
    }

    public static function check_update($update_plugins_option){
        if(!class_exists("RGPayPalUpgrade"))
            require_once("plugin-upgrade.php");

        return RGPayPalUpgrade::check_update(self::$path, self::$slug, self::$url, self::$slug, self::get_key(), self::$version, $update_plugins_option);
    }

    private static function get_key(){
        if(self::is_gravityforms_supported())
            return GFCommon::get_key();
        else
            return "";
    }
    //------------------------------------------------------------------------

    //Creates PayPal left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_paypal");
        if(!empty($permission))
            $menus[] = array("name" => "gf_paypal", "label" => __("PayPal", "gravityformspaypal"), "callback" =>  array("GFPayPal", "paypal_page"), "permission" => $permission);

        return $menus;
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){
        if(get_option("gf_paypal_version") != self::$version)
            GFPayPalData::update_table();

        update_option("gf_paypal_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $paypal_tooltips = array(
            "paypal_email_address" => "<h6>" . __("PayPal Email Address", "gravityformspaypal") . "</h6>" . __("Enter the PayPal email address where payment should be received.", "gravityformspaypal"),
            "paypal_mode" => "<h6>" . __("Mode", "gravityformspaypal") . "</h6>" . __("Select Production to receive live payments. Select Test for testing purposes when using the PayPal development sandbox.", "gravityformspaypal"),
            "paypal_transaction_type" => "<h6>" . __("Transaction Type", "gravityformspaypal") . "</h6>" . __("Select which PayPal transaction type should be used. Products and Services, Donations or Subscription.", "gravityformspaypal"),
            "paypal_gravity_form" => "<h6>" . __("Gravity Form", "gravityformspaypal") . "</h6>" . __("Select which Gravity Forms you would like to integrate with PayPal.", "gravityformspaypal"),
            "paypal_customer" => "<h6>" . __("Customer", "gravityformspaypal") . "</h6>" . __("Map your Form Fields to the available PayPal customer information fields.", "gravityformspaypal"),
            "paypal_page_style" => "<h6>" . __("Page Style", "gravityformspaypal") . "</h6>" . __("This option allows you to select which PayPal page style should be used if you have setup a custom payment page style with PayPal.", "gravityformspaypal"),
            "paypal_continue_button_label" => "<h6>" . __("Continue Button Label", "gravityformspaypal") . "</h6>" . __("Enter the text that should appear on the continue button once payment has been completed via PayPal.", "gravityformspaypal"),
            "paypal_cancel_url" => "<h6>" . __("Cancel URL", "gravityformspaypal") . "</h6>" . __("Enter the URL the user should be sent to should they cancel before completing their PayPal payment.", "gravityformspaypal"),
            "paypal_options" => "<h6>" . __("Options", "gravityformspaypal") . "</h6>" . __("Turn on or off the available PayPal checkout options.", "gravityformspaypal"),
            "paypal_recurring_amount" => "<h6>" . __("Recurring Amount", "gravityformspaypal") . "</h6>" . __("Select which field determines the recurring payment amount, or select 'Form Total' to use the total of all pricing fields as the recurring amount.", "gravityformspaypal"),
            "paypal_billing_cycle" => "<h6>" . __("Billing Cycle", "gravityformspaypal") . "</h6>" . __("Select your billing cycle.  This determines how often the recurring payment should occur.", "gravityformspaypal"),
            "paypal_recurring_times" => "<h6>" . __("Recurring Times", "gravityformspaypal") . "</h6>" . __("Select how many times the recurring payment should be made.  The default is to bill the customer until the subscription is canceled.", "gravityformspaypal"),
            "paypal_trial_period_enable" => "<h6>" . __("Trial Period", "gravityformspaypal") . "</h6>" . __("Enable a trial period.  The users recurring payment will not begin until after this trial period.", "gravityformspaypal"),
            "paypal_trial_amount" => "<h6>" . __("Trial Amount", "gravityformspaypal") . "</h6>" . __("Enter the trial period amount or leave it blank for a free trial.", "gravityformspaypal"),
            "paypal_trial_period" => "<h6>" . __("Trial Period", "gravityformspaypal") . "</h6>" . __("Select the trial period length.", "gravityformspaypal"),
            "paypal_conditional" => "<h6>" . __("PayPal Condition", "gravityformspaypal") . "</h6>" . __("When the PayPal condition is enabled, form submissions will only be sent to PayPal when the condition is met. When disabled all form submissions will be sent to PayPal.", "gravityformspaypal"),
            "paypal_edit_payment_amount" => "<h6>" . __("Amount", "gravityformspaypal") . "</h6>" . __("Enter the amount the user paid for this transaction.", "gravityformspaypal"),
            "paypal_edit_payment_date" => "<h6>" . __("Date", "gravityformspaypal") . "</h6>" . __("Enter the date of this transaction.", "gravityformspaypal"),
            "paypal_edit_payment_transaction_id" => "<h6>" . __("Transaction ID", "gravityformspaypal") . "</h6>" . __("The transacation id is returned from PayPal and uniquely identifies this payment.", "gravityformspaypal"),
            "paypal_edit_payment_status" => "<h6>" . __("Status", "gravityformspaypal") . "</h6>" . __("Set the payment status. This status can only be altered if not currently set to Approved and not a subscription.", "gravityformspaypal")
        );
        return array_merge($tooltips, $paypal_tooltips);
    }

    public static function delay_post($is_disabled, $form, $lead){
    //loading data class
    require_once(self::get_base_path() . "/data.php");

    $config = self::get_active_config($form);
    if(!$config)
        return $is_disabled;

    if(!self::has_paypal_condition($form, $config) || !self::has_payment($form, $lead, $config)){
        return $is_disabled;
    }

    return $config["meta"]["delay_post"] == true;
}

    //Kept for backwards compatibility
    public static function delay_admin_notification($is_disabled, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config || !self::has_payment($form, $lead, $config))
            return $is_disabled;

        return isset($config["meta"]["delay_notification"]) ? $config["meta"]["delay_notification"] == true : $is_disabled;
    }

    //Kept for backwards compatibility
    public static function delay_autoresponder($is_disabled, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config || !self::has_payment($form, $lead, $config))
            return $is_disabled;

        return isset($config["meta"]["delay_autoresponder"]) ? $config["meta"]["delay_autoresponder"] == true : $is_disabled;
    }

    public static function delay_notification($is_disabled, $notification, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config || !self::has_payment($form, $lead, $config)){
            return $is_disabled;
        }

        $selected_notifications = is_array(rgar($config["meta"], "selected_notifications")) ? rgar($config["meta"], "selected_notifications") : array();

        return isset($config["meta"]["delay_notifications"]) && in_array($notification["id"], $selected_notifications) ? true : $is_disabled;
    }

    public static function has_payment($form, $entry, $paypal_config){

        $products = GFCommon::get_product_fields($form, $entry, true);
        $recurring_field = rgar($paypal_config["meta"], "recurring_amount_field");
        $total = 0;
        foreach($products["products"] as $id => $product){

            if($paypal_config["meta"]["type"] != "subscription" || $recurring_field == $id || $recurring_field == "all"){
                $price = GFCommon::to_number($product["price"]);
                if(is_array(rgar($product,"options"))){
                    foreach($product["options"] as $option){
                        $price += GFCommon::to_number($option["price"]);
                    }
                }

                $total += $price * $product['quantity'];
            }
        }

        if($recurring_field == "all" && !empty($products["shipping"]["price"]))
            $total += floatval($products["shipping"]["price"]);

        return $total > 0;
    }


    private static function get_selected_notifications($config, $form){
        $selected_notifications = is_array(rgar($config['meta'], 'selected_notifications')) ? rgar($config['meta'], 'selected_notifications') : array();

        if(empty($selected_notifications)){
            //populating selected notifications so that their delayed notification settings get carried over
            //to the new structure when upgrading to the new PayPal Add-On
            if(!rgempty("delay_autoresponder", $config['meta'])){
                $user_notification = self::get_notification_by_type($form, "user");
                if($user_notification)
                    $selected_notifications[] = $user_notification["id"];
            }

            if(!rgempty("delay_notification", $config['meta'])){
                $admin_notification = self::get_notification_by_type($form, "admin");
                if($admin_notification)
                    $selected_notifications[] = $admin_notification["id"];
            }
        }

        return $selected_notifications;
    }

    private static function get_notification_by_type($form, $notification_type){
        if(!is_array($form["notifications"]))
            return false;

        foreach($form["notifications"] as $notification){
            if($notification["type"] == $notification_type)
                return $notification;
        }

        return false;

    }

    public static function paypal_page(){
        $view = rgget("view");
        if($view == "edit")
            self::edit_page(rgget("id"));
        else if($view == "stats")
            self::stats_page(rgget("id"));
        else
            self::list_page();
    }

    //Displays the paypal feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("PayPal Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravityformspaypal"));
        }

        if(rgpost('action') == "delete"){
            check_admin_referer("list_action", "gf_paypal_list");

            $id = absint($_POST["action_argument"]);
            GFPayPalData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravityformspaypal") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_paypal_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFPayPalData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravityformspaypal") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <img alt="<?php _e("PayPal Transactions", "gravityformspaypal") ?>" src="<?php echo self::get_base_url()?>/images/paypal_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php
            _e("PayPal Forms", "gravityformspaypal");

            if(get_option("gf_paypal_configured")){
                ?>
                <a class="button add-new-h2" href="admin.php?page=gf_paypal&view=edit&id=0"><?php _e("Add New", "gravityformspaypal") ?></a>
                <?php
            }
            ?>
            </h2>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_paypal_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravityformspaypal") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravityformspaypal") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravityformspaypal") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravityformspaypal") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravityformspaypal") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspaypal") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspaypal") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformspaypal") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspaypal") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformspaypal") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php


                        $settings = GFPayPalData::get_feeds();
                        if(!get_option("gf_paypal_configured")){
                            ?>
                            <tr>
                                <td colspan="3" style="padding:20px;">
                                    <?php echo sprintf(__("To get started, please configure your %sPayPal Settings%s.", "gravityformspaypal"), '<a href="admin.php?page=gf_settings&addon=PayPal">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        else if(is_array($settings) && sizeof($settings) > 0){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravityformspaypal") : __("Inactive", "gravityformspaypal");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravityformspaypal") : __("Inactive", "gravityformspaypal");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_paypal&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravityformspaypal") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="<?php _e("Edit", "gravityformspaypal")?>" href="admin.php?page=gf_paypal&view=edit&id=<?php echo $setting["id"] ?>" ><?php _e("Edit", "gravityformspaypal") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Stats", "gravityformspaypal")?>" href="admin.php?page=gf_paypal&view=stats&id=<?php echo $setting["id"] ?>"><?php _e("Stats", "gravityformspaypal") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Entries", "gravityformspaypal")?>" href="admin.php?page=gf_entries&view=entries&id=<?php echo $setting["form_id"] ?>"><?php _e("Entries", "gravityformspaypal") ?></a>
                                            |
                                            </span>
                                            <span class="trash">
                                            <a title="<?php _e("Delete", "gravityformspaypal") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravityformspaypal") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspaypal") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravityformspaypal")?></a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date">
                                        <?php
                                            switch($setting["meta"]["type"]){
                                                case "product" :
                                                    _e("Product and Services", "gravityformspaypal");
                                                break;

                                                case "donation" :
                                                    _e("Donation", "gravityformspaypal");
                                                break;

                                                case "subscription" :
                                                    _e("Subscription", "gravityformspaypal");
                                                break;
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(__("You don't have any PayPal feeds configured. Let's go %screate one%s!", "gravityformspaypal"), '<a href="admin.php?page=gf_paypal&view=edit&id=0">', "</a>"); ?>
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
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravityformspaypal") ?>').attr('alt', '<?php _e("Inactive", "gravityformspaypal") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravityformspaypal") ?>').attr('alt', '<?php _e("Active", "gravityformspaypal") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_paypal_update_feed_active" );
                mysack.setVar( "gf_paypal_update_feed_active", "<?php echo wp_create_nonce("gf_paypal_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravityformspaypal" ) ?>' )};
                mysack.runAJAX();

                return true;
            }


        </script>
        <?php
    }

    public static function load_notifications(){
        $form_id = $_POST["form_id"];
        $form = RGFormsModel::get_form_meta($form_id);
        $notifications = array();
        if(is_array(rgar($form, "notifications"))){
            foreach($form["notifications"] as $notification){
                $notifications[] = array("name" => $notification["name"], "id" => $notification["id"]);
            }
        }
        die(json_encode($notifications));
    }

    public static function confirm_settings(){
        update_option("gf_paypal_configured", $_POST["is_confirmed"]);
    }

    public static function settings_page(){

        if(rgpost("uninstall")){
            check_admin_referer("uninstall", "gf_paypal_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms PayPal Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformspaypal")?></div>
            <?php
            return;
        }
        $is_configured = get_option("gf_paypal_configured");

        ?>
        <form action="" method="post">
            <?php wp_nonce_field("update", "gf_paypal_update") ?>
            <h3><?php _e("PayPal Settings", "gravityformspaypal") ?></h3>
            <p style="text-align: left;">
                <?php _e("Gravity Forms requires IPN to be enabled on your PayPal account. Follow the following steps to confirm IPN is enabled.", "gravityformspaypal") ?>
            </p>

            <ul>
                <li><?php echo sprintf(__("Navigate to your PayPal %sIPN Settings page.%s", "gravityformspaypal"), "<a href='https://www.paypal.com/us/cgi-bin/webscr?cmd=_profile-ipn-notify' target='_blank'>" , "</a>") ?></li>
                <li><?php _e("If IPN is already enabled, you will see your current IPN settings along with a button to turn off IPN. If that is the case, just check the confirmation box below and you are ready to go!", "gravityformspaypal") ?></li>
                <li><?php _e("If IPN is not enabled, click the 'Choose IPN Settings' button.", "gravityformspaypal") ?></li>
                <li><?php echo sprintf(__("Click the box to enable IPN and enter the following Notification URL: %s", "gravityformspaypal"), "<strong>" . add_query_arg("page", "gf_paypal_ipn", get_bloginfo("url") . "/") . "</strong>") ?></li>
            </ul>
            <br/>
            <input type="checkbox" name="gf_paypal_configured" id="gf_paypal_configured" onclick="confirm_settings()" <?php echo $is_configured ? "checked='checked'" : ""?>/>
            <label for="gf_paypal_configured" class="inline"><?php _e("Confirm that you have configured your PayPal account to enable IPN", "gravityformspaypal") ?></label>
            <script type="text/javascript">
                function confirm_settings(){
                    var confirmed = jQuery("#gf_paypal_configured").is(":checked") ? 1 : 0;
                    jQuery.post(ajaxurl, {action:"gf_paypal_confirm_settings", is_confirmed: confirmed});
                }
            </script>

        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_paypal_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_paypal_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall PayPal Add-On", "gravityformspaypal") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL PayPal Feeds.", "gravityformspaypal") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall PayPal Add-On", "gravityformspaypal") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL PayPal Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityformspaypal") . '\');"/>';
                    echo apply_filters("gform_paypal_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }

    private static function get_product_field_options($productFields, $selectedValue){
        $options = "<option value=''>" . __("Select a product", "gravityformspaypal") . "</option>";
        foreach($productFields as $field){
            $label = GFCommon::truncate_middle($field["label"], 30);
            $selected = $selectedValue == $field["id"] ? "selected='selected'" : "";
            $options .= "<option value='{$field["id"]}' {$selected}>{$label}</option>";
        }

        return $options;
    }

    private static function stats_page(){
        ?>
        <style>
          .paypal_graph_container{clear:both; padding-left:5px; min-width:789px; margin-right:50px;}
        .paypal_message_container{clear: both; padding-left:5px; text-align:center; padding-top:120px; border: 1px solid #CCC; background-color: #FFF; width:100%; height:160px;}
        .paypal_summary_container {margin:30px 60px; text-align: center; min-width:740px; margin-left:50px;}
        .paypal_summary_item {width:160px; background-color: #FFF; border: 1px solid #CCC; padding:14px 8px; margin:6px 3px 6px 0; display: -moz-inline-stack; display: inline-block; zoom: 1; *display: inline; text-align:center;}
        .paypal_summary_value {font-size:20px; margin:5px 0; font-family:Georgia,"Times New Roman","Bitstream Charter",Times,serif}
        .paypal_summary_title {}
        #paypal_graph_tooltip {border:4px solid #b9b9b9; padding:11px 0 0 0; background-color: #f4f4f4; text-align:center; -moz-border-radius: 4px; -webkit-border-radius: 4px; border-radius: 4px; -khtml-border-radius: 4px;}
        #paypal_graph_tooltip .tooltip_tip {width:14px; height:14px; background-image:url(<?php echo self::get_base_url() ?>/images/tooltip_tip.png); background-repeat: no-repeat; position: absolute; bottom:-14px; left:68px;}

        .paypal_tooltip_date {line-height:130%; font-weight:bold; font-size:13px; color:#21759B;}
        .paypal_tooltip_sales {line-height:130%;}
        .paypal_tooltip_revenue {line-height:130%;}
            .paypal_tooltip_revenue .paypal_tooltip_heading {}
            .paypal_tooltip_revenue .paypal_tooltip_value {}
            .paypal_trial_disclaimer {clear:both; padding-top:20px; font-size:10px;}
        </style>
        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/flot/jquery.flot.min.js"></script>
        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/js/currency.js"></script>

        <div class="wrap">
            <img alt="<?php _e("PayPal", "gravityformspaypal") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/paypal_wordpress_icon_32.png"/>
            <h2><?php _e("PayPal Stats", "gravityformspaypal") ?></h2>

            <form method="post" action="">
                <ul class="subsubsub">
                    <li><a class="<?php echo (!RGForms::get("tab") || RGForms::get("tab") == "daily") ? "current" : "" ?>" href="?page=gf_paypal&view=stats&id=<?php echo $_GET["id"] ?>"><?php _e("Daily", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "weekly" ? "current" : ""?>" href="?page=gf_paypal&view=stats&id=<?php echo $_GET["id"] ?>&tab=weekly"><?php _e("Weekly", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "monthly" ? "current" : ""?>" href="?page=gf_paypal&view=stats&id=<?php echo $_GET["id"] ?>&tab=monthly"><?php _e("Monthly", "gravityforms"); ?></a></li>
                </ul>
                <?php
                $config = GFPayPalData::get_feed(RGForms::get("id"));

                switch(RGForms::get("tab")){
                    case "monthly" :
                        $chart_info = self::monthly_chart_info($config);
                    break;

                    case "weekly" :
                        $chart_info = self::weekly_chart_info($config);
                    break;

                    default :
                        $chart_info = self::daily_chart_info($config);
                    break;
                }

                if(!$chart_info["series"]){
                    ?>
                    <div class="paypal_message_container"><?php _e("No payments have been made yet.", "gravityformspaypal") ?> <?php echo $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"]) ? " **" : ""?></div>
                    <?php
                }
                else{
                    ?>
                    <div class="paypal_graph_container">
                        <div id="graph_placeholder" style="width:100%;height:300px;"></div>
                    </div>

                    <script type="text/javascript">
                        var paypal_graph_tooltips = <?php echo $chart_info["tooltips"] ?>;

                        jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        jQuery(window).resize(function(){
                            jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        });

                        var previousPoint = null;
                        jQuery("#graph_placeholder").bind("plothover", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        jQuery("#graph_placeholder").bind("plotclick", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        function startShowTooltip(item){
                            if (item) {
                                if (!previousPoint || previousPoint[0] != item.datapoint[0]) {
                                    previousPoint = item.datapoint;

                                    jQuery("#paypal_graph_tooltip").remove();
                                    var x = item.datapoint[0].toFixed(2),
                                        y = item.datapoint[1].toFixed(2);

                                    showTooltip(item.pageX, item.pageY, paypal_graph_tooltips[item.dataIndex]);
                                }
                            }
                            else {
                                jQuery("#paypal_graph_tooltip").remove();
                                previousPoint = null;
                            }
                        }

                        function showTooltip(x, y, contents) {
                            jQuery('<div id="paypal_graph_tooltip">' + contents + '<div class="tooltip_tip"></div></div>').css( {
                                position: 'absolute',
                                display: 'none',
                                opacity: 0.90,
                                width:'150px',
                                height:'<?php echo $config["meta"]["type"] == "subscription" ? "75px" : "60px" ;?>',
                                top: y - <?php echo $config["meta"]["type"] == "subscription" ? "100" : "89" ;?>,
                                left: x - 79
                            }).appendTo("body").fadeIn(200);
                        }


                        function convertToMoney(number){
                            var currency = getCurrentCurrency();
                            return currency.toMoney(number);
                        }
                        function formatWeeks(number){
                            number = number + "";
                            return "<?php _e("Week ", "gravityformspaypal") ?>" + number.substring(number.length-2);
                        }

                        function getCurrentCurrency(){
                            <?php
                            if(!class_exists("RGCurrency"))
                                require_once(ABSPATH . "/" . PLUGINDIR . "/gravityforms/currency.php");

                            $current_currency = RGCurrency::get_currency(GFCommon::get_currency());
                            ?>
                            var currency = new Currency(<?php echo GFCommon::json_encode($current_currency)?>);
                            return currency;
                        }
                    </script>
                <?php
                }
                $payment_totals = RGFormsModel::get_form_payment_totals($config["form_id"]);
                $transaction_totals = GFPayPalData::get_transaction_totals($config["form_id"]);

                switch($config["meta"]["type"]){
                    case "product" :
                        $total_sales = $payment_totals["orders"];
                        $sales_label = __("Total Orders", "gravityformspaypal");
                    break;

                    case "donation" :
                        $total_sales = $payment_totals["orders"];
                        $sales_label = __("Total Donations", "gravityformspaypal");
                    break;

                    case "subscription" :
                        $total_sales = $payment_totals["active"];
                        $sales_label = __("Active Subscriptions", "gravityformspaypal");
                    break;
                }

                $total_revenue = empty($transaction_totals["payment"]["revenue"]) ? 0 : $transaction_totals["payment"]["revenue"];
                ?>
                <div class="paypal_summary_container">
                    <div class="paypal_summary_item">
                        <div class="paypal_summary_title"><?php _e("Total Revenue", "gravityformspaypal")?></div>
                        <div class="paypal_summary_value"><?php echo GFCommon::to_money($total_revenue) ?></div>
                    </div>
                    <div class="paypal_summary_item">
                        <div class="paypal_summary_title"><?php echo $chart_info["revenue_label"]?></div>
                        <div class="paypal_summary_value"><?php echo $chart_info["revenue"] ?></div>
                    </div>
                    <div class="paypal_summary_item">
                        <div class="paypal_summary_title"><?php echo $sales_label?></div>
                        <div class="paypal_summary_value"><?php echo $total_sales ?></div>
                    </div>
                    <div class="paypal_summary_item">
                        <div class="paypal_summary_title"><?php echo $chart_info["sales_label"] ?></div>
                        <div class="paypal_summary_value"><?php echo $chart_info["sales"] ?></div>
                    </div>
                </div>
                <?php
                if(!$chart_info["series"] && $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"])){
                    ?>
                    <div class="paypal_trial_disclaimer"><?php _e("** Free trial transactions will only be reflected in the graph after the first payment is made (i.e. after trial period ends)", "gravityformspaypal") ?></div>
                    <?php
                }
                ?>
            </form>
        </div>
        <?php
    }
    private function get_graph_timestamp($local_datetime){
        $local_timestamp = mysql2date("G", $local_datetime); //getting timestamp with timezone adjusted
        $local_date_timestamp = mysql2date("G", gmdate("Y-m-d 23:59:59", $local_timestamp)); //setting time portion of date to midnight (to match the way Javascript handles dates)
        $timestamp = ($local_date_timestamp - (24 * 60 * 60) + 1) * 1000; //adjusting timestamp for Javascript (subtracting a day and transforming it to milliseconds
        return $timestamp;
    }

    private static function matches_current_date($format, $js_timestamp){
        $target_date = $format == "YW" ? $js_timestamp : date($format, $js_timestamp / 1000);

        $current_date = gmdate($format, GFCommon::get_local_timestamp(time()));
        return $target_date == $current_date;
    }

    private static function daily_chart_info($config){
        global $wpdb;

        $tz_offset = self::get_mysql_tz_offset();

        $results = $wpdb->get_results("SELECT CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "') as date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                        FROM {$wpdb->prefix}rg_lead l
                                        INNER JOIN {$wpdb->prefix}rg_paypal_transaction t ON l.id = t.entry_id
                                        WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                        GROUP BY date(date)
                                        ORDER BY payment_date desc
                                        LIMIT 30");

        $sales_today = 0;
        $revenue_today = 0;
        $tooltips = "";

        if(!empty($results)){

            $data = "[";

            foreach($results as $result){
                $timestamp = self::get_graph_timestamp($result->date);
                if(self::matches_current_date("Y-m-d", $timestamp)){
                    $sales_today += $result->new_sales;
                    $revenue_today += $result->amount_sold;
                }
                $data .="[{$timestamp},{$result->amount_sold}],";

                if($config["meta"]["type"] == "subscription"){
                    $sales_line = " <div class='paypal_tooltip_subscription'><span class='paypal_tooltip_heading'>" . __("New Subscriptions", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . $result->new_sales . "</span></div><div class='paypal_tooltip_subscription'><span class='paypal_tooltip_heading'>" . __("Renewals", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . $result->renewals . "</span></div>";
                }
                else{
                    $sales_line = "<div class='paypal_tooltip_sales'><span class='paypal_tooltip_heading'>" . __("Orders", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . $result->new_sales . "</span></div>";
                }

                $tooltips .= "\"<div class='paypal_tooltip_date'>" . GFCommon::format_date($result->date, false, "", false) . "</div>{$sales_line}<div class='paypal_tooltip_revenue'><span class='paypal_tooltip_heading'>" . __("Revenue", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
            }
            $data = substr($data, 0, strlen($data)-1);
            $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
            $data .="]";

            $series = "[{data:" . $data . "}]";
            $month_names = self::get_chart_month_names();
            $options ="
            {
                xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %d', minTickSize:[1, 'day']},
                yaxis: {tickFormatter: convertToMoney},
                bars: {show:true, align:'right', barWidth: (24 * 60 * 60 * 1000) - 10000000},
                colors: ['#a3bcd3', '#14568a'],
                grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
            }";
        }
        switch($config["meta"]["type"]){
            case "product" :
                $sales_label = __("Orders Today", "gravityformspaypal");
            break;

            case "donation" :
                $sales_label = __("Donations Today", "gravityformspaypal");
            break;

            case "subscription" :
                $sales_label = __("Subscriptions Today", "gravityformspaypal");
            break;
        }
        $revenue_today = GFCommon::to_money($revenue_today);
        return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue Today", "gravityformspaypal"), "revenue" => $revenue_today, "sales_label" => $sales_label, "sales" => $sales_today);
    }

    private static function weekly_chart_info($config){
            global $wpdb;

            $tz_offset = self::get_mysql_tz_offset();

            $results = $wpdb->get_results("SELECT yearweek(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "')) week_number, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_paypal_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            GROUP BY week_number
                                            ORDER BY week_number desc
                                            LIMIT 30");
            $sales_week = 0;
            $revenue_week = 0;
            $tooltips = "";
            if(!empty($results))
            {
                $data = "[";

                foreach($results as $result){
                    if(self::matches_current_date("YW", $result->week_number)){
                        $sales_week += $result->new_sales;
                        $revenue_week += $result->amount_sold;
                    }
                    $data .="[{$result->week_number},{$result->amount_sold}],";

                    if($config["meta"]["type"] == "subscription"){
                        $sales_line = " <div class='paypal_tooltip_subscription'><span class='paypal_tooltip_heading'>" . __("New Subscriptions", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . $result->new_sales . "</span></div><div class='paypal_tooltip_subscription'><span class='paypal_tooltip_heading'>" . __("Renewals", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . $result->renewals . "</span></div>";
                    }
                    else{
                        $sales_line = "<div class='paypal_tooltip_sales'><span class='paypal_tooltip_heading'>" . __("Orders", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . $result->new_sales . "</span></div>";
                    }

                    $tooltips .= "\"<div class='paypal_tooltip_date'>" . substr($result->week_number, 0, 4) . ", " . __("Week",  "gravityformspaypal") . " " . substr($result->week_number, strlen($result->week_number)-2, 2) . "</div>{$sales_line}<div class='paypal_tooltip_revenue'><span class='paypal_tooltip_heading'>" . __("Revenue", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {tickFormatter: formatWeeks, tickDecimals: 0},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth:0.95},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }

            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Week", "gravityformspaypal");
                break;

                case "donation" :
                    $sales_label = __("Donations this Week", "gravityformspaypal");
                break;

                case "subscription" :
                    $sales_label = __("Subscriptions this Week", "gravityformspaypal");
                break;
            }
            $revenue_week = GFCommon::to_money($revenue_week);

            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Week", "gravityformspaypal"), "revenue" => $revenue_week, "sales_label" => $sales_label , "sales" => $sales_week);
    }

    private static function monthly_chart_info($config){
            global $wpdb;
            $tz_offset = self::get_mysql_tz_offset();

            $results = $wpdb->get_results("SELECT date_format(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "'), '%Y-%m-02') date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_paypal_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            group by date
                                            order by date desc
                                            LIMIT 30");

            $sales_month = 0;
            $revenue_month = 0;
            $tooltips = "";
            if(!empty($results)){

                $data = "[";

                foreach($results as $result){
                    $timestamp = self::get_graph_timestamp($result->date);
                    if(self::matches_current_date("Y-m", $timestamp)){
                        $sales_month += $result->new_sales;
                        $revenue_month += $result->amount_sold;
                    }
                    $data .="[{$timestamp},{$result->amount_sold}],";

                    if($config["meta"]["type"] == "subscription"){
                        $sales_line = " <div class='paypal_tooltip_subscription'><span class='paypal_tooltip_heading'>" . __("New Subscriptions", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . $result->new_sales . "</span></div><div class='paypal_tooltip_subscription'><span class='paypal_tooltip_heading'>" . __("Renewals", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . $result->renewals . "</span></div>";
                    }
                    else{
                        $sales_line = "<div class='paypal_tooltip_sales'><span class='paypal_tooltip_heading'>" . __("Orders", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . $result->new_sales . "</span></div>";
                    }

                    $tooltips .= "\"<div class='paypal_tooltip_date'>" . GFCommon::format_date($result->date, false, "F, Y", false) . "</div>{$sales_line}<div class='paypal_tooltip_revenue'><span class='paypal_tooltip_heading'>" . __("Revenue", "gravityformspaypal") . ": </span><span class='paypal_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %y', minTickSize: [1, 'month']},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth: (24 * 60 * 60 * 30 * 1000) - 130000000},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }
            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Month", "gravityformspaypal");
                break;

                case "donation" :
                    $sales_label = __("Donations this Month", "gravityformspaypal");
                break;

                case "subscription" :
                    $sales_label = __("Subscriptions this Month", "gravityformspaypal");
                break;
            }
            $revenue_month = GFCommon::to_money($revenue_month);
            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Month", "gravityformspaypal"), "revenue" => $revenue_month, "sales_label" => $sales_label, "sales" => $sales_month);
    }

    private static function get_mysql_tz_offset(){
        $tz_offset = get_option("gmt_offset");

        //add + if offset starts with a number
        if(is_numeric(substr($tz_offset, 0, 1)))
            $tz_offset = "+" . $tz_offset;

        return $tz_offset . ":00";
    }

    private static function get_chart_month_names(){
        return "['" . __("Jan", "gravityformspaypal") ."','" . __("Feb", "gravityformspaypal") ."','" . __("Mar", "gravityformspaypal") ."','" . __("Apr", "gravityformspaypal") ."','" . __("May", "gravityformspaypal") ."','" . __("Jun", "gravityformspaypal") ."','" . __("Jul", "gravityformspaypal") ."','" . __("Aug", "gravityformspaypal") ."','" . __("Sep", "gravityformspaypal") ."','" . __("Oct", "gravityformspaypal") ."','" . __("Nov", "gravityformspaypal") ."','" . __("Dec", "gravityformspaypal") ."']";
    }

    // Edit Page
    private static function edit_page(){
        ?>
        <style>
            #paypal_submit_container{clear:both;}
            .paypal_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold; width:120px;}
            .paypal_field_cell {padding: 6px 17px 0 0; margin-right:15px;}

            .paypal_validation_error{ background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border:1px dotted #C89797;}
            .paypal_validation_error span {color: red;}
            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0; padding-left:5px;}
            .margin_vertical_30{margin: 30px 0; padding-left:5px;}
            .width-1{width:300px;}
            .gf_paypal_invalid_form{margin-top:30px; background-color:#FFEBE8;border:1px solid #CC0000; padding:10px; width:600px;}
        </style>
        <script type="text/javascript">
            var form = Array();
            function ToggleNotifications(){

                var container = jQuery("#gf_paypal_notification_container");
                var isChecked = jQuery("#gf_paypal_delay_notifications").is(":checked");

                if(isChecked){
                    container.slideDown();
                    var isLoaded = jQuery(".gf_paypal_notification").length > 0
                    if(!isLoaded){
                        container.html("<li><img src='<?php echo self::get_base_url() ?>/images/loading.gif' title='<?php _e("Please wait...", "gravityformspaypal"); ?>'></li>");
                        jQuery.post(ajaxurl, {
                            action: "gf_paypal_load_notifications",
                            form_id: form["id"],
                            },
                            function(response){

                                var notifications = jQuery.parseJSON(response);
                                if(!notifications){
                                    container.html("<li><div class='error' padding='20px;'><?php _e("Notifications could not be loaded. Please try again later or contact support", "gravityformspaypal") ?></div></li>");
                                }
                                else if(notifications.length == 0){
                                    container.html("<li><div class='error' padding='20px;'><?php _e("The form selected does not have any notifications.", "gravityformspaypal") ?></div></li>");
                                }
                                else{
                                    var str = "";
                                    for(var i=0; i<notifications.length; i++){
                                        str += "<li class='gf_paypal_notification'>"
                                            +       "<input type='checkbox' value='" + notifications[i]["id"] + "' name='gf_paypal_selected_notifications[]' id='gf_paypal_selected_notifications' checked='checked' /> "
                                            +       "<label class='inline' for='gf_paypal_selected_notifications'>" + notifications[i]["name"] + "</label>";
                                            +  "</li>";
                                    }
                                    container.html(str);
                                }
                            }
                        );
                    }
                    jQuery(".gf_paypal_notification input").prop("checked", true);
                }
                else{
                    container.slideUp();
                    jQuery(".gf_paypal_notification input").prop("checked", false);
                }
            }
        </script>
        <div class="wrap">
            <img alt="<?php _e("PayPal", "gravityformspaypal") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/paypal_wordpress_icon_32.png"/>
            <h2><?php _e("PayPal Transaction Settings", "gravityformspaypal") ?></h2>

        <?php

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["paypal_setting_id"]) ? $_POST["paypal_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFPayPalData::get_feed($id);
        $is_validation_error = false;
        
        $config["form_id"] = rgpost("gf_paypal_submit") ? absint(rgpost("gf_paypal_form")) : rgar($config, "form_id");

        $form = isset($config["form_id"]) && $config["form_id"] ? $form = RGFormsModel::get_form_meta($config["form_id"]) : array();

        //updating meta information
        if(rgpost("gf_paypal_submit")){
        	
            $config["meta"]["email"] = trim(rgpost("gf_paypal_email"));
            $config["meta"]["mode"] = rgpost("gf_paypal_mode");
            $config["meta"]["type"] = rgpost("gf_paypal_type");
            $config["meta"]["style"] = rgpost("gf_paypal_page_style");
            $config["meta"]["continue_text"] = rgpost("gf_paypal_continue_text");
            $config["meta"]["cancel_url"] = rgpost("gf_paypal_cancel_url");
            $config["meta"]["disable_note"] = rgpost("gf_paypal_disable_note");
            $config["meta"]["disable_shipping"] = rgpost('gf_paypal_disable_shipping');
            $config["meta"]["delay_post"] = rgpost('gf_paypal_delay_post');
            $config["meta"]["update_post_action"] = rgpost('gf_paypal_update_action');

            if(isset($form["notifications"])){
                //new notification settings
                $config["meta"]["delay_notifications"] = rgpost('gf_paypal_delay_notifications');
                $config["meta"]["selected_notifications"] = $config["meta"]["delay_notifications"] ? rgpost('gf_paypal_selected_notifications') : array();

                if(isset($config["meta"]["delay_autoresponder"]))
                    unset($config["meta"]["delay_autoresponder"]);
                if(isset($config["meta"]["delay_notification"]))
                    unset($config["meta"]["delay_notification"]);
            }
            else{
                //legacy notification settings (for backwards compatibility)
                $config["meta"]["delay_autoresponder"] = rgpost('gf_paypal_delay_autoresponder');
                $config["meta"]["delay_notification"] = rgpost('gf_paypal_delay_notification');

                if(isset($config["meta"]["delay_notifications"]))
                    unset($config["meta"]["delay_notifications"]);
                if(isset($config["meta"]["selected_notifications"]))
                    unset($config["meta"]["selected_notifications"]);
            }

            // paypal conditional
            $config["meta"]["paypal_conditional_enabled"] = rgpost('gf_paypal_conditional_enabled');
            $config["meta"]["paypal_conditional_field_id"] = rgpost('gf_paypal_conditional_field_id');
            $config["meta"]["paypal_conditional_operator"] = rgpost('gf_paypal_conditional_operator');
            $config["meta"]["paypal_conditional_value"] = rgpost('gf_paypal_conditional_value');

            //recurring fields
            $config["meta"]["recurring_amount_field"] = rgpost("gf_paypal_recurring_amount");
            $config["meta"]["billing_cycle_number"] = rgpost("gf_paypal_billing_cycle_number");
            $config["meta"]["billing_cycle_type"] = rgpost("gf_paypal_billing_cycle_type");
            $config["meta"]["recurring_times"] = rgpost("gf_paypal_recurring_times");
            $config["meta"]["trial_period_enabled"] = rgpost('gf_paypal_trial_period');
            $config["meta"]["trial_amount"] = rgpost('gf_paypal_trial_amount');
            $config["meta"]["trial_period_number"] = rgpost('gf_paypal_trial_period_number');
            $config["meta"]["trial_period_type"] = rgpost('gf_paypal_trial_period_type');
            $config["meta"]["recurring_retry"] = rgpost('gf_paypal_recurring_retry');

            //-----------------

            $customer_fields = self::get_customer_fields();
            $config["meta"]["customer_fields"] = array();
            foreach($customer_fields as $field){
                $config["meta"]["customer_fields"][$field["name"]] = $_POST["paypal_customer_field_{$field["name"]}"];
            }

            $config = apply_filters('gform_paypal_save_config', $config);

            $is_validation_error = apply_filters("gform_paypal_config_validation", false, $config);

            if(GFCommon::is_valid_email($config["meta"]["email"]) && !$is_validation_error){
                $id = GFPayPalData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravityformspaypal"), "<a href='?page=gf_paypal'>", "</a>") ?></div>
                <?php
            }
            else{
                $is_validation_error = true;
            }

        }


        ?>
        <form method="post" action="">
            <input type="hidden" name="paypal_setting_id" value="<?php echo $id ?>" />

            <div class="margin_vertical_10 <?php echo $is_validation_error ? "paypal_validation_error" : "" ?>">
                <?php
                if($is_validation_error){
                    ?>
                    <span><?php _e('There was an issue saving your feed. Please address the errors below and try again.'); ?></span>
                    <?php
                }
                ?>
            </div> <!-- / validation message -->

            <div class="margin_vertical_10<?php echo ($is_validation_error && !GFCommon::is_valid_email($config["meta"]["email"])) ? " paypal_validation_error" : "" ?>">
                <label class="left_header" for="gf_paypal_email"><?php _e("PayPal Email Address", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_email_address") ?></label>
                <input type="text" name="gf_paypal_email" id="gf_paypal_email" value="<?php echo rgar($config['meta'], 'email') ?>" class="width-1"/>
                <?php
                if($is_validation_error && !GFCommon::is_valid_email($config["meta"]["email"])){
                    ?>
                    <span>Please enter a valid email address.</span>
                    <?php
                }
                ?>
            </div>
            <div class="margin_vertical_10">
                <label class="left_header"><?php _e("Mode", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_mode") ?></label>

                <input type="radio" name="gf_paypal_mode" id="gf_paypal_mode_production" value="production" <?php echo rgar($config['meta'], 'mode') != "test" ? "checked='checked'" : "" ?>/>
                <label class="inline" for="gf_paypal_mode_production"><?php _e("Production", "gravityformspaypal"); ?></label>
                &nbsp;&nbsp;&nbsp;
                <input type="radio" name="gf_paypal_mode" id="gf_paypal_mode_test" value="test" <?php echo rgar($config['meta'], 'mode') == "test" ? "checked='checked'" : "" ?>/>
                <label class="inline" for="gf_paypal_mode_test"><?php _e("Test", "gravityformspaypal"); ?></label>
            </div>
            <div class="margin_vertical_10">
                <label class="left_header" for="gf_paypal_type"><?php _e("Transaction Type", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_transaction_type") ?></label>

                <select id="gf_paypal_type" name="gf_paypal_type" onchange="SelectType(jQuery(this).val());">
                    <option value=""><?php _e("Select a transaction type", "gravityformspaypal") ?></option>
                    <option value="product" <?php echo rgar($config['meta'], 'type') == "product" ? "selected='selected'" : "" ?>><?php _e("Products and Services", "gravityformspaypal") ?></option>
                    <option value="donation" <?php echo rgar($config['meta'], 'type') == "donation" ? "selected='selected'" : "" ?>><?php _e("Donations", "gravityformspaypal") ?></option>
                    <option value="subscription" <?php echo rgar($config['meta'], 'type') == "subscription" ? "selected='selected'" : "" ?>><?php _e("Subscriptions", "gravityformspaypal") ?></option>
                </select>
            </div>
            <div id="paypal_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["type"]) ? "style='display:none;'" : "" ?>>
                <label for="gf_paypal_form" class="left_header"><?php _e("Gravity Form", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_gravity_form") ?></label>

                <select id="gf_paypal_form" name="gf_paypal_form" onchange="SelectForm(jQuery('#gf_paypal_type').val(), jQuery(this).val(), '<?php echo rgar($config, 'id') ?>');">
                    <option value=""><?php _e("Select a form", "gravityformspaypal"); ?> </option>
                    <?php

                    $active_form = rgar($config, 'form_id');
                    $available_forms = GFPayPalData::get_available_forms($active_form);

                    foreach($available_forms as $current_form) {
                        $selected = absint($current_form->id) == rgar($config, 'form_id') ? 'selected="selected"' : '';
                        ?>

                            <option value="<?php echo absint($current_form->id) ?>" <?php echo $selected; ?>><?php echo esc_html($current_form->title) ?></option>

                        <?php
                    }
                    ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFPayPal::get_base_url() ?>/images/loading.gif" id="paypal_wait" style="display: none;"/>

                <div id="gf_paypal_invalid_product_form" class="gf_paypal_invalid_form"  style="display:none;">
                    <?php _e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "gravityformspaypal") ?>
                </div>
                <div id="gf_paypal_invalid_donation_form" class="gf_paypal_invalid_form" style="display:none;">
                    <?php _e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "gravityformspaypal") ?>
                </div>
            </div>
            <div id="paypal_field_group" valign="top" <?php echo empty($config["meta"]["type"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>

                <div id="paypal_field_container_subscription" class="paypal_field_container" valign="top" <?php echo rgars($config, "meta/type") != "subscription" ? "style='display:none;'" : ""?>>
                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypal_recurring_amount"><?php _e("Recurring Amount", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_recurring_amount") ?></label>
                        <select id="gf_paypal_recurring_amount" name="gf_paypal_recurring_amount">
                            <?php echo self::get_product_options($form, rgar($config["meta"],"recurring_amount_field")) ?>
                        </select>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypal_billing_cycle_number"><?php _e("Billing Cycle", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_billing_cycle") ?></label>
                        <select id="gf_paypal_billing_cycle_number" name="gf_paypal_billing_cycle_number">
                            <?php
                            for($i=1; $i<=100; $i++){
                            ?>
                                <option value="<?php echo $i ?>" <?php echo rgar($config["meta"],"billing_cycle_number") == $i ? "selected='selected'" : "" ?>><?php echo $i ?></option>
                            <?php
                            }
                            ?>
                        </select>&nbsp;
                        <select id="gf_paypal_billing_cycle_type" name="gf_paypal_billing_cycle_type" onchange="SetPeriodNumber('#gf_paypal_billing_cycle_number', jQuery(this).val());">
                            <option value="D" <?php echo rgars($config, "meta/billing_cycle_type") == "D" ? "selected='selected'" : "" ?>><?php _e("day(s)", "gravityformspaypal") ?></option>
                            <option value="W" <?php echo rgars($config, "meta/billing_cycle_type") == "W" ? "selected='selected'" : "" ?>><?php _e("week(s)", "gravityformspaypal") ?></option>
                            <option value="M" <?php echo rgars($config, "meta/billing_cycle_type") == "M" || strlen(rgars($config, "meta/billing_cycle_type")) == 0 ? "selected='selected'" : "" ?>><?php _e("month(s)", "gravityformspaypal") ?></option>
                            <option value="Y" <?php echo rgars($config, "meta/billing_cycle_type") == "Y" ? "selected='selected'" : "" ?>><?php _e("year(s)", "gravityformspaypal") ?></option>
                        </select>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypal_recurring_times"><?php _e("Recurring Times", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_recurring_times") ?></label>
                        <select id="gf_paypal_recurring_times" name="gf_paypal_recurring_times">
                            <option value=""><?php _e("Infinite", "gravityformspaypal") ?></option>
                            <?php
                            for($i=2; $i<=52; $i++){
                                $selected = ($i == rgar($config["meta"],"recurring_times")) ? 'selected="selected"' : '';
                                ?>
                                <option value="<?php echo $i ?>" <?php echo $selected; ?>><?php echo $i ?></option>
                                <?php
                            }
                            ?>
                        </select>&nbsp;&nbsp;
                        <input type="checkbox" name="gf_paypal_recurring_retry" id="gf_paypal_recurring_retry" value="1" <?php echo rgars($config, "meta/recurring_retry") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paypal_recurring_retry"><?php _e("Try to bill again after failed attempt.", "gravityformspaypal"); ?></label>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypal_trial_period"><?php _e("Trial Period", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_trial_period_enable") ?></label>
                        <input type="checkbox" name="gf_paypal_trial_period" id="gf_paypal_trial_period" value="1" onclick="if(jQuery(this).is(':checked')) jQuery('#paypal_trial_period_container').show('slow'); else jQuery('#paypal_trial_period_container').hide('slow');" <?php echo rgars($config, "meta/trial_period_enabled") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paypal_trial_period"><?php _e("Enable", "gravityformspaypal"); ?></label>
                    </div>

                    <div id="paypal_trial_period_container" <?php echo rgars($config, "meta/trial_period_enabled") ? "" : "style='display:none;'" ?>>
                        <div class="margin_vertical_10">
                            <label class="left_header" for="gf_paypal_trial_amount"><?php _e("Trial Amount", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_trial_amount") ?></label>
                            <input type="text" name="gf_paypal_trial_amount" id="gf_paypal_trial_amount" value="<?php echo rgars($config, "meta/trial_amount") ?>"/>
                        </div>
                        <div class="margin_vertical_10">
                            <label class="left_header" for="gf_paypal_trial_period_number"><?php _e("Trial Period", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_trial_period") ?></label>
                            <select id="gf_paypal_trial_period_number" name="gf_paypal_trial_period_number">
                                <?php
                                for($i=1; $i<=100; $i++){
                                ?>
                                    <option value="<?php echo $i ?>" <?php echo rgars($config, "meta/trial_period_number") == $i ? "selected='selected'" : "" ?>><?php echo $i ?></option>
                                <?php
                                }
                                ?>
                            </select>&nbsp;
                            <select id="gf_paypal_trial_period_type" name="gf_paypal_trial_period_type" onchange="SetPeriodNumber('#gf_paypal_trial_period_number', jQuery(this).val());">
                                <option value="D" <?php echo rgars($config, "meta/trial_period_type") == "D" ? "selected='selected'" : "" ?>><?php _e("day(s)", "gravityformspaypal") ?></option>
                                <option value="W" <?php echo rgars($config, "meta/trial_period_type") == "W" ? "selected='selected'" : "" ?>><?php _e("week(s)", "gravityformspaypal") ?></option>
                                <option value="M" <?php echo rgars($config, "meta/trial_period_type") == "M" || empty($config["meta"]["trial_period_type"]) ? "selected='selected'" : "" ?>><?php _e("month(s)", "gravityformspaypal") ?></option>
                                <option value="Y" <?php echo rgars($config, "meta/trial_period_type") == "Y" ? "selected='selected'" : "" ?>><?php _e("year(s)", "gravityformspaypal") ?></option>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="margin_vertical_10">
                    <label class="left_header"><?php _e("Customer", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_customer") ?></label>

                    <div id="paypal_customer_fields">
                        <?php
                            if(!empty($form))
                                echo self::get_customer_information($form, $config);
                        ?>
                    </div>
                </div>

                <div class="margin_vertical_10">
                    <label class="left_header" for="gf_paypal_page_style"><?php _e("Page Style", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_page_style") ?></label>
                    <input type="text" name="gf_paypal_page_style" id="gf_paypal_page_style" class="width-1" value="<?php echo rgars($config, "meta/style") ?>"/>
                </div>
                <div class="margin_vertical_10">
                    <label class="left_header" for="gf_paypal_continue_text"><?php _e("Continue Button Label", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_continue_button_label") ?></label>
                    <input type="text" name="gf_paypal_continue_text" id="gf_paypal_continue_text" class="width-1" value="<?php echo rgars($config, "meta/continue_text") ?>"/>
                </div>
                <div class="margin_vertical_10">
                    <label class="left_header" for="gf_paypal_cancel_url"><?php _e("Cancel URL", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_cancel_url") ?></label>
                    <input type="text" name="gf_paypal_cancel_url" id="gf_paypal_cancel_url" class="width-1" value="<?php echo rgars($config, "meta/cancel_url") ?>"/>
                </div>

                <div class="margin_vertical_10">
                    <label class="left_header"><?php _e("Options", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_options") ?></label>

                    <ul style="overflow:hidden;">
                        <li>
                            <input type="checkbox" name="gf_paypal_disable_shipping" id="gf_paypal_disable_shipping" value="1" <?php echo rgar($config['meta'], 'disable_shipping') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_paypal_disable_shipping"><?php _e("Do not prompt buyer to include a shipping address.", "gravityformspaypal"); ?></label>
                        </li>
                        <li>
                            <input type="checkbox" name="gf_paypal_disable_note" id="gf_paypal_disable_note" value="1" <?php echo rgar($config['meta'], 'disable_note') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_paypal_disable_note"><?php _e("Do not prompt buyer to include a note with payment.", "gravityformspaypal"); ?></label>
                        </li>

                        <li id="paypal_delay_notification" <?php echo isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                            <input type="checkbox" name="gf_paypal_delay_notification" id="gf_paypal_delay_notification" value="1" <?php echo rgar($config["meta"], 'delay_notification') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_paypal_delay_notification"><?php _e("Send admin notification only when payment is received.", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_delay_admin_notification") ?></label>
                        </li>
                        <li id="paypal_delay_autoresponder" <?php echo isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                            <input type="checkbox" name="gf_paypal_delay_autoresponder" id="gf_paypal_delay_autoresponder" value="1" <?php echo rgar($config["meta"], 'delay_autoresponder') ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_paypal_delay_autoresponder"><?php _e("Send user notification only when payment is received.", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_delay_user_notification") ?></label>
                        </li>

                        <?php
                        $display_post_fields = !empty($form) ? GFCommon::has_post_field($form["fields"]) : false;
                        ?>
                        <li id="paypal_post_action" <?php echo $display_post_fields ? "" : "style='display:none;'" ?>>
                            <input type="checkbox" name="gf_paypal_delay_post" id="gf_paypal_delay_post" value="1" <?php echo rgar($config["meta"],"delay_post") ? "checked='checked'" : ""?> />
                            <label class="inline" for="gf_paypal_delay_post"><?php _e("Create post only when payment is received.", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_delay_post") ?></label>
                        </li>

                        <li id="paypal_post_update_action" <?php echo $display_post_fields && $config["meta"]["type"] == "subscription" ? "" : "style='display:none;'" ?>>
                            <input type="checkbox" name="gf_paypal_update_post" id="gf_paypal_update_post" value="1" <?php echo rgar($config["meta"],"update_post_action") ? "checked='checked'" : ""?> onclick="var action = this.checked ? 'draft' : ''; jQuery('#gf_paypal_update_action').val(action);" />
                            <label class="inline" for="gf_paypal_update_post"><?php _e("Update Post when subscription is cancelled.", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_update_post") ?></label>
                            <select id="gf_paypal_update_action" name="gf_paypal_update_action" onchange="var checked = jQuery(this).val() ? 'checked' : false; jQuery('#gf_paypal_update_post').attr('checked', checked);">
                                <option value=""></option>
                                <option value="draft" <?php echo rgar($config["meta"],"update_post_action") == "draft" ? "selected='selected'" : ""?>><?php _e("Mark Post as Draft", "gravityformspaypal") ?></option>
                                <option value="delete" <?php echo rgar($config["meta"],"update_post_action") == "delete" ? "selected='selected'" : ""?>><?php _e("Delete Post", "gravityformspaypal") ?></option>
                            </select>
                        </li>

                        <?php do_action("gform_paypal_action_fields", $config, $form) ?>
                    </ul>
                </div>

                <div class="margin_vertical_10" id="gf_paypal_notifications" <?php echo !isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                    <label class="left_header"><?php _e("Notifications", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_notifications") ?></label>
                    <?php
                    $has_delayed_notifications = rgar($config['meta'], 'delay_notifications') || rgar($config['meta'], 'delay_notification') || rgar($config['meta'], 'delay_autoresponder');
                    ?>
                    <div style="overflow:hidden;">
                        <input type="checkbox" name="gf_paypal_delay_notifications" id="gf_paypal_delay_notifications" value="1" onclick="ToggleNotifications();" <?php checked("1", $has_delayed_notifications)?> />
                        <label class="inline" for="gf_paypal_delay_notifications"><?php _e("Send notifications only when payment is received.", "gravityformspaypal"); ?></label>

                        <ul id="gf_paypal_notification_container" style="padding-left:20px; <?php echo $has_delayed_notifications ? "" : "display:none;"?>">
                        <?php
                        if(!empty($form) && is_array($form["notifications"])){
                            $selected_notifications = self::get_selected_notifications($config, $form);

                            foreach($form["notifications"] as $notification){
                                ?>
                                <li class="gf_paypal_notification">
                                    <input type="checkbox" name="gf_paypal_selected_notifications[]" id="gf_paypal_selected_notifications" value="<?php echo $notification["id"]?>" <?php checked(true, in_array($notification["id"], $selected_notifications))?> />
                                    <label class="inline" for="gf_paypal_selected_notifications"><?php echo $notification["name"]; ?></label>
                                </li>
                                <?php
                            }
                        }
                        ?>
                        </ul>
                    </div>
                </div>

                <?php do_action("gform_paypal_add_option_group", $config, $form); ?>

                <div id="gf_paypal_conditional_section" valign="top" class="margin_vertical_10">
                    <label for="gf_paypal_conditional_optin" class="left_header"><?php _e("PayPal Condition", "gravityformspaypal"); ?> <?php gform_tooltip("paypal_conditional") ?></label>

                    <div id="gf_paypal_conditional_option">
                        <table cellspacing="0" cellpadding="0">
                            <tr>
                                <td>
                                    <input type="checkbox" id="gf_paypal_conditional_enabled" name="gf_paypal_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_paypal_conditional_container').fadeIn('fast');} else{ jQuery('#gf_paypal_conditional_container').fadeOut('fast'); }" <?php echo rgar($config['meta'], 'paypal_conditional_enabled') ? "checked='checked'" : ""?>/>
                                    <label for="gf_paypal_conditional_enable"><?php _e("Enable", "gravityformspaypal"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="gf_paypal_conditional_container" <?php echo !rgar($config['meta'], 'paypal_conditional_enabled') ? "style='display:none'" : ""?>>

                                        <div id="gf_paypal_conditional_fields" style="display:none">
                                            <?php _e("Send to PayPal if ", "gravityformspaypal") ?>
                                            <select id="gf_paypal_conditional_field_id" name="gf_paypal_conditional_field_id" class="optin_select" onchange='jQuery("#gf_paypal_conditional_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'>
                                            </select>
                                            <select id="gf_paypal_conditional_operator" name="gf_paypal_conditional_operator">
                                                <option value="is" <?php echo rgar($config['meta'], 'paypal_conditional_operator') == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravityformspaypal") ?></option>
                                                <option value="isnot" <?php echo rgar($config['meta'], 'paypal_conditional_operator') == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravityformspaypal") ?></option>
                                                <option value=">" <?php echo rgar($config['meta'], 'paypal_conditional_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravityformspaypal") ?></option>
                                                <option value="<" <?php echo rgar($config['meta'], 'paypal_conditional_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravityformspaypal") ?></option>
                                                <option value="contains" <?php echo rgar($config['meta'], 'paypal_conditional_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravityformspaypal") ?></option>
                                                <option value="starts_with" <?php echo rgar($config['meta'], 'paypal_conditional_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravityformspaypal") ?></option>
                                                <option value="ends_with" <?php echo rgar($config['meta'], 'paypal_conditional_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravityformspaypal") ?></option>
                                            </select>
                                            <div id="gf_paypal_conditional_value_container" name="gf_paypal_conditional_value_container" style="display:inline;"></div>
                                        </div>

                                        <div id="gf_paypal_conditional_message" style="display:none">
                                            <?php _e("To create a registration condition, your form must have a field supported by conditional logic.", "gravityform") ?>
                                        </div>

                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div> <!-- / paypal conditional -->

                <div id="paypal_submit_container" class="margin_vertical_30">
                    <input type="submit" name="gf_paypal_submit" value="<?php echo empty($id) ? __("  Save  ", "gravityformspaypal") : __("Update", "gravityformspaypal"); ?>" class="button-primary"/>
                    <input type="button" value="<?php _e("Cancel", "gravityformspaypal"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_paypal'" />
                </div>
            </div>
        </form>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function(){
                SetPeriodNumber('#gf_paypal_billing_cycle_number', jQuery("#gf_paypal_billing_cycle_type").val());
                SetPeriodNumber('#gf_paypal_trial_period_number', jQuery("#gf_paypal_trial_period_type").val());
            });

            function SelectType(type){
                jQuery("#paypal_field_group").slideUp();

                jQuery("#paypal_field_group input[type=\"text\"], #paypal_field_group select").val("");
                jQuery("#gf_paypal_trial_period_type, #gf_paypal_billing_cycle_type").val("M");

                jQuery("#paypal_field_group input:checked").attr("checked", false);

                if(type){
                    jQuery("#paypal_form_container").slideDown();
                    jQuery("#gf_paypal_form").val("");
                }
                else{
                    jQuery("#paypal_form_container").slideUp();
                }
            }

            function SelectForm(type, formId, settingId){
                if(!formId){
                    jQuery("#paypal_field_group").slideUp();
                    return;
                }

                jQuery("#paypal_wait").show();
                jQuery("#paypal_field_group").slideUp();

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_paypal_form" );
                mysack.setVar( "gf_select_paypal_form", "<?php echo wp_create_nonce("gf_select_paypal_form") ?>" );
                mysack.setVar( "type", type);
                mysack.setVar( "form_id", formId);
                mysack.setVar( "setting_id", settingId);
                mysack.onError = function() {jQuery("#paypal_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravityformspaypal") ?>' )};
                mysack.runAJAX();

                return true;
            }

            function EndSelectForm(form_meta, customer_fields, recurring_amount_options){

                //setting global form object
                form = form_meta;

                var type = jQuery("#gf_paypal_type").val();

                jQuery(".gf_paypal_invalid_form").hide();
                if( (type == "product" || type =="subscription") && GetFieldsByType(["product"]).length == 0){
                    jQuery("#gf_paypal_invalid_product_form").show();
                    jQuery("#paypal_wait").hide();
                    return;
                }
                else if(type == "donation" && GetFieldsByType(["product", "donation"]).length == 0){
                    jQuery("#gf_paypal_invalid_donation_form").show();
                    jQuery("#paypal_wait").hide();
                    return;
                }

                jQuery(".paypal_field_container").hide();
                jQuery("#paypal_customer_fields").html(customer_fields);
                jQuery("#gf_paypal_recurring_amount").html(recurring_amount_options);

                //displaying delayed post creation setting if current form has a post field
                var post_fields = GetFieldsByType(["post_title", "post_content", "post_excerpt", "post_category", "post_custom_field", "post_image", "post_tag"]);
                if(post_fields.length > 0){
                    jQuery("#paypal_post_action").show();
                }
                else{
                    jQuery("#gf_paypal_delay_post").attr("checked", false);
                    jQuery("#paypal_post_action").hide();
                }

                if(type == "subscription" && post_fields.length > 0){
                    jQuery("#paypal_post_update_action").show();
                }
                else{
                    jQuery("#gf_paypal_update_post").attr("checked", false);
                    jQuery("#paypal_post_update_action").hide();
                }

                SetPeriodNumber('#gf_paypal_billing_cycle_number', jQuery("#gf_paypal_billing_cycle_type").val());
                SetPeriodNumber('#gf_paypal_trial_period_number', jQuery("#gf_paypal_trial_period_type").val());

                //Calling callback functions
                jQuery(document).trigger('paypalFormSelected', [form]);

                jQuery("#gf_paypal_conditional_enabled").attr('checked', false);

                SetPayPalCondition("","");

                if(form["notifications"]){
                    jQuery("#gf_paypal_notifications").show();
                    jQuery("#paypal_delay_autoresponder, #paypal_delay_notification").hide();
                }
                else{
                    jQuery("#paypal_delay_autoresponder, #paypal_delay_notification").show();
                    jQuery("#gf_paypal_notifications").hide();
                }

                jQuery("#paypal_field_container_" + type).show();
                jQuery("#paypal_field_group").slideDown();
                jQuery("#paypal_wait").hide();
            }

            function SetPeriodNumber(element, type){
                var prev = jQuery(element).val();

                var min = 1;
                var max = 0;
                switch(type){
                    case "D" :
                        max = 100;
                    break;
                    case "W" :
                        max = 52;
                    break;
                    case "M" :
                        max = 12;
                    break;
                    case "Y" :
                        max = 5;
                    break;
                }
                var str="";
                for(var i=min; i<=max; i++){
                    var selected = prev == i ? "selected='selected'" : "";
                    str += "<option value='" + i + "' " + selected + ">" + i + "</option>";
                }
                jQuery(element).html(str);
            }

            function GetFieldsByType(types){
                var fields = new Array();
                for(var i=0; i<form["fields"].length; i++){
                    if(IndexOf(types, form["fields"][i]["type"]) >= 0)
                        fields.push(form["fields"][i]);
                }
                return fields;
            }

            function IndexOf(ary, item){
                for(var i=0; i<ary.length; i++)
                    if(ary[i] == item)
                        return i;

                return -1;
            }

        </script>

        <script type="text/javascript">

            // Paypal Conditional Functions

            <?php
            if(!empty($config["form_id"])){
                ?>

                // initilize form object
                form = <?php echo GFCommon::json_encode($form)?> ;

                // initializing registration condition drop downs
                jQuery(document).ready(function(){
                    var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["paypal_conditional_field_id"])?>";
                    var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["paypal_conditional_value"])?>";
                    SetPayPalCondition(selectedField, selectedValue);
                });

                <?php
            }
            ?>

            function SetPayPalCondition(selectedField, selectedValue){

                // load form fields
                jQuery("#gf_paypal_conditional_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#gf_paypal_conditional_field_id").val();
                var checked = jQuery("#gf_paypal_conditional_enabled").attr('checked');

                if(optinConditionField){
                    jQuery("#gf_paypal_conditional_message").hide();
                    jQuery("#gf_paypal_conditional_fields").show();
                    jQuery("#gf_paypal_conditional_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#gf_paypal_conditional_value").val(selectedValue);
                }
                else{
                    jQuery("#gf_paypal_conditional_message").show();
                    jQuery("#gf_paypal_conditional_fields").hide();
                }

                if(!checked) jQuery("#gf_paypal_conditional_container").hide();

            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
					str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "gf_paypal_conditional_value", "name"=> "gf_paypal_conditional_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
				}
				else if(field.choices){
					str += '<select id="gf_paypal_conditional_value" name="gf_paypal_conditional_value" class="optin_select">'


	                for(var i=0; i<field.choices.length; i++){
	                    var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
	                    var isSelected = fieldValue == selectedValue;
	                    var selected = isSelected ? "selected='selected'" : "";
	                    if(isSelected)
	                        isAnySelected = true;

	                    str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
	                }

	                if(!isAnySelected && selectedValue){
	                    str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
	                }
	                str += "</select>";
				}
				else
				{
					selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
					//create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
					str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='gf_paypal_conditional_value' name='gf_paypal_conditional_value' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
				}

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(!text)
                    return "";

                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
			    inputType = field.inputType ? field.inputType : field.type;
			    var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
			                            "post_tags", "post_custom_field", "post_content", "post_excerpt"];

			    var index = jQuery.inArray(inputType, supported_fields);

			    return index >= 0;
			}

        </script>

        <?php

    }

    public static function select_paypal_form(){

        check_ajax_referer("gf_select_paypal_form", "gf_select_paypal_form");

        $type = $_POST["type"];
        $form_id =  intval($_POST["form_id"]);
        $setting_id =  intval($_POST["setting_id"]);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);

        $customer_fields = self::get_customer_information($form);
        $recurring_amount_fields = self::get_product_options($form, "");

        die("EndSelectForm(" . GFCommon::json_encode($form) . ", '" . str_replace("'", "\'", $customer_fields) . "', '" . str_replace("'", "\'", $recurring_amount_fields) . "');");
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_paypal");
        $wp_roles->add_cap("administrator", "gravityforms_paypal_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_paypal", "gravityforms_paypal_uninstall"));
    }

    public static function get_active_config($form){

        require_once(self::get_base_path() . "/data.php");

        $configs = GFPayPalData::get_feed_by_form($form["id"], true);
        if(!$configs)
            return false;

        foreach($configs as $config){
            if(self::has_paypal_condition($form, $config))
                return $config;
        }

        return false;
    }

    public static function send_to_paypal($confirmation, $form, $entry, $ajax){

        // ignore requests that are not the current form's submissions
        if(RGForms::post("gform_submit") != $form["id"])
        {
            return $confirmation;
		}

        $config = self::get_active_config($form);

        if(!$config)
        {
            self::log_debug("NOT sending to PayPal: No PayPal setup was located for form_id = {$form['id']}.");
            return $confirmation;
		}

        // updating entry meta with current feed id
        gform_update_meta($entry["id"], "paypal_feed_id", $config["id"]);

        // updating entry meta with current payment gateway
        gform_update_meta($entry["id"], "payment_gateway", "paypal");

        //updating lead's payment_status to Processing
        RGFormsModel::update_lead_property($entry["id"], "payment_status", 'Processing');

        //Getting Url (Production or Sandbox)
        $url = $config["meta"]["mode"] == "production" ? self::$production_url : self::$sandbox_url;

        $invoice_id = apply_filters("gform_paypal_invoice", "", $form, $entry);

        $invoice = empty($invoice_id) ? "" : "&invoice={$invoice_id}";

        //Current Currency
        $currency = GFCommon::get_currency();

        //Customer fields
        $customer_fields = self::customer_query_string($config, $entry);

        //Page style
        $page_style = !empty($config["meta"]["style"]) ? "&page_style=" . urlencode($config["meta"]["style"]) : "";

        //Continue link text
        $continue_text = !empty($config["meta"]["continue_text"]) ? "&cbt=" . urlencode($config["meta"]["continue_text"]) : "&cbt=" . __("Click here to continue", "gravityformspaypal");

        //If page is HTTPS, set return mode to 2 (meaning PayPal will post info back to page)
        //If page is not HTTPS, set return mode to 1 (meaning PayPal will redirect back to page) to avoid security warning
        //$return_mode = GFCommon::is_ssl() ? "2" : "1";
        $return_mode = "2"; //rm=1 seems to create lots of problems with the redirect back to the site. Defaulting it to 2.

        $return_url = "&return=" . urlencode(self::return_url($form["id"], $entry["id"])) . "&rm={$return_mode}";

        //Cancel URL
        $cancel_url = !empty($config["meta"]["cancel_url"]) ? "&cancel_return=" . urlencode($config["meta"]["cancel_url"]) : "";

        //Don't display note section
        $disable_note = !empty($config["meta"]["disable_note"]) ? "&no_note=1" : "";

        //Don't display shipping section
        $disable_shipping = !empty($config["meta"]["disable_shipping"]) ? "&no_shipping=1" : "";

        //URL that will listen to notifications from PayPal
        $ipn_url = urlencode(get_bloginfo("url") . "/?page=gf_paypal_ipn");

        $business_email = urlencode(trim($config["meta"]["email"]));
        $custom_field = $entry["id"] . "|" . wp_hash($entry["id"]);

        $url .= "?notify_url={$ipn_url}&charset=UTF-8&currency_code={$currency}&business={$business_email}&custom={$custom_field}{$invoice}{$customer_fields}{$page_style}{$continue_text}{$cancel_url}{$disable_note}{$disable_shipping}{$return_url}";
        $query_string = "";

        switch($config["meta"]["type"]){
            case "product" :
                $query_string = self::get_product_query_string($form, $entry);
            break;

            case "donation" :
                $query_string = self::get_donation_query_string($form, $entry);
            break;

            case "subscription" :
                $query_string = self::get_subscription_query_string($config, $form, $entry);
            break;
        }

        $query_string = apply_filters("gform_paypal_query_{$form['id']}", apply_filters("gform_paypal_query", $query_string, $form, $entry), $form, $entry);

        if(!$query_string)
        {
        	self::log_debug("NOT sending to PayPal: The price is either zero or the gform_paypal_query filter was used to remove the querystring that is sent to PayPal.");
            return $confirmation;
		}

        $url .= $query_string;

        $url = apply_filters("gform_paypal_request_{$form['id']}", apply_filters("gform_paypal_request", $url, $form, $entry), $form, $entry);

        self::log_debug("Sending to PayPal: {$url}");

        if(headers_sent() || $ajax){
            $confirmation = "<script>function gformRedirect(){document.location.href='$url';}";
            if(!$ajax)
                $confirmation .="gformRedirect();";
            $confirmation .="</script>";
        }
        else{
            $confirmation = array("redirect" => $url);
        }

        return $confirmation;
    }

    public static function has_paypal_condition($form, $config) {

        $config = $config["meta"];

        $operator = isset($config["paypal_conditional_operator"]) ? $config["paypal_conditional_operator"] : "";
        $field = RGFormsModel::get_field($form, $config["paypal_conditional_field_id"]);

        if(empty($field) || !$config["paypal_conditional_enabled"])
            return true;

        // if conditional is enabled, but the field is hidden, ignore conditional
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array());

        $field_value = RGFormsModel::get_field_value($field, array());

        $is_value_match = RGFormsModel::is_value_match($field_value, $config["paypal_conditional_value"], $operator);
        $go_to_paypal = $is_value_match && $is_visible;

        return  $go_to_paypal;
    }

    public static function get_config($form_id){
        if(!class_exists("GFPayPalData"))
            require_once(self::get_base_path() . "/data.php");

        //Getting paypal settings associated with this transaction
        $config = GFPayPalData::get_feed_by_form($form_id);

        //Ignore IPN messages from forms that are no longer configured with the PayPal add-on
        if(!$config)
            return false;

        return $config[0]; //only one feed per form is supported (left for backwards compatibility)
    }

    public static function get_config_by_entry($entry) {

        if(!class_exists("GFPayPalData"))
            require_once(self::get_base_path() . "/data.php");

        $feed_id = gform_get_meta($entry["id"], "paypal_feed_id");
        $feed = GFPayPalData::get_feed($feed_id);

        return !empty($feed) ? $feed : false;
    }

    public static function maybe_thankyou_page(){

        if(!self::is_gravityforms_supported())
            return;

        if($str = RGForms::get("gf_paypal_return"))
        {
            $str = base64_decode($str);

            parse_str($str, $query);
            if(wp_hash("ids=" . $query["ids"]) == $query["hash"]){
                list($form_id, $lead_id) = explode("|", $query["ids"]);

                $form = RGFormsModel::get_form_meta($form_id);
                $lead = RGFormsModel::get_lead($lead_id);

                if(!class_exists("GFFormDisplay"))
                    require_once(GFCommon::get_base_path() . "/form_display.php");

                $confirmation = GFFormDisplay::handle_confirmation($form, $lead, false);

                if(is_array($confirmation) && isset($confirmation["redirect"])){
                    header("Location: {$confirmation["redirect"]}");
                    exit;
                }

                GFFormDisplay::$submission[$form_id] = array("is_confirmation" => true, "confirmation_message" => $confirmation, "form" => $form, "lead" => $lead);
            }
        }
    }

    public static function process_ipn($wp){

        if(!self::is_gravityforms_supported())
           return;

        //Ignore requests that are not IPN
        if(RGForms::get("page") != "gf_paypal_ipn")
            return;

        self::log_debug("IPN request received. Starting to process...");
        self::log_debug(print_r($_POST, true));

        //Send request to paypal and verify it has not been spoofed
        if(!self::verify_paypal_ipn()){
            self::log_error("IPN request could not be verified by PayPal. Aborting.");
            return;
        }
        self::log_debug("IPN message successfully verified by PayPal");

        //Valid IPN requests must have a custom field
        $custom = RGForms::post("custom");
        if(empty($custom)){
            self::log_error("IPN request does not have a custom field, so it was not created by Gravity Forms. Aborting.");
            return;
        }

        //Getting entry associated with this IPN message (entry id is sent in the "custom" field)
        list($entry_id, $hash) = explode("|", $custom);
        $hash_matches = wp_hash($entry_id) == $hash;
        //Validates that Entry Id wasn't tampered with
        if(!RGForms::post("test_ipn") && !$hash_matches){
            self::log_error("Entry Id verification failed. Hash does not match. Custom field: {$custom}. Aborting.");
            return;
        }

        self::log_debug("IPN message has a valid custom field: {$custom}");

        //$entry_id = RGForms::post("custom");
        $entry = RGFormsModel::get_lead($entry_id);

        //Ignore orphan IPN messages (ones without an entry)
        if(!$entry){
            self::log_error("Entry could not be found. Entry ID: {$entry_id}. Aborting.");
            return;
        }
        self::log_debug("Entry has been found." . print_r($entry, true));

        // config ID is stored in entry via send_to_paypal() function
        $config = self::get_config_by_entry($entry);

        //Ignore IPN messages from forms that are no longer configured with the PayPal add-on
        if(!$config){
            self::log_error("Form no longer is configured with PayPal Addon. Form ID: {$entry["form_id"]}. Aborting.");
            return;
        }
        self::log_debug("Form {$entry["form_id"]} is properly configured.");

        //Only process test messages coming fron SandBox and only process production messages coming from production PayPal
        if( ($config["meta"]["mode"] == "test" && !RGForms::post("test_ipn")) || ($config["meta"]["mode"] == "production" && RGForms::post("test_ipn"))){
            self::log_error("Invalid test/production mode. IPN message mode (test/production) does not match mode configured in the PayPal feed. Configured Mode: {$config["meta"]["mode"]}. IPN test mode: " . RGForms::post("test_ipn"));
            return;
        }

        //Check business email to make sure it matches
        $recipient_email = rgempty("business") ? rgpost("receiver_email") : rgpost("business");
        if(strtolower(trim($recipient_email)) != strtolower(trim($config["meta"]["email"]))){
            self::log_error("PayPal email does not match. Email entered on PayPal feed:" . strtolower(trim($config["meta"]["email"])) . " - Email from IPN message: " . $recipient_email);
            return;
        }

        //Pre IPN processing filter. Allows users to cancel IPN processing
        $cancel = apply_filters("gform_paypal_pre_ipn", false, $_POST, $entry, $config);

        if(!$cancel) {
            self::log_debug( 'Setting payment status...' );
            self::set_payment_status( $config, $entry, rgpost( 'payment_status' ), rgpost( 'txn_type' ), rgpost( 'txn_id' ), rgpost( 'parent_txn_id' ), rgpost( 'subscr_id' ), rgpost( 'mc_gross' ), rgpost( 'pending_reason' ), rgpost( 'reason_code' ) );
            do_action( 'gform_paypal_ipn_' . rgpost( 'txn_type' ), $entry, $config, rgpost( 'payment_status' ), rgpost( 'txn_type' ), rgpost( 'txn_id' ), rgpost( 'parent_txn_id' ), rgpost( 'subscr_id' ), rgpost( 'mc_gross' ), rgpost( 'pending_reason' ), rgpost( 'reason_code' ) );
        }
        else{
            self::log_debug("IPN processing cancelled by the gform_paypal_pre_ipn filter. Aborting.");
        }

        self::log_debug("Before gform_paypal_post_ipn.");
        //Post IPN processing action
        do_action("gform_paypal_post_ipn", $_POST, $entry, $config, $cancel);

        self::log_debug("IPN processing complete.");
    }

    public static function set_payment_status($config, $entry, $status, $transaction_type, $transaction_id, $parent_transaction_id, $subscriber_id, $amount, $pending_reason, $reason){
        global $current_user;
        $user_id = 0;
        $user_name = "System";
        if($current_user && $user_data = get_userdata($current_user->ID)){
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }
        self::log_debug("Payment status: {$status} - Transaction Type: {$transaction_type} - Transaction ID: {$transaction_id} - Parent Transaction: {$parent_transaction_id} - Subscriber ID: {$subscriber_id} - Amount: {$amount} - Pending reason: {$pending_reason} - Reason: {$reason}");
        self::log_debug("Entry: " . print_r($entry, true));

        switch(strtolower($transaction_type)){
            case "subscr_payment" :
                if($entry["payment_status"] != "Active") {
                    self::log_debug("Starting subscription");

                    if(self::is_valid_initial_payment_amount($config, $entry)){
                        self::start_subscription($entry, $subscriber_id, $amount, $user_id, $user_name);
                    }
                    else{
                        self::log_debug("Payment amount does not match subscription amount. Subscription will not be activated.");
                        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment amount (%s) does not match subscription amount. Subscription will not be activated. Transaction Id: %s", "gravityforms"), GFCommon::to_money($amount, $entry["currency"]), $transaction_id));
                    }

                } else {
                    self::log_debug("Payment status is already active, so simply adding a Note");
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription payment has been made. Amount: %s. Transaction Id: %s", "gravityforms"), GFCommon::to_money($amount, $entry["currency"]), $transaction_id));
                }
                self::log_debug("Inserting payment transaction");
                GFPayPalData::insert_transaction($entry["id"], "payment", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
            break;

            case "subscr_signup" :
                $trial_amount = GFCommon::to_number($config["meta"]["trial_amount"]);
                //Starting subscription if there is a free trial period. Otherwise, subscription will be started when payment is received (i.e. sbscr_payment)
                if($entry["payment_status"] != "Active" && $config["meta"]["trial_period_enabled"] && empty($trial_amount)){
                    self::log_debug("Starting subscription");
                    self::start_subscription($entry, $subscriber_id, $amount, $user_id, $user_name);
                }
            break;

            case "subscr_cancel" :
                if($entry["payment_status"] != "Cancelled"){
                    $entry["payment_status"] = "Cancelled";

                    self::log_debug("Cancelling subscription");
                    RGFormsModel::update_lead($entry);
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription has been cancelled. Subscriber Id: %s", "gravityforms"), $subscriber_id));

                    if($config["meta"]["update_post_action"] == "draft" && !empty($entry["post_id"])){
                        $post = get_post($entry["post_id"]);
                        $post->post_status = 'draft';
                        wp_update_post($post);
                        self::log_debug("Marking associated post as a Draft");
                    }
                    if($config["meta"]["update_post_action"] == "delete" && !empty($entry["post_id"])){
                        wp_delete_post($entry["post_id"]);
                        self::log_debug("Deleting associated post");
                    }

                    do_action("gform_subscription_canceled", $entry, $config, $transaction_id);
                }
            break;

            case "subscr_eot" :
                if($entry["payment_status"] != "Expired"){
                    $entry["payment_status"] = "Expired";

                    self::log_debug("Setting entry as expired");
                    RGFormsModel::update_lead($entry);
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription has expired. Subscriber Id: %s", "gravityforms"), $subscriber_id));
                }
            break;

            case "subscr_failed" :
                if($entry["payment_status"] != "Failed"){
                    $entry["payment_status"] = "Failed";

                    self::log_debug("Marking entry as Failed");
                    RGFormsModel::update_lead($entry);
                    RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription signup has failed. Subscriber Id: %s", "gravityforms"), $subscriber_id));
                }
            break;

            default:

                //handles products and donation
                switch(strtolower($status)){
                    case "completed" :
                        self::log_debug("Processing a completed payment");
                        if($entry["payment_status"] != "Approved"){

                            if(self::is_valid_initial_payment_amount($config, $entry)){
                                self::log_debug("Entry is not already approved. Proceeding...");
                                $entry["payment_status"] = "Approved";
                                $entry["payment_amount"] = $amount;
                                $entry["payment_date"] = gmdate("y-m-d H:i:s");
                                $entry["transaction_id"] = $transaction_id;
                                $entry["transaction_type"] = 1; //payment

                                if(!$entry["is_fulfilled"]){
                                    self::log_debug("Payment has been made. Fulfilling order.");
                                    self::fulfill_order($entry, $transaction_id, $amount);
                                    self::log_debug("Order has been fulfilled");
                                    $entry["is_fulfilled"] = true;
                                }

                                self::log_debug("Updating entry.");
                                RGFormsModel::update_lead($entry);
                                self::log_debug("Adding note.");
                                RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has been approved. Amount: %s. Transaction Id: %s", "gravityforms"), GFCommon::to_money($entry["payment_amount"], $entry["currency"]), $transaction_id));
                            }
                            else{
                                self::log_debug("Payment amount does not match product price. Entry will not be marked as Approved.");
                                RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment amount (%s) does not match product price. Entry will not be marked as Approved. Transaction Id: %s", "gravityforms"), GFCommon::to_money($amount, $entry["currency"]), $transaction_id));
                            }
                        }
                        self::log_debug("Inserting transaction.");
                        GFPayPalData::insert_transaction($entry["id"], "payment", "", $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "reversed" :
                        self::log_debug("Processing reversal.");
                        if($entry["payment_status"] != "Reversed"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Reversed";
                                self::log_debug("Setting entry as Reversed");
                                RGFormsModel::update_lead($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has been reversed. Transaction Id: %s. Reason: %s", "gravityforms"), $transaction_id, self::get_reason($reason)));
                        }

                        GFPayPalData::insert_transaction($entry["id"], "reversal", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "canceled_reversal" :
                        self::log_debug("Processing a reversal cancellation");
                        if($entry["payment_status"] != "Approved"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Approved";
                                self::log_debug("Setting entry as approved");
                                RGFormsModel::update_lead($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment reversal has been canceled and the funds have been transferred to your account. Transaction Id: %s", "gravityforms"), $entry["transaction_id"]));
                        }

                        GFPayPalData::insert_transaction($entry["id"], "reinstated", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "denied" :
                        self::log_debug("Processing a Denied request.");
                        if($entry["payment_status"] != "Denied"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Denied";
                                self::log_debug("Setting entry as Denied.");
                                RGFormsModel::update_lead($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has been denied. Transaction Id: %s", "gravityforms"), $transaction_id));
                        }

                        GFPayPalData::insert_transaction($entry["id"], "denied", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "pending" :
                        self::log_debug("Processing a pending transaction.");
                        if($entry["payment_status"] != "Pending"){
                            if($entry["transaction_type"] != 2){
                                $entry["payment_status"] = "Pending";
                                $entry["payment_amount"] = $amount;
                                $entry["transaction_type"] = 1; //payment
                                self::log_debug("Setting entry as Pending.");
                                RGFormsModel::update_lead($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment is pending. Amount: %s. Transaction Id: %s. Reason: %s", "gravityforms"), GFCommon::to_money($amount, $entry["currency"]), $transaction_id, self::get_pending_reason($pending_reason)));
                        }

                        GFPayPalData::insert_transaction($entry["id"], "pending", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "refunded" :
                        self::log_debug("Processing a Refund request.");
                        if($entry["payment_status"] != "Refunded"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Refunded";
                                self::log_debug("Setting entry as Refunded.");
                                RGFormsModel::update_lead($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has been refunded. Refunded amount: %s. Transaction Id: %s", "gravityforms"), $amount, $transaction_id));
                        }

                        GFPayPalData::insert_transaction($entry["id"], "refund", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "voided" :
                        self::log_debug("Processing a Voided request.");
                        if($entry["payment_status"] != "Voided"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Voided";
                                self::log_debug("Setting entry as Voided.");
                                RGFormsModel::update_lead($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Authorization has been voided. Transaction Id: %s", "gravityforms"), $transaction_id));
                        }

                        GFPayPalData::insert_transaction($entry["id"], "void", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "processed" :
                        self::log_debug("Processing a 'processed' request.");
                        if($entry["transaction_type"] != 2){
                            $entry["payment_status"] = "Pending";
                            self::log_debug("Setting entry as Pending.");
                            RGFormsModel::update_lead($entry);
                            $entry["transaction_type"] = 1; //payment
                        }
                        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has been authorized. You can capture funds from your PayPal control panel. Transaction Id: %s", "gravityforms"), $transaction_id));

                        GFPayPalData::insert_transaction($entry["id"], "processed", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                    case "failed" :
                        self::log_debug("Processed a Failed request.");
                        if($entry["payment_status"] != "Failed"){
                            if($entry["transaction_type"] == 1){
                                $entry["payment_status"] = "Failed";
                                self::log_debug("Setting entry as Failed.");
                                RGFormsModel::update_lead($entry);
                            }
                            RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has Failed. Failed payments occur when they are made via your customer's bank account and could not be completed. Transaction Id: %s", "gravityforms"), $transaction_id));
                        }

                        GFPayPalData::insert_transaction($entry["id"], "failed", $subscriber_id, $transaction_id, $parent_transaction_id, $amount);
                    break;

                }

            break;
        }
        self::log_debug("Before gform_post_payment_status.");
        do_action("gform_post_payment_status", $config, $entry, $status,  $transaction_id, $subscriber_id, $amount, $pending_reason, $reason);
    }

    public static function fulfill_order(&$entry, $transaction_id, $amount){

        $config = self::get_config_by_entry($entry);
        if(!$config){
            self::log_error("Order can't be fulfilled because feed wasn't found for form: {$entry["form_id"]}");
            return;
        }

        $form = RGFormsModel::get_form_meta($entry["form_id"]);
        if($config["meta"]["delay_post"]){
            self::log_debug("Creating post.");
            RGFormsModel::create_post($form, $entry);
        }

        if(isset($config["meta"]["delay_notifications"])){
            //sending delayed notifications
            GFCommon::send_notifications($config["meta"]["selected_notifications"], $form, $entry, true, "form_submission");

        }
        else{

            //sending notifications using the legacy structure
            if($config["meta"]["delay_notification"]){
               self::log_debug("Sending admin notification.");
               GFCommon::send_admin_notification($form, $entry);
            }

            if($config["meta"]["delay_autoresponder"]){
               self::log_debug("Sending user notification.");
               GFCommon::send_user_notification($form, $entry);
            }
        }

        self::log_debug("Before gform_paypal_fulfillment.");
        do_action("gform_paypal_fulfillment", $entry, $config, $transaction_id, $amount);
    }

    private static function start_subscription($entry, $subscriber_id, $amount, $user_id, $user_name){
        $entry["payment_status"] = "Active";
        $entry["payment_amount"] = $amount;
        $entry["payment_date"] = gmdate("y-m-d H:i:s");
        $entry["transaction_id"] = $subscriber_id;
        $entry["transaction_type"] = 2; //subscription

        if(!$entry["is_fulfilled"]){
            self::fulfill_order($entry, $subscriber_id, $amount);
            $entry["is_fulfilled"] = true;
        }

        RGFormsModel::update_lead($entry);
        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription has been created. Subscriber Id: %s", "gravityforms"), $subscriber_id));
    }

    private static function get_pending_reason($code){

        switch(strtolower($code)){
            case "address":
                return __("The payment is pending because your customer did not include a confirmed shipping address and your Payment Receiving Preferences is set to allow you to manually accept or deny each of these payments. To change your preference, go to the Preferences section of your Profile.", "gravityforms");

            case "authorization":
                return __("You set the payment action to Authorization and have not yet captured funds.", "gravityforms");

            case "echeck":
                return __("The payment is pending because it was made by an eCheck that has not yet cleared.", "gravityforms");

            case "intl":
                return __("The payment is pending because you hold a non-U.S. account and do not have a withdrawal mechanism. You must manually accept or deny this payment from your Account Overview.", "gravityforms");

            case "multi-currency":
                return __("You do not have a balance in the currency sent, and you do not have your Payment Receiving Preferences set to automatically convert and accept this payment. You must manually accept or deny this payment.", "gravityforms");

            case "order":
                return __("You set the payment action to Order and have not yet captured funds.", "gravityforms");

            case "paymentreview":
                return __("The payment is pending while it is being reviewed by PayPal for risk.", "gravityforms");

            case "unilateral":
                return __("The payment is pending because it was made to an email address that is not yet registered or confirmed.", "gravityforms");

            case "upgrade":
                return __("The payment is pending because it was made via credit card and you must upgrade your account to Business or Premier status in order to receive the funds. upgrade can also mean that you have reached the monthly limit for transactions on your account.", "gravityforms");

            case "verify":
                return __("The payment is pending because you are not yet verified. You must verify your account before you can accept this payment.", "gravityforms");

            case "other":
                return __("Reason has not been specified. For more information, contact PayPal Customer Service.", "gravityforms");

            default:
                return empty($code) ? __("Reason has not been specified. For more information, contact PayPal Customer Service.", "gravityforms") : $code;
        }
    }

    private static function get_reason($code){

        switch(strtolower($code)){
            case "adjustment_reversal":
                return __("Reversal of an adjustment", "gravityforms");
            case "buyer-complaint":
                return __("A reversal has occurred on this transaction due to a complaint about the transaction from your customer.", "gravityforms");

            case "chargeback":
                return __("A reversal has occurred on this transaction due to a chargeback by your customer.", "gravityforms");

            case "chargeback_reimbursement":
                return __("Reimbursement for a chargeback.", "gravityforms");

            case "chargeback_settlement":
                return __("Settlement of a chargeback.", "gravityforms");

            case "guarantee":
                return __("A reversal has occurred on this transaction due to your customer triggering a money-back guarantee.", "gravityforms");

            case "other":
                return __("Non-specified reason.", "gravityforms");

            case "refund":
                return __("A reversal has occurred on this transaction because you have given the customer a refund.", "gravityforms");

            default:
                return empty($code) ? __("Reason has not been specified. For more information, contact PayPal Customer Service.", "gravityforms") : $code;
        }
    }

    private static function verify_paypal_ipn(){

        $req = 'cmd=_notify-validate';
        foreach ($_POST as $key => $value) {
            $value = urlencode(stripslashes($value));
            $req .= "&$key=$value";
        }

        $url = rgpost("test_ipn") ? self::$sandbox_url : self::$production_url;

        self::log_debug("Sending IPN request to PayPal for validation. URL: $url - Data: $req");

        $url_info = parse_url($url);

        //Post back to PayPal system to validate
        $request = new WP_Http();
        $headers = array("Host" => $url_info["host"]);
        $response = $request->post($url, array("httpversion"=>"1.1", "headers" => $headers, "sslverify" => false, "ssl" => true, "body" => $req, "timeout"=>20));
        self::log_debug("Response: " . print_r($response, true));

        return !is_wp_error($response) && trim($response["body"]) == "VERIFIED";
    }

    private static function customer_query_string($config, $lead){
        $fields = "";
        foreach(self::get_customer_fields() as $field){
            $field_id = $config["meta"]["customer_fields"][$field["name"]];
            $value = rgar($lead,$field_id);

            if($field["name"] == "country")
                $value = GFCommon::get_country_code($value);
            else if($field["name"] == "state")
                $value = GFCommon::get_us_state_code($value);

            if(!empty($value))
                $fields .="&{$field["name"]}=" . urlencode($value);
        }

        return $fields;
    }

    public static function is_valid_initial_payment_amount($config, $lead){

        $form = RGFormsModel::get_form_meta($lead["form_id"]);
        $products = GFCommon::get_product_fields($form, $lead, true);
        $payment_amount = rgpost("mc_gross");

        $product_amount = 0;
        switch($config["meta"]["type"]){
            case "product" :
                $product_amount = GFCommon::get_order_total($form, $lead);
            break;

            case "donation" :
                $query_string = self::get_donation_query_string($form, $lead);
                parse_str($query_string, $donation_info);
                $product_amount = $donation_info["amount"];

            break;

            case "subscription" :
                $query_string = self::get_subscription_query_string($config, $form, $lead);
                parse_str($query_string, $subscription_info);
                if(isset($subscription_info["a1"])){
                    $product_amount = $subscription_info["a1"];
                }
                else{
                    $product_amount = $subscription_info["a3"];
                }

            break;
        }
        
        $epsilon = 0.00001;
        $is_equal = abs( floatval( $payment_amount ) - floatval( $product_amount ) ) < $epsilon;
        $is_greater = floatval( $payment_amount ) >= floatval( $product_amount );
                    
        //initial payment is valid if it is equal to or greater than product/subscription amount
        if( $is_equal || $is_greater ){
            return true;
		}

        return false;

    }

    private static function get_product_query_string($form, $entry){
        $fields = "";
        $products = GFCommon::get_product_fields($form, $entry, true);
        $product_index = 1;
        $total = 0;
        $discount = 0;

        foreach($products["products"] as $product){
            $option_fields = "";
            $price = GFCommon::to_number($product["price"]);
            if(is_array(rgar($product,"options"))){
                $option_index = 1;
                foreach($product["options"] as $option){
                    $field_label = urlencode(rgar($option, "field_label"));
                    $option_name = urlencode(rgar($option, "option_name"));
                    $option_fields .= "&on{$option_index}_{$product_index}={$field_label}&os{$option_index}_{$product_index}={$option_name}";
                    $price += GFCommon::to_number($option["price"]);
                    $option_index++;
                }
            }

            $name = urlencode($product["name"]);
            if($price > 0)
            {
                $fields .= "&item_name_{$product_index}={$name}&amount_{$product_index}={$price}&quantity_{$product_index}={$product["quantity"]}{$option_fields}";
                $total += $price * $product['quantity'];
                $product_index++;
            }
            else{
                $discount += abs($price) * $product['quantity'];
            }

        }

        if($discount > 0){
            $fields .= "&discount_amount_cart={$discount}";
        }

        $shipping = !empty($products["shipping"]["price"]) ? "&shipping_1={$products["shipping"]["price"]}" : "";
        $fields .= "{$shipping}&cmd=_cart&upload=1";

        return $total > 0 && $total > $discount ? $fields : false;
    }

    private static function get_donation_query_string($form, $entry){
        $fields = "";

        //getting all donation fields
        $donations = GFCommon::get_fields_by_type($form, array("donation"));
        $total = 0;
        $purpose = "";
        foreach($donations as $donation){
            $value = RGFormsModel::get_lead_field_value($entry, $donation);
            list($name, $price) = explode("|", $value);
            if(empty($price)){
                $price = $name;
                $name = $donation["label"];
            }
            $purpose .= $name . ", ";
            $price = GFCommon::to_number($price);
            $total += $price;
        }

        //using product fields for donation if there aren't any legacy donation fields in the form
        if($total == 0){
            //getting all product fields
            $products = GFCommon::get_product_fields($form, $entry, true);
            foreach($products["products"] as $product){
                $options = "";
                if(is_array($product["options"]) && !empty($product["options"])){
                    $options = " (";
                    foreach($product["options"] as $option){
                        $options .= $option["option_name"] . ", ";
                    }
                    $options = substr($options, 0, strlen($options)-2) . ")";
                }
                $quantity = GFCommon::to_number($product["quantity"]);
                $quantity_label = $quantity > 1 ? $quantity . " " : "";
                $purpose .= $quantity_label . $product["name"] . $options . ", ";
            }

            $total = GFCommon::get_order_total($form, $entry);
        }

        if(!empty($purpose))
            $purpose = substr($purpose, 0, strlen($purpose)-2);

        $purpose = urlencode($purpose);

        //truncating to maximum length allowed by PayPal
        if(strlen($purpose) > 127)
            $purpose = substr($purpose, 0, 124) . "...";

        $fields = "&amount={$total}&item_name={$purpose}&cmd=_donations";

        return $total > 0 ? $fields : false;
    }

    private static function get_subscription_query_string($config, $form, $entry){

        //getting all product fields
        $products = GFCommon::get_product_fields($form, $entry, true);
        $item_name = "";
        $name_without_options = "";
        $amount = 0;
        foreach($products["products"] as $id => $product){
            if($id == $config["meta"]["recurring_amount_field"] || $config["meta"]["recurring_amount_field"] == "all"){
                $options = "";
                $price = GFCommon::to_number($product["price"]);
                if(isset($product["options"]) && is_array($product["options"]) && !empty($product["options"])){
                    $options = " (";
                    foreach($product["options"] as $option){
                        $options .= $option["option_name"] . ", ";
                        $price += GFCommon::to_number($option["price"]);
                    }
                    $options = substr($options, 0, strlen($options)-2) . ")";
                }
                $quantity = GFCommon::to_number($product["quantity"]);
                $quantity_label = $quantity > 1 ? $quantity . " " : "";
                $item_name .= $quantity_label . $product["name"] . $options . ", ";
                $name_without_options .= $product["name"] . ", ";
                $amount += ($price * $quantity);
            }
        }

        //adding shipping if feed was configured with "Form Total"
        if($config["meta"]["recurring_amount_field"] == "all" && !empty($products["shipping"]["price"]))
            $amount += floatval($products["shipping"]["price"]);


        if(!empty($item_name))
            $item_name = substr($item_name, 0, strlen($item_name)-2);

        //if name is larger than max, remove options from it.
        if(strlen($item_name) > 127){
            $item_name = substr($name_without_options, 0, strlen($name_without_options)-2);

            //truncating name to maximum allowed size
            if(strlen($item_name) > 127)
                $item_name = substr($item_name, 0, 124) . "...";
        }
        $item_name = urlencode($item_name);

        $trial="";
        if($config["meta"]["trial_period_enabled"]){
            $trial_amount = GFCommon::to_number($config["meta"]["trial_amount"]);
            if(empty($trial_amount))
                $trial_amount = 0;
            $trial = "&a1={$trial_amount}&p1={$config["meta"]["trial_period_number"]}&t1={$config["meta"]["trial_period_type"]}";
        }
        $recurring_times= !empty($config["meta"]["recurring_times"]) ? "&srt={$config["meta"]["recurring_times"]}" : "";
        $recurring_retry = $config["meta"]["recurring_retry"] ? "1" : "0";
        $query_string = "&cmd=_xclick-subscriptions&item_name={$item_name}{$trial}&a3={$amount}&p3={$config["meta"]["billing_cycle_number"]}&t3={$config["meta"]["billing_cycle_type"]}&src=1&sra={$recurring_retry}{$recurring_times}";

        return $amount > 0 ? $query_string : false;
    }

    private static function get_subscription_option_info($product){
        $option_price = 0;
        $option_labels = array();
        if(is_array($product["options"])){
            foreach($product["options"] as $option){
                $option_price += $option["price"];
                $option_labels[] = $option["option_label"];
            }
        }
        $label = empty($option_labels) ? $product["name"] : $product["name"] . " - " . implode(", " , $option_labels);
        if(strlen($label) > 127)
            $label = $product["name"] . " - " . __("with options", "gravityformspaypal");

        return array("price" => $option_price, "label" => $label);
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFPayPal::has_access("gravityforms_paypal_uninstall"))
            die(__("You don't have adequate permission to uninstall the PayPal Add-On.", "gravityformspaypal"));

        //droping all tables
        GFPayPalData::drop_tables();

        //removing options
        delete_option("gf_paypal_site_name");
        delete_option("gf_paypal_auth_token");
        delete_option("gf_paypal_version");

        //Deactivating plugin
        $plugin = "gravityformspaypal/paypal.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    private static function get_customer_information($form, $config=null){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form);

        $str = "<table cellpadding='0' cellspacing='0'><tr><td class='paypal_col_heading'>" . __("PayPal Fields", "gravityformspaypal") . "</td><td class='paypal_col_heading'>" . __("Form Fields", "gravityformspaypal") . "</td></tr>";
        $customer_fields = self::get_customer_fields();
        foreach($customer_fields as $field){
            $selected_field = $config ? $config["meta"]["customer_fields"][$field["name"]] : "";
            $str .= "<tr><td class='paypal_field_cell'>" . $field["label"]  . "</td><td class='paypal_field_cell'>" . self::get_mapped_field_list($field["name"], $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "</table>";

        return $str;
    }

    private static function get_customer_fields(){
        return array(array("name" => "first_name" , "label" => "First Name"), array("name" => "last_name" , "label" =>"Last Name"),
        array("name" => "email" , "label" =>"Email"), array("name" => "address1" , "label" =>"Address"), array("name" => "address2" , "label" =>"Address 2"),
        array("name" => "city" , "label" =>"City"), array("name" => "state" , "label" =>"State"), array("name" => "zip" , "label" =>"Zip"),
        array("name" => "country" , "label" =>"Country"));
    }

    private static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "paypal_customer_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    private static function get_product_options($form, $selected_field){
        $str = "<option value=''>" . __("Select a field", "gravityformspaypal") ."</option>";
        $fields = GFCommon::get_fields_by_type($form, array("product"));

        foreach($fields as $field){
            $field_id = $field["id"];
            $field_label = RGFormsModel::get_label($field);

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }

        $selected = $selected_field == 'all' ? "selected='selected'" : "";
        $str .= "<option value='all' " . $selected . ">" . __("Form Total", "gravityformspaypal") ."</option>";

        return $str;
    }

    private static function get_form_fields($form){
        $fields = array();

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"])){

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field, 'displayOnly')){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function return_url($form_id, $lead_id) {
        $pageURL = GFCommon::is_ssl() ? "https://" : "http://";

        if ($_SERVER["SERVER_PORT"] != "80")
            $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
        else
            $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];

        $ids_query = "ids={$form_id}|{$lead_id}";
        $ids_query .= "&hash=" . wp_hash($ids_query);

        return add_query_arg("gf_paypal_return", base64_encode($ids_query), $pageURL);
    }

    private static function is_paypal_page(){
        $current_page = trim(strtolower(RGForms::get("page")));
        return in_array($current_page, array("gf_paypal"));
    }

    //Returns the url of the plugin's root folder
    protected static function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected static function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

    public static function admin_edit_payment_status($payment_status, $form_id, $lead)
    {
		//allow the payment status to be edited when for paypal, not set to Approved, and not a subscription
		$payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
		require_once(self::get_base_path() . "/data.php");
		//get the transaction type out of the feed configuration, do not allow status to be changed when subscription
		$paypal_feed_id = gform_get_meta($lead["id"], "paypal_feed_id");
		$feed_config = GFPayPalData::get_feed($paypal_feed_id);
		$transaction_type = rgars($feed_config, "meta/type");
    	if ($payment_gateway <> "paypal" || strtolower(rgpost("save")) <> "edit" || $payment_status == "Approved" || $transaction_type == "subscription")
    		return $payment_status;

		//create drop down for payment status
		$payment_string = gform_tooltip("paypal_edit_payment_status","",true);
		$payment_string .= '<select id="payment_status" name="payment_status">';
		$payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
		$payment_string .= '<option value="Approved">Approved</option>';
		$payment_string .= '</select>';
		return $payment_string;
    }

    public static function admin_edit_payment_status_details($form_id, $lead)
    {
		//check meta to see if this entry is paypal
		$payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
		$form_action = strtolower(rgpost("save"));
		if ($payment_gateway <> "paypal" || $form_action <> "edit")
			return;

		//get data from entry to pre-populate fields
		$payment_amount = rgar($lead, "payment_amount");
		if (empty($payment_amount))
		{
			$form = RGFormsModel::get_form_meta($form_id);
			$payment_amount = GFCommon::get_order_total($form,$lead);
		}
	  	$transaction_id = rgar($lead, "transaction_id");
		$payment_date = rgar($lead, "payment_date");
		if (empty($payment_date))
		{
			$payment_date = gmdate("y-m-d H:i:s");
		}

		//display edit fields
		?>
		<div id="edit_payment_status_details" style="display:block">
			<table>
				<tr>
					<td colspan="2"><strong>Payment Information</strong></td>
				</tr>

				<tr>
					<td>Date:<?php gform_tooltip("paypal_edit_payment_date") ?></td>
					<td><input type="text" id="payment_date" name="payment_date" value="<?php echo $payment_date?>"></td>
				</tr>
				<tr>
					<td>Amount:<?php gform_tooltip("paypal_edit_payment_amount") ?></td>
					<td><input type="text" id="payment_amount" name="payment_amount" value="<?php echo $payment_amount?>"></td>
				</tr>
				<tr>
					<td nowrap>Transaction ID:<?php gform_tooltip("paypal_edit_payment_transaction_id") ?></td>
					<td><input type="text" id="paypal_transaction_id" name="paypal_transaction_id" value="<?php echo $transaction_id?>"></td>
				</tr>
			</table>
		</div>
		<?php
	}

	public static function admin_update_payment($form, $lead_id)
	{
		check_admin_referer('gforms_save_entry', 'gforms_save_entry');
		//update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
		//check meta to see if this entry is paypal
		$payment_gateway = gform_get_meta($lead_id, "payment_gateway");
		$form_action = strtolower(rgpost("save"));
		if ($payment_gateway <> "paypal" || $form_action <> "update")
			return;
		//get lead
		$lead = RGFormsModel::get_lead($lead_id);
		//get payment fields to update
		$payment_status = rgpost("payment_status");
		//when updating, payment status may not be editable, if no value in post, set to lead payment status
		if (empty($payment_status))
		{
			$payment_status = $lead["payment_status"];
		}

		$payment_amount = rgpost("payment_amount");
		$payment_transaction = rgpost("paypal_transaction_id");
		$payment_date = rgpost("payment_date");
		if (empty($payment_date))
		{
			$payment_date = gmdate("y-m-d H:i:s");
		}
		else
		{
			//format date entered by user
			$payment_date = date("Y-m-d H:i:s", strtotime($payment_date));
		}

		global $current_user;
		$user_id = 0;
        $user_name = "System";
        if($current_user && $user_data = get_userdata($current_user->ID)){
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }

		$lead["payment_status"] = $payment_status;
		$lead["payment_amount"] = $payment_amount;
		$lead["payment_date"] =   $payment_date;
		$lead["transaction_id"] = $payment_transaction;

		// if payment status does not equal approved or the lead has already been fulfilled, do not continue with fulfillment
        if($payment_status == 'Approved' && !$lead["is_fulfilled"])
        {
        	//call fulfill order, mark lead as fulfilled
        	self::fulfill_order($lead, $payment_transaction, $payment_amount);
        	$lead["is_fulfilled"] = true;
		}
		//update lead, add a note
		RGFormsModel::update_lead($lead);
		RGFormsModel::add_note($lead["id"], $user_id, $user_name, sprintf(__("Payment information was manually updated. Status: %s. Amount: %s. Transaction Id: %s. Date: %s", "gravityforms"), $lead["payment_status"], GFCommon::to_money($lead["payment_amount"], $lead["currency"]), $payment_transaction, $lead["payment_date"]));
	}

	function set_logging_supported($plugins)
	{
		$plugins[self::$slug] = "PayPal Payments Standard";
		return $plugins;
	}

	private static function log_error($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
		}
	}

	private static function log_debug($message){
		if(class_exists("GFLogging"))
		{
			GFLogging::include_logger();
			GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
		}
	}
}

if(!function_exists("rgget")){
function rgget($name, $array=null){
    if(!isset($array))
        $array = $_GET;

    if(isset($array[$name]))
        return $array[$name];

    return "";
}
}

if(!function_exists("rgpost")){
function rgpost($name, $do_stripslashes=true){
    if(isset($_POST[$name]))
        return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];

    return "";
}
}

if(!function_exists("rgar")){
function rgar($array, $name){
    if(isset($array[$name]))
        return $array[$name];

    return '';
}
}

if(!function_exists("rgars")){
function rgars($array, $name){
    $names = explode("/", $name);
    $val = $array;
    foreach($names as $current_name){
        $val = rgar($val, $current_name);
    }
    return $val;
}
}

if(!function_exists("rgempty")){
function rgempty($name, $array = null){
    if(!$array)
        $array = $_POST;

    $val = rgget($name, $array);
    return empty($val);
}
}

if(!function_exists("rgblank")){
function rgblank($text){
    return empty($text) && strval($text) != "0";
}
}
