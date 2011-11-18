<?php

/**
 * @package Konkr
 */
/*
  Plugin Name: KonKr
  Plugin URI: http://konkr.com
  Description: KonKr <strong>protect your blog from comment and trackback spam</strong>. <br>To get started: 1) Click the "Activate" link to the left of this description, 2) <a href="http://konkr.com">Sign up for a free KonKr API key</a>, and 3) Go to your <a href="plugins.php?page=konkr-key-config">KonKr configuration</a> page, and save your API key.
  Version: 0.1
  Author: Toorop (forked from Automattic Konkr Worpress plugin)
  Author URI: http://konkr.com
  License: GPLv2 or later
 */

/*
  This program is free software; you can redistribute it and/or
  modify it under the terms of the GNU General Public License
  as published by the Free Software Foundation; either version 2
  of the License, or (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

define('KONKR_VERSION', '0.1');
define('KONKR_PLUGIN_URL', plugin_dir_url(__FILE__));

/** If you hardcode a WP.com API key here, all key config screens will be hidden */
if (defined('KONKR_API_KEY'))
    $konkr_api_key = constant('KONKR_API_KEY');
else
    $konkr_api_key = '';

// Make sure we don't expose any info if called directly
if (!function_exists('add_action')) {
    echo "Hi there!  I'm just a plugin, not much I can do when called directly.";
    exit;
}

if (isset($wp_db_version) && $wp_db_version <= 9872)
    include_once dirname(__FILE__) . '/legacy.php';

include_once dirname(__FILE__) . '/widget.php';

if (is_admin())
    require_once dirname(__FILE__) . '/admin.php';

function konkr_init() {
    global $konkr_api_key, $konkr_api_host, $konkr_api_port;

    if ($konkr_api_key)
        $konkr_api_host = $konkr_api_key . '.api.konkr.com';
    else
        $konkr_api_host = get_option('wordpress_api_key') . '.api.konkr.com';

    $konkr_api_port = 80;
}

add_action('init', 'konkr_init');

function konkr_get_key() {
    global $konkr_api_key;
    if (!empty($konkr_api_key))
        return $konkr_api_key;
    return get_option('wordpress_api_key');
}

function konkr_verify_key($key, $ip = null) {
    global $konkr_api_host, $konkr_api_port, $konkr_api_key;
    $blog = urlencode(get_option('home'));
    if ($konkr_api_key)
        $key = $konkr_api_key;
    $response = konkr_http_post("key=$key&blog=$blog", 'api.konkr.com', '/1.1/verify-key', $konkr_api_port, $ip);
    if (!is_array($response) || !isset($response[1]) || $response[1] != 'valid' && $response[1] != 'invalid')
        return 'failed';
    return $response[1];
}

// if we're in debug or test modes, use a reduced service level so as not to polute training or stats data
function konkr_test_mode() {
    if (defined('KONKR_TEST_MODE') && KONKR_TEST_MODE)
        return true;
    return false;
}

// return a comma-separated list of role names for the given user
function konkr_get_user_roles($user_id) {
    $roles = false;

    if (!class_exists('WP_User'))
        return false;

    if ($user_id > 0) {
        $comment_user = new WP_User($user_id);
        if (isset($comment_user->roles))
            $roles = join(',', $comment_user->roles);
    }

    if (is_multisite() && is_super_admin($user_id)) {
        if (empty($roles)) {
            $roles = 'super_admin';
        } else {
            $comment_user->roles[] = 'super_admin';
            $roles = join(',', $comment_user->roles);
        }
    }

    return $roles;
}

