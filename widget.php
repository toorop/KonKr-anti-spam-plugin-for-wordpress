<?php

/**
 * @package Konkr
 */
// Widget stuff
function widget_konkr_register() {
    if (function_exists('register_sidebar_widget')) :

        function widget_konkr($args) {
            extract($args);
            $options = get_option('widget_konkr');
            $count = get_option('konkr_spam_count');
            ?>
            <?php echo $before_widget; ?>
            <?php echo $before_title . $options['title'] . $after_title; ?>
            <div id="konkrwrap"><div id="konkrstats"><a id="aka" href="http://konkr.com" title=""><?php printf(_n('%1$s%2$s%3$s %4$sspam comment%5$s %6$sblocked by%7$s<br />%8$sKonkr%9$s', '%1$s%2$s%3$s %4$sspam comments%5$s %6$sblocked by%7$s<br />%8$sKonkr%9$s', $count), '<span id="konkr1"><span id="konkrcount">', number_format_i18n($count), '</span>', '<span id="konkrsc">', '</span></span>', '<span id="konkr2"><span id="konkrbb">', '</span>', '<span id="konkra">', '</span></span>'); ?></a></div></div> 
            <?php echo $after_widget; ?>
            <?php
        }

        function widget_konkr_style() {
            $plugin_dir = '/wp-content/plugins';
            if (defined('PLUGINDIR'))
                $plugin_dir = '/' . PLUGINDIR;
            ?>
            <style type="text/css">
                #aka,#aka:link,#aka:hover,#aka:visited,#aka:active{color:#fff;text-decoration:none}
                #aka:hover{border:none;text-decoration:none}
                #aka:hover #konkr1{display:none}
                #aka:hover #konkr2,#konkr1{display:block}
                #konkr2{display:none;padding-top:2px}
                #konkra{font-size:16px;font-weight:bold;line-height:18px;text-decoration:none}
                #konkrcount{display:block;font:15px Verdana,Arial,Sans-Serif;font-weight:bold;text-decoration:none}
                #konkrwrap #konkrstats{background:url(<?php echo get_option('siteurl'), $plugin_dir; ?>/konkr/konkr.gif) no-repeat top left;border:none;color:#fff;font:11px 'Trebuchet MS','Myriad Pro',sans-serif;height:40px;line-height:100%;overflow:hidden;padding:8px 0 0;text-align:center;width:120px}
            </style>
            <?php
        }

        function widget_konkr_control() {
            $options = $newoptions = get_option('widget_konkr');
            if (isset($_POST['konkr-submit']) && $_POST["konkr-submit"]) {
                $newoptions['title'] = strip_tags(stripslashes($_POST["konkr-title"]));
                if (empty($newoptions['title']))
                    $newoptions['title'] = __('Spam Blocked');
            }
            if ($options != $newoptions) {
                $options = $newoptions;
                update_option('widget_konkr', $options);
            }
            $title = htmlspecialchars($options['title'], ENT_QUOTES);
            ?>
            <p><label for="konkr-title"><?php _e('Title:'); ?> <input style="width: 250px;" id="konkr-title" name="konkr-title" type="text" value="<?php echo $title; ?>" /></label></p>
            <input type="hidden" id="konkr-submit" name="konkr-submit" value="1" />
            <?php
        }

        if (function_exists('wp_register_sidebar_widget')) {
            wp_register_sidebar_widget('konkr', 'Konkr', 'widget_konkr', null, 'konkr');
            wp_register_widget_control('konkr', 'Konkr', 'widget_konkr_control', null, 75, 'konkr');
        } else {
            register_sidebar_widget('Konkr', 'widget_konkr', null, 'konkr');
            register_widget_control('Konkr', 'widget_konkr_control', null, 75, 'konkr');
        }
        if (is_active_widget('widget_konkr'))
            add_action('wp_head', 'widget_konkr_style');
    endif;
}

add_action('init', 'widget_konkr_register');

// Counter for non-widget users
function konkr_counter() {
    $plugin_dir = '/wp-content/plugins';
    if (defined('PLUGINDIR'))
        $plugin_dir = '/' . PLUGINDIR;
    ?>
    <style type="text/css">
        #konkrwrap #aka,#aka:link,#aka:hover,#aka:visited,#aka:active{color:#fff;text-decoration:none}
        #aka:hover{border:none;text-decoration:none}
        #aka:hover #konkr1{display:none}
        #aka:hover #konkr2,#konkr1{display:block}
        #konkr2{display:none;padding-top:2px}
        #konkra{font-size:16px;font-weight:bold;line-height:18px;text-decoration:none}
        #konkrcount{display:block;font:15px Verdana,Arial,Sans-Serif;font-weight:bold;text-decoration:none}
        #konkrwrap #konkrstats{background:url(<?php echo get_option('siteurl'), $plugin_dir; ?>/konkr/konkr.gif) no-repeat top left;border:none;color:#fff;font:11px 'Trebuchet MS','Myriad Pro',sans-serif;height:40px;line-height:100%;overflow:hidden;padding:8px 0 0;text-align:center;width:120px}
    </style>
    <?php
    $count = get_option('konkr_spam_count');
    printf(_n('<div id="konkrwrap"><div id="konkrstats"><a id="aka" href="http://konkr.com" title=""><div id="konkr1"><span id="konkrcount">%1$s</span> <span id="konkrsc">spam comment</span></div> <div id="konkr2"><span id="konkrbb">blocked by</span><br /><span id="konkra">Konkr</span></div></a></div></div>', '<div id="konkrwrap"><div id="konkrstats"><a id="aka" href="http://konkr.com" title=""><div id="konkr1"><span id="konkrcount">%1$s</span> <span id="konkrsc">spam comments</span></div> <div id="konkr2"><span id="konkrbb">blocked by</span><br /><span id="konkra">Konkr</span></div></a></div></div>', $count), number_format_i18n($count));
}
