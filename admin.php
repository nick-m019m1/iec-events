<?php
/**
 * Admin screens for IEC Events: the event meta box and the list table.
 * Loaded from iec-events.php only when is_admin() is true.
 */

defined('ABSPATH') || exit;

/* -------------------------------------------------------------------------
 * Meta box
 * ---------------------------------------------------------------------- */

function iec_add_meta_box() {
    add_meta_box(
        'iec_event_details',
        'Event details',
        'iec_render_meta_box',
        'iec_event',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_iec_event', 'iec_add_meta_box');

function iec_render_meta_box($post) {
    $local = (string) get_post_meta($post->ID, '_iec_start_local', true);
    $date  = '';
    $time  = '';
    if (false !== strpos($local, ' ')) {
        list($date, $time) = explode(' ', $local, 2);
    }

    $tz = (string) get_post_meta($post->ID, '_iec_timezone', true);
    if (!array_key_exists($tz, iec_timezones())) {
        $tz = IEC_DEFAULT_TZ;
    }

    $duration = (int) get_post_meta($post->ID, '_iec_duration_min', true);
    if ($duration < 1) {
        $duration = 60;
    }

    $link = (string) get_post_meta($post->ID, '_iec_link', true);
    $desc = (string) get_post_meta($post->ID, '_iec_description', true);

    wp_nonce_field('iec_save_event', 'iec_event_nonce');
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="iec_date">Date &amp; time</label></th>
            <td>
                <input type="date" id="iec_date" name="iec_date" value="<?php echo esc_attr($date); ?>">
                <input type="time" id="iec_time" name="iec_time" value="<?php echo esc_attr($time); ?>">
                <p class="description">Local time in the timezone below, exactly as you would say it aloud.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="iec_timezone">Timezone</label></th>
            <td>
                <select id="iec_timezone" name="iec_timezone">
                    <?php foreach (iec_timezones() as $identifier => $label) : ?>
                        <option value="<?php echo esc_attr($identifier); ?>" <?php selected($tz, $identifier); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="iec_duration">Duration</label></th>
            <td>
                <input type="number" id="iec_duration" name="iec_duration" class="small-text"
                       min="1" step="1" value="<?php echo esc_attr((string) $duration); ?>"> minutes
                <p class="description">The event drops off the list once this much time has passed.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="iec_link">Link</label></th>
            <td>
                <input type="url" id="iec_link" name="iec_link" class="regular-text"
                       value="<?php echo esc_attr($link); ?>" placeholder="https://">
                <p class="description">Optional. The event title links here when set.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="iec_description">Description</label></th>
            <td>
                <textarea id="iec_description" name="iec_description" rows="2"
                          class="large-text"><?php echo esc_textarea($desc); ?></textarea>
                <p class="description">Optional, plain text.</p>
            </td>
        </tr>
    </table>
    <?php
}

/* -------------------------------------------------------------------------
 * List table
 * ---------------------------------------------------------------------- */

/** The event's own local time, formatted for the list table. */
function iec_admin_datetime($post_id) {
    $local = (string) get_post_meta($post_id, '_iec_start_local', true);
    if ('' === $local) {
        return '—';
    }

    $tz = (string) get_post_meta($post_id, '_iec_timezone', true);
    if (!array_key_exists($tz, iec_timezones())) {
        $tz = IEC_DEFAULT_TZ;
    }

    try {
        $start = new DateTimeImmutable($local, new DateTimeZone($tz));
    } catch (Exception $e) {
        return '—';
    }

    // 'T' gives the abbreviation for that date, so EST in January and EDT in July.
    return $start->format('M j, Y, g:i a T');
}

/**
 * Replace the built-in Date column, which shows the publish date, with the
 * event's own date. Two date columns side by side only invites mistakes.
 */
function iec_event_columns($columns) {
    $new = [];
    foreach ($columns as $key => $label) {
        if ('date' === $key) {
            continue;
        }
        $new[$key] = $label;
        if ('title' === $key) {
            $new['iec_datetime'] = 'Date &amp; time';
        }
    }
    if (!isset($new['iec_datetime'])) {
        $new['iec_datetime'] = 'Date &amp; time';
    }
    return $new;
}
add_filter('manage_iec_event_posts_columns', 'iec_event_columns');

function iec_event_column_content($column, $post_id) {
    if ('iec_datetime' === $column) {
        echo esc_html(iec_admin_datetime($post_id));
    }
}
add_action('manage_iec_event_posts_custom_column', 'iec_event_column_content', 10, 2);

function iec_event_sortable_columns($columns) {
    $columns['iec_datetime'] = 'iec_datetime';
    return $columns;
}
add_filter('manage_edit-iec_event_sortable_columns', 'iec_event_sortable_columns');

/**
 * Sort the list by event date by default, and apply the Upcoming / Past filter.
 *
 * The filter is the same _iec_end_utc comparison the front end uses, so the
 * archive needs no cron job and no custom post status.
 */
function iec_admin_pre_get_posts($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    if ('iec_event' !== $query->get('post_type')) {
        return;
    }

    $orderby = $query->get('orderby');
    if ('' === $orderby) {
        // No column clicked: soonest event first. The admin screen has already
        // defaulted order to DESC by this point, so set it explicitly.
        $query->set('meta_key', '_iec_start_utc');
        $query->set('orderby', 'meta_value');
        $query->set('order', 'ASC');
    } elseif ('iec_datetime' === $orderby) {
        // Column clicked: keep whichever direction the header link asked for.
        $query->set('meta_key', '_iec_start_utc');
        $query->set('orderby', 'meta_value');
    }

    $view = isset($_GET['iec_view']) ? sanitize_key(wp_unslash($_GET['iec_view'])) : '';
    if ('upcoming' === $view || 'past' === $view) {
        $meta_query   = (array) $query->get('meta_query');
        $meta_query[] = [
            'key'     => '_iec_end_utc',
            'value'   => gmdate('Y-m-d H:i:s'),
            'compare' => 'upcoming' === $view ? '>=' : '<',
            'type'    => 'DATETIME',
        ];
        $query->set('meta_query', $meta_query);
    }
}
add_action('pre_get_posts', 'iec_admin_pre_get_posts');

/* -------------------------------------------------------------------------
 * Settings page: Events > Settings
 * ---------------------------------------------------------------------- */

function iec_settings_menu() {
    add_submenu_page(
        'edit.php?post_type=iec_event',
        'Events Settings',
        'Settings',
        'manage_options',
        'iec-events-settings',
        'iec_settings_page'
    );
}
add_action('admin_menu', 'iec_settings_menu');

function iec_register_settings() {
    register_setting('iec_events', IEC_OPTION, [
        'type'              => 'array',
        'sanitize_callback' => 'iec_sanitize_options',
        'default'           => iec_option_defaults(),
    ]);
}
add_action('admin_init', 'iec_register_settings');

/** Never trust the form: rebuild the option from known-good values only. */
function iec_sanitize_options($input) {
    $clean = iec_option_defaults();
    $input = is_array($input) ? $input : [];

    $count          = isset($input['count']) ? absint($input['count']) : $clean['count'];
    $clean['count'] = max(1, min(12, $count));

    $clean['show_desc'] = empty($input['show_desc']) ? 0 : 1;

    $align = isset($input['title_align']) ? strtolower(trim((string) $input['title_align'])) : '';
    $clean['title_align'] = in_array($align, ['left', 'center', 'right'], true) ? $align : '';

    return $clean;
}

function iec_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $options = iec_options();
    ?>
    <div class="wrap">
        <h1>Events Settings</h1>
        <p>
            Defaults for the <code>[iec_events]</code> shortcode. An attribute written on an
            individual shortcode overrides the setting here, so one page can differ from another.
        </p>

        <form method="post" action="options.php">
            <?php settings_fields('iec_events'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="iec_count">Events shown</label></th>
                    <td>
                        <input type="number" id="iec_count"
                               name="<?php echo esc_attr(IEC_OPTION); ?>[count]"
                               class="small-text" min="1" max="12" step="1"
                               value="<?php echo esc_attr((string) $options['count']); ?>">
                        <p class="description">Between 1 and 12. Overridden by <code>count="6"</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Description</th>
                    <td>
                        <label>
                            <input type="checkbox" value="1"
                                   name="<?php echo esc_attr(IEC_OPTION); ?>[show_desc]"
                                   <?php checked(1, (int) $options['show_desc']); ?>>
                            Show each event's description under its title
                        </label>
                        <p class="description">
                            Only events that have a description show one. Overridden by
                            <code>show_desc="1"</code> or <code>show_desc="0"</code>.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="iec_title_align">Heading alignment</label></th>
                    <td>
                        <select id="iec_title_align" name="<?php echo esc_attr(IEC_OPTION); ?>[title_align]">
                            <?php
                            $choices = [
                                ''       => 'Theme default',
                                'left'   => 'Left',
                                'center' => 'Centred',
                                'right'  => 'Right',
                            ];
                            foreach ($choices as $value => $label) :
                                ?>
                                <option value="<?php echo esc_attr($value); ?>"
                                    <?php selected($options['title_align'], $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Aligns the list heading only, not the events. Overridden by
                            <code>title_align="center"</code>.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h2>Using it on a page</h2>
        <p>
            Add a Shortcode block and paste <code>[iec_events title="Upcoming Events (ET Time)" tz_label="ET"]</code>.
            Other attributes: <code>count</code>, <code>show_desc</code>, <code>title_align</code>,
            <code>show_thumb</code>, <code>class</code>. Leave <code>tz_label</code> off to show
            EST or EDT automatically.
        </p>
    </div>
    <?php
}

/** All | Upcoming | Past links above the list table. */
function iec_event_views($views) {
    $current = isset($_GET['iec_view']) ? sanitize_key(wp_unslash($_GET['iec_view'])) : '';
    $base    = admin_url('edit.php?post_type=iec_event');

    // The core All link carries no iec_view, so it clears the filter when clicked.
    if ('' !== $current && isset($views['all'])) {
        $views['all'] = str_replace([' class="current"', ' aria-current="page"'], '', $views['all']);
    }

    foreach (['upcoming' => 'Upcoming', 'past' => 'Past'] as $slug => $label) {
        $class = $current === $slug ? ' class="current" aria-current="page"' : '';

        $views['iec_' . $slug] = sprintf(
            '<a href="%s"%s>%s</a>',
            esc_url(add_query_arg('iec_view', $slug, $base)),
            $class,
            esc_html($label)
        );
    }

    return $views;
}
add_filter('views_edit-iec_event', 'iec_event_views');