// Returns array with headers in $response[0] and body in $response[1]
function konkr_http_post($request, $host, $path, $port = 80, $ip=null) {
    global $wp_version;

    $konkr_ua = "WordPress/{$wp_version} | ";
    $konkr_ua .= 'Konkr/' . constant('KONKR_VERSION');

    $konkr_ua = apply_filters('konkr_ua', $konkr_ua);

    $content_length = strlen($request);

    $http_host = $host;
    // use a specific IP if provided
    // needed by konkr_check_server_connectivity()
    if ($ip && long2ip(ip2long($ip))) {
        $http_host = $ip;
    } else {
        $http_host = $host;
    }

    // use the WP HTTP class if it is available
    if (function_exists('wp_remote_post')) {
        $http_args = array(
            'body' => $request,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded; ' .
                'charset=' . get_option('blog_charset'),
                'Host' => $host,
                'User-Agent' => $konkr_ua
            ),
            'httpversion' => '1.0',
            'timeout' => 15
        );
        $konkr_url = "http://{$http_host}{$path}";
        $response = wp_remote_post($konkr_url, $http_args);
        if (is_wp_error($response))
            return '';

        return array($response['headers'], $response['body']);
    } else {
        $http_request = "POST $path HTTP/1.0\r\n";
        $http_request .= "Host: $host\r\n";
        $http_request .= 'Content-Type: application/x-www-form-urlencoded; charset=' . get_option('blog_charset') . "\r\n";
        $http_request .= "Content-Length: {$content_length}\r\n";
        $http_request .= "User-Agent: {$konkr_ua}\r\n";
        $http_request .= "\r\n";
        $http_request .= $request;

        $response = '';
        if (false != ( $fs = @fsockopen($http_host, $port, $errno, $errstr, 10) )) {
            fwrite($fs, $http_request);

            while (!feof($fs))
                $response .= fgets($fs, 1160); // One TCP-IP packet
            fclose($fs);
            $response = explode("\r\n\r\n", $response, 2);
        }
        return $response;
    }
}

// filter handler used to return a spam result to pre_comment_approved
function konkr_result_spam($approved) {
    // bump the counter here instead of when the filter is added to reduce the possibility of overcounting
    if ($incr = apply_filters('konkr_spam_count_incr', 1))
        update_option('konkr_spam_count', get_option('konkr_spam_count') + $incr);
    // this is a one-shot deal
    remove_filter('pre_comment_approved', 'konkr_result_spam');
    return 'spam';
}

function konkr_result_hold($approved) {
    // once only
    remove_filter('pre_comment_approved', 'konkr_result_hold');
    return '0';
}

// how many approved comments does this author have?
function konkr_get_user_comments_approved($user_id, $comment_author_email, $comment_author, $comment_author_url) {
    global $wpdb;

    if (!empty($user_id))
        return $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE user_id = %d AND comment_approved = 1", $user_id));

    if (!empty($comment_author_email))
        return $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_author_email = %s AND comment_author = %s AND comment_author_url = %s AND comment_approved = 1", $comment_author_email, $comment_author, $comment_author_url));

    return 0;
}

function konkr_microtime() {
    $mtime = explode(' ', microtime());
    return $mtime[1] + $mtime[0];
}

// log an event for a given comment, storing it in comment_meta
function konkr_update_comment_history($comment_id, $message, $event=null) {
    global $current_user;

    // failsafe for old WP versions
    if (!function_exists('add_comment_meta'))
        return false;

    $user = '';
    if (is_object($current_user) && isset($current_user->user_login))
        $user = $current_user->user_login;

    $event = array(
        'time' => konkr_microtime(),
        'message' => $message,
        'event' => $event,
        'user' => $user,
    );

    // $unique = false so as to allow multiple values per comment
    $r = add_comment_meta($comment_id, 'konkr_history', $event, false);
}

// get the full comment history for a given comment, as an array in reverse chronological order
function konkr_get_comment_history($comment_id) {

    // failsafe for old WP versions
    if (!function_exists('add_comment_meta'))
        return false;

    $history = get_comment_meta($comment_id, 'konkr_history', false);
    usort($history, 'konkr_cmp_time');
    return $history;
}

function konkr_cmp_time($a, $b) {
    return $a['time'] > $b['time'] ? -1 : 1;
}

