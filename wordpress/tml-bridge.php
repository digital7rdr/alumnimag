<?php
/**
 * Plugin Name: TML Dashboard Bridge
 * Description: Lets drive.readthemissinglink.net set Divi/Extra layout meta on drafts it creates through the REST API.
 * Version: 1.0.0
 * Author: Digital7
 *
 * Install: copy this file to wp-content/mu-plugins/tml-bridge.php (create the mu-plugins folder if needed).
 */

if (!defined('ABSPATH')) exit;

add_action('init', function () {
    $keys = array('_et_pb_use_builder', '_et_pb_page_layout', '_tml_article_id');
    foreach ($keys as $key) {
        register_post_meta('post', $key, array(
            'type'          => 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function () { return current_user_can('edit_posts'); },
        ));
    }
});
