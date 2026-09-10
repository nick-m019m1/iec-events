<?php
/**
 * Plugin Name: IEC Events
 * Description: A lightweight upcoming-events list. Adds an Events post type and an [iec_events] shortcode.
 * Version:     1.0.0
 * Author:      Inside Edge Capital
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

/** Fallback timezone whenever none is chosen or an unknown one is submitted. */
const IEC_DEFAULT_TZ = 'America/New_York';

/**
 * The timezones offered in the meta box, and the only values accepted on save.
 *
 * Identifiers, never UTC offsets: an offset is wrong for half the year.
 *
 * @return array<string,string> identifier => label
 */
function iec_timezones() {
    return [
        'America/New_York'    => 'Eastern (New York)',
        'America/Chicago'     => 'Central (Chicago)',
        'America/Denver'      => 'Mountain (Denver)',
        'America/Los_Angeles' => 'Pacific (Los Angeles)',
        'Europe/London'       => 'London',
        'Europe/Sofia'        => 'Sofia',
        'UTC'                 => 'UTC',
    ];
}

/**
 * The event post type. Private on the front end: events are only ever shown
 * through the shortcode, so they need no permalinks, archive or search presence.
 */
function iec_register_post_type() {
    register_post_type('iec_event', [
        'labels' => [
            'name'               => 'Events',
            'singular_name'      => 'Event',
            'menu_name'          => 'Events',
            'all_items'          => 'All Events',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Event',
            'edit_item'          => 'Edit Event',
            'new_item'           => 'New Event',
            'search_items'       => 'Search Events',
            'not_found'          => 'No events found.',
            'not_found_in_trash' => 'No events found in Trash.',
        ],
        'public'              => false,
        'publicly_queryable'  => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_rest'        => false,
        'has_archive'         => false,
        'exclude_from_search' => true,
        'rewrite'             => false,
        'query_var'           => false,
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'menu_position'       => 25,
        'menu_icon'           => 'dashicons-calendar-alt',
        // No 'editor': the description is a plain textarea in the meta box.
        // Loading the block editor for an event is the weight we are removing.
        'supports'            => ['title', 'thumbnail'],
    ]);
}
add_action('init', 'iec_register_post_type');

/** Only someone who may edit the event may touch its meta. */
function iec_meta_auth($allowed, $meta_key, $post_id) {
    return current_user_can('edit_post', $post_id);
}

/** Declare the meta keys so WordPress knows their type and how to clean them. */
function iec_register_meta() {
    $keys = [
        '_iec_start_local'  => 'sanitize_text_field',
        '_iec_timezone'     => 'sanitize_text_field',
        '_iec_start_utc'    => 'sanitize_text_field',
        '_iec_end_utc'      => 'sanitize_text_field',
        '_iec_duration_min' => 'absint',
        '_iec_link'         => 'esc_url_raw',
        '_iec_description'  => 'sanitize_textarea_field',
    ];

    foreach ($keys as $key => $sanitize) {
        register_post_meta('iec_event', $key, [
            'type'              => '_iec_duration_min' === $key ? 'integer' : 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => $sanitize,
            'auth_callback'     => 'iec_meta_auth',
        ]);
    }
}
add_action('init', 'iec_register_meta');

/**
 * Save the meta box, recomputing both UTC values from scratch every time.
 *
 * The UTC keys are written as empty strings rather than deleted when there is no
 * date: an empty string can never satisfy the front-end ">= now" comparison, so a
 * dateless event stays off the page, while the admin list (which sorts on that
 * key) still shows the row.
 */
function iec_save_event($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!isset($_POST['iec_event_nonce'])
        || !wp_verify_nonce(sanitize_key($_POST['iec_event_nonce']), 'iec_save_event')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $date = isset($_POST['iec_date']) ? sanitize_text_field(wp_unslash($_POST['iec_date'])) : '';
    $time = isset($_POST['iec_time']) ? sanitize_text_field(wp_unslash($_POST['iec_time'])) : '';
    $tz   = isset($_POST['iec_timezone']) ? sanitize_text_field(wp_unslash($_POST['iec_timezone'])) : '';
    $link = isset($_POST['iec_link']) ? esc_url_raw(wp_unslash($_POST['iec_link'])) : '';
    $desc = isset($_POST['iec_description']) ? sanitize_textarea_field(wp_unslash($_POST['iec_description'])) : '';

    // Browsers send Y-m-d and H:i (some add seconds); anything else is not a date.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = '';
    }
    $time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) ? substr($time, 0, 5) : '';

    if (!array_key_exists($tz, iec_timezones())) {
        $tz = IEC_DEFAULT_TZ;
    }

    $duration = isset($_POST['iec_duration']) ? absint($_POST['iec_duration']) : 0;
    if ($duration < 1) {
        $duration = 60;
    }

    $start_local = '';
    $start_utc   = '';
    $end_utc     = '';

    if ('' !== $date) {
        $start_local = $date . ' ' . ('' === $time ? '00:00' : $time);
        try {
            $start = new DateTimeImmutable($start_local, new DateTimeZone($tz));
            $utc   = new DateTimeZone('UTC');

            $start_utc = $start->setTimezone($utc)->format('Y-m-d H:i:s');
            $end_utc   = $start->modify('+' . $duration . ' minutes')
                               ->setTimezone($utc)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $start_local = '';
            $start_utc   = '';
            $end_utc     = '';
        }
    }

    update_post_meta($post_id, '_iec_start_local', $start_local);
    update_post_meta($post_id, '_iec_timezone', $tz);
    update_post_meta($post_id, '_iec_start_utc', $start_utc);
    update_post_meta($post_id, '_iec_end_utc', $end_utc);
    update_post_meta($post_id, '_iec_duration_min', $duration);
    update_post_meta($post_id, '_iec_link', $link);
    update_post_meta($post_id, '_iec_description', $desc);
}
add_action('save_post_iec_event', 'iec_save_event');

require_once __DIR__ . '/render.php';

if (is_admin()) {
    require_once __DIR__ . '/admin.php';
}