// this fires on wp_insert_comment.  we can't update comment_meta when konkr_auto_check_comment() runs
// because we don't know the comment ID at that point.
function konkr_auto_check_update_meta($id, $comment) {
    global $konkr_last_comment;

    // failsafe for old WP versions
    if (!function_exists('add_comment_meta'))
        return false;

    // wp_insert_comment() might be called in other contexts, so make sure this is the same comment
    // as was checked by konkr_auto_check_comment
    if (is_object($comment) && !empty($konkr_last_comment) && is_array($konkr_last_comment)) {
        if (intval($konkr_last_comment['comment_post_ID']) == intval($comment->comment_post_ID)
                && $konkr_last_comment['comment_author'] == $comment->comment_author
                && $konkr_last_comment['comment_author_email'] == $comment->comment_author_email) {
            // normal result: true or false
            if ($konkr_last_comment['konkr_result'] == 'true') {
                update_comment_meta($comment->comment_ID, 'konkr_result', 'true');
                konkr_update_comment_history($comment->comment_ID, __('Konkr caught this comment as spam'), 'check-spam');
                if ($comment->comment_approved != 'spam')
                    konkr_update_comment_history($comment->comment_ID, sprintf(__('Comment status was changed to %s'), $comment->comment_approved), 'status-changed' . $comment->comment_approved);
            } elseif ($konkr_last_comment['konkr_result'] == 'false') {
                update_comment_meta($comment->comment_ID, 'konkr_result', 'false');
                konkr_update_comment_history($comment->comment_ID, __('Konkr cleared this comment'), 'check-ham');
                if ($comment->comment_approved == 'spam') {
                    if (wp_blacklist_check($comment->comment_author, $comment->comment_author_email, $comment->comment_author_url, $comment->comment_content, $comment->comment_author_IP, $comment->comment_agent))
                        konkr_update_comment_history($comment->comment_ID, __('Comment was caught by wp_blacklist_check'), 'wp-blacklisted');
                    else
                        konkr_update_comment_history($comment->comment_ID, sprintf(__('Comment status was changed to %s'), $comment->comment_approved), 'status-changed-' . $comment->comment_approved);
                }
                // abnormal result: error
            } else {
                update_comment_meta($comment->comment_ID, 'konkr_error', time());
                konkr_update_comment_history($comment->comment_ID, sprintf(__('Konkr was unable to check this comment (response: %s), will automatically retry again later.'), $konkr_last_comment['konkr_result']), 'check-error');
            }

            // record the complete original data as submitted for checking
            if (isset($konkr_last_comment['comment_as_submitted']))
                update_comment_meta($comment->comment_ID, 'konkr_as_submitted', $konkr_last_comment['comment_as_submitted']);
        }
    }
}

add_action('wp_insert_comment', 'konkr_auto_check_update_meta', 10, 2);

function konkr_auto_check_comment($commentdata) {
    global $konkr_api_host, $konkr_api_port, $konkr_last_comment;

    $comment = $commentdata;
    $comment['user_ip'] = $_SERVER['REMOTE_ADDR'];
    $comment['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    $comment['referrer'] = $_SERVER['HTTP_REFERER'];
    $comment['blog'] = get_option('home');
    $comment['blog_lang'] = get_locale();
    $comment['blog_charset'] = get_option('blog_charset');
    $comment['permalink'] = get_permalink($comment['comment_post_ID']);

    if (!empty($comment['user_ID'])) {
        $comment['user_role'] = konkr_get_user_roles($comment['user_ID']);
    }

    $konkr_nonce_option = apply_filters('konkr_comment_nonce', get_option('konkr_comment_nonce'));
    $comment['konkr_comment_nonce'] = 'inactive';
    if ($konkr_nonce_option == 'true' || $konkr_nonce_option == '') {
        $comment['konkr_comment_nonce'] = 'failed';
        if (isset($_POST['konkr_comment_nonce']) && wp_verify_nonce($_POST['konkr_comment_nonce'], 'konkr_comment_nonce_' . $comment['comment_post_ID']))
            $comment['konkr_comment_nonce'] = 'passed';

        // comment reply in wp-admin
        if (isset($_POST['_ajax_nonce-replyto-comment']) && check_ajax_referer('replyto-comment', '_ajax_nonce-replyto-comment'))
            $comment['konkr_comment_nonce'] = 'passed';
    }

    if (konkr_test_mode())
        $comment['is_test'] = 'true';

    foreach ($_POST as $key => $value) {
        if (is_string($value))
            $comment["POST_{$key}"] = $value;
    }

    $ignore = array('HTTP_COOKIE', 'HTTP_COOKIE2', 'PHP_AUTH_PW');

    foreach ($_SERVER as $key => $value) {
        if (!in_array($key, $ignore) && is_string($value))
            $comment["$key"] = $value;
        else
            $comment["$key"] = '';
    }

    $query_string = '';
    foreach ($comment as $key => $data)
        $query_string .= $key . '=' . urlencode(stripslashes($data)) . '&';

    $commentdata['comment_as_submitted'] = $comment;

    $response = konkr_http_post($query_string, $konkr_api_host, '/1.1/comment-check', $konkr_api_port);
    $commentdata['konkr_result'] = $response[1];
    if ('true' == $response[1]) {
        // konkr_spam_count will be incremented later by konkr_result_spam()
        add_filter('pre_comment_approved', 'konkr_result_spam');

        do_action('konkr_spam_caught');

        $post = get_post($comment['comment_post_ID']);
        $last_updated = strtotime($post->post_modified_gmt);
        $diff = time() - $last_updated;
        $diff = $diff / 86400;

        if ($post->post_type == 'post' && $diff > 30 && get_option('konkr_discard_month') == 'true' && empty($comment['user_ID'])) {
            // konkr_result_spam() won't be called so bump the counter here
            if ($incr = apply_filters('konkr_spam_count_incr', 1))
                update_option('konkr_spam_count', get_option('konkr_spam_count') + $incr);
            wp_redirect($_SERVER['HTTP_REFERER']);
            die();
        }
    }

    // if the response is neither true nor false, hold the comment for moderation and schedule a recheck
    if ('true' != $response[1] && 'false' != $response[1]) {
        if (!wp_get_current_user()) {
            add_filter('pre_comment_approved', 'konkr_result_hold');
        }
        wp_schedule_single_event(time() + 1200, 'konkr_schedule_cron_recheck');
    }

    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
        // WP 2.1+: delete old comments daily
        if (!wp_next_scheduled('konkr_scheduled_delete'))
            wp_schedule_event(time(), 'daily', 'konkr_scheduled_delete');
    } elseif ((mt_rand(1, 10) == 3)) {
        // WP 2.0: run this one time in ten
        konkr_delete_old();
    }
    $konkr_last_comment = $commentdata;
    return $commentdata;
}

add_action('preprocess_comment', 'konkr_auto_check_comment', 1);

function konkr_delete_old() {
    global $wpdb;
    $now_gmt = current_time('mysql', 1);
    $comment_ids = $wpdb->get_col("SELECT comment_id FROM $wpdb->comments WHERE DATE_SUB('$now_gmt', INTERVAL 15 DAY) > comment_date_gmt AND comment_approved = 'spam'");
    if (empty($comment_ids))
        return;

    $comma_comment_ids = implode(', ', array_map('intval', $comment_ids));

    do_action('delete_comment', $comment_ids);
    $wpdb->query("DELETE FROM $wpdb->comments WHERE comment_id IN ( $comma_comment_ids )");
    $wpdb->query("DELETE FROM $wpdb->commentmeta WHERE comment_id IN ( $comma_comment_ids )");
    clean_comment_cache($comment_ids);
    $n = mt_rand(1, 5000);
    if (apply_filters('konkr_optimize_table', ($n == 11))) // lucky number
        $wpdb->query("OPTIMIZE TABLE $wpdb->comments");
}

add_action('konkr_scheduled_delete', 'konkr_delete_old');

function konkr_check_db_comment($id, $recheck_reason = 'recheck_queue') {
    global $wpdb, $konkr_api_host, $konkr_api_port;

    $id = (int) $id;
    $c = $wpdb->get_row("SELECT * FROM $wpdb->comments WHERE comment_ID = '$id'", ARRAY_A);
    if (!$c)
        return;

    $c['user_ip'] = $c['comment_author_IP'];
    $c['user_agent'] = $c['comment_agent'];
    $c['referrer'] = '';
    $c['blog'] = get_option('home');
    $c['blog_lang'] = get_locale();
    $c['blog_charset'] = get_option('blog_charset');
    $c['permalink'] = get_permalink($c['comment_post_ID']);
    $id = $c['comment_ID'];
    if (konkr_test_mode())
        $c['is_test'] = 'true';
    $c['recheck_reason'] = $recheck_reason;

    $query_string = '';
    foreach ($c as $key => $data)
        $query_string .= $key . '=' . urlencode(stripslashes($data)) . '&';

    $response = konkr_http_post($query_string, $konkr_api_host, '/1.1/comment-check', $konkr_api_port);
    return $response[1];
}

function konkr_cron_recheck() {
    global $wpdb;

    delete_option('konkr_available_servers');

    $comment_errors = $wpdb->get_col("
		SELECT comment_id
		FROM {$wpdb->prefix}commentmeta
		WHERE meta_key = 'konkr_error'
		LIMIT 100
	");

    foreach ((array) $comment_errors as $comment_id) {
        // if the comment no longer exists, remove the meta entry from the queue to avoid getting stuck
        if (!get_comment($comment_id)) {
            delete_comment_meta($comment_id, 'konkr_error');
            continue;
        }

        add_comment_meta($comment_id, 'konkr_rechecking', true);
        $status = konkr_check_db_comment($comment_id, 'retry');

        $msg = '';
        if ($status == 'true') {
            $msg = __('Konkr caught this comment as spam during an automatic retry.');
        } elseif ($status == 'false') {
            $msg = __('Konkr cleared this comment during an automatic retry.');
        }

        // If we got back a legit response then update the comment history
        // other wise just bail now and try again later.  No point in
        // re-trying all the comments once we hit one failure.
        if (!empty($msg)) {
            delete_comment_meta($comment_id, 'konkr_error');
            konkr_update_comment_history($comment_id, $msg, 'cron-retry');
            update_comment_meta($comment_id, 'konkr_result', $status);
            // make sure the comment status is still pending.  if it isn't, that means the user has already moved it elsewhere.
            $comment = get_comment($comment_id);
            if ($comment && 'unapproved' == wp_get_comment_status($comment_id)) {
                if ($status == 'true') {
                    wp_spam_comment($comment_id);
                } elseif ($status == 'false') {
                    // comment is good, but it's still in the pending queue.  depending on the moderation settings
                    // we may need to change it to approved.
                    if (check_comment($comment->comment_author, $comment->comment_author_email, $comment->comment_author_url, $comment->comment_content, $comment->comment_author_IP, $comment->comment_agent, $comment->comment_type))
                        wp_set_comment_status($comment_id, 1);
                }
            }
        } else {
            delete_comment_meta($comment_id, 'konkr_rechecking');
            wp_schedule_single_event(time() + 1200, 'konkr_schedule_cron_recheck');
            return;
        }
    }

    $remaining = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->commentmeta WHERE meta_key = 'konkr_error'"));
    if ($remaining && !wp_next_scheduled('konkr_schedule_cron_recheck')) {
        wp_schedule_single_event(time() + 1200, 'konkr_schedule_cron_recheck');
    }
}

add_action('konkr_schedule_cron_recheck', 'konkr_cron_recheck');

function konkr_add_comment_nonce($post_id) {
    echo '<p style="display: none;">';
    wp_nonce_field('konkr_comment_nonce_' . $post_id, 'konkr_comment_nonce', FALSE);
    echo '</p>';
}

$konkr_comment_nonce_option = apply_filters('konkr_comment_nonce', get_option('konkr_comment_nonce'));

if ($konkr_comment_nonce_option == 'true' || $konkr_comment_nonce_option == '')
    add_action('comment_form', 'konkr_add_comment_nonce');

if ('3.0.5' == $wp_version) {
    remove_filter('comment_text', 'wp_kses_data');
    if (is_admin())
        add_filter('comment_text', 'wp_kses_post');
}
