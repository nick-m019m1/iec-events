<?php
/**
 * Front-end output for IEC Events: the query, the [iec_events] shortcode and its CSS.
 */

defined('ABSPATH') || exit;

/**
 * The next $count events that have not finished yet.
 *
 * Filtering on the end time rather than the start is what keeps an 8-9pm
 * livestream in the list while it is actually happening, and drops it the
 * moment it finishes. This query is the entire archiving mechanism: there is
 * no cron job and no custom post status, so nothing can be late or fail.
 */
function iec_get_events($count) {
    return new WP_Query([
        'post_type'              => 'iec_event',
        'post_status'            => 'publish',
        'posts_per_page'         => $count,
        'meta_key'               => '_iec_end_utc',
        'orderby'                => 'meta_value',
        'order'                  => 'ASC',
        'meta_query'             => [[
            'key'     => '_iec_end_utc',
            'value'   => gmdate('Y-m-d H:i:s'),
            'compare' => '>=',
            'type'    => 'DATETIME',
        ]],
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'ignore_sticky_posts'    => true,
    ]);
}

/** Read a shortcode attribute written as 1/true/yes/on. */
function iec_flag($value) {
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * The stylesheet, returned once per request and empty every time after.
 *
 * Inline rather than enqueued: it saves an HTTP request, and pages without the
 * shortcode load nothing at all. Colours and fonts are inherited from Astra;
 * --iec-accent is the one hook for changing the date colour, and defaults to
 * currentColor rather than hardcoding a brand orange.
 */
function iec_css() {
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;

    return '<style id="iec-events-css">
.iec-events{--iec-accent:currentColor}
.iec-events-title{margin:0 0 .75em}
.iec-events-title.iec-align-left{text-align:left}
.iec-events-title.iec-align-center{text-align:center}
.iec-events-title.iec-align-right{text-align:right}
.iec-events-list{list-style:none;margin:0;padding:0}
.iec-event{display:grid;grid-template-columns:auto minmax(0,1fr);gap:.5em 1em;
 align-items:start;padding:.85em 0;border-bottom:1px solid rgba(0,0,0,.12)}
.iec-event.has-thumb{grid-template-columns:auto auto minmax(0,1fr)}
.iec-event:last-child{border-bottom:0}
.iec-event-date{text-align:center;line-height:1.15}
.iec-event-month{display:block;font-size:.75em;font-weight:700;text-transform:uppercase;
 letter-spacing:.06em;color:var(--iec-accent)}
.iec-event-day{display:block;font-size:1.6em;font-weight:700}
.iec-event-thumb img{display:block;width:4.5em;height:auto;border-radius:3px}
.iec-event-body{min-width:0}
.iec-event-time{display:block;font-size:.875em;opacity:.75}
.iec-event-title{display:block;font-weight:600}
.iec-event-title a{text-decoration:none}
.iec-event-title a:hover,.iec-event-title a:focus{text-decoration:underline}
.iec-event-desc{margin:.35em 0 0;font-size:.9em;opacity:.9}
</style>';
}

/**
 * [iec_events count="4" title="Upcoming Events (ET Time)" tz_label="ET"]
 *
 * Also accepts title_align (left, center or right — blank inherits the theme),
 * show_thumb, show_desc and class. Count, show_desc and title_align default to
 * whatever is saved on Events > Settings. Outputs nothing at all when no
 * event is upcoming, heading included: an empty "Upcoming Events" box on a
 * quiet week looks broken.
 */
function iec_events_shortcode($atts) {
    // Saved settings supply the defaults; an attribute on the shortcode wins.
    $options = iec_options();

    $atts = shortcode_atts([
        'count'       => $options['count'],
        'title'       => '',
        'title_align' => $options['title_align'],
        'tz_label'    => '',
        'show_thumb'  => 0,
        'show_desc'   => $options['show_desc'],
        'class'       => '',
    ], $atts, 'iec_events');

    $count      = max(1, min(12, (int) $atts['count']));
    $title      = trim((string) $atts['title']);
    $tz_label   = trim((string) $atts['tz_label']);
    $show_thumb = iec_flag($atts['show_thumb']);
    $show_desc  = iec_flag($atts['show_desc']);

    // Left blank the heading inherits the theme's alignment, as it always has.
    $align = strtolower(trim((string) $atts['title_align']));
    if (!in_array($align, ['left', 'center', 'right'], true)) {
        $align = '';
    }

    $query = iec_get_events($count);
    if (!$query->have_posts()) {
        return '';
    }

    $classes = ['iec-events'];
    foreach (preg_split('/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY) as $class) {
        $classes[] = sanitize_html_class($class);
    }

    $out = iec_css();
    $out .= '<div class="' . esc_attr(implode(' ', array_filter($classes))) . '">';

    if ('' !== $title) {
        $heading_class = 'iec-events-title' . ('' !== $align ? ' iec-align-' . $align : '');
        $out .= '<h3 class="' . esc_attr($heading_class) . '">' . esc_html($title) . '</h3>';
    }

    $out .= '<ul class="iec-events-list">';

    foreach ($query->posts as $event) {
        $out .= iec_event_row($event, $tz_label, $show_thumb, $show_desc);
    }

    return $out . '</ul></div>';
}
add_shortcode('iec_events', 'iec_events_shortcode');

/**
 * One <li>: the date block, then the time range and title.
 *
 * Times are rebuilt from the stored UTC value and shown in the event's own
 * timezone, so an event set in London reads in London time wherever the site
 * or the reader happens to be.
 */
function iec_event_row($event, $tz_label, $show_thumb, $show_desc) {
    $start_utc = (string) get_post_meta($event->ID, '_iec_start_utc', true);
    $end_utc   = (string) get_post_meta($event->ID, '_iec_end_utc', true);
    $tz        = (string) get_post_meta($event->ID, '_iec_timezone', true);

    // An empty string would be read as "now" by DateTimeImmutable, quietly
    // printing today's date. The query should never return such a row, but a
    // wrong date is worse than a missing one.
    if ('' === $start_utc || '' === $end_utc) {
        return '';
    }

    if (!array_key_exists($tz, iec_timezones())) {
        $tz = IEC_DEFAULT_TZ;
    }

    try {
        $utc   = new DateTimeZone('UTC');
        $zone  = new DateTimeZone($tz);
        $start = (new DateTimeImmutable($start_utc, $utc))->setTimezone($zone);
        $end   = (new DateTimeImmutable($end_utc, $utc))->setTimezone($zone);
    } catch (Exception $e) {
        return '';
    }

    // 'T' gives EST or EDT for the right half of the year; tz_label overrides it.
    $abbreviation = '' !== $tz_label ? $tz_label : $start->format('T');
    $range = $start->format('g:i a') . ' – ' . $end->format('g:i a') . ' ' . $abbreviation;

    $thumb = '';
    if ($show_thumb && has_post_thumbnail($event)) {
        $thumb = '<span class="iec-event-thumb">'
            . get_the_post_thumbnail($event, 'thumbnail', ['loading' => 'lazy'])
            . '</span>';
    }

    $title = esc_html(get_the_title($event));
    $link  = (string) get_post_meta($event->ID, '_iec_link', true);
    if ('' !== $link) {
        $title = '<a href="' . esc_url($link) . '">' . $title . '</a>';
    }

    $description = '';
    if ($show_desc) {
        $text = (string) get_post_meta($event->ID, '_iec_description', true);
        if ('' !== trim($text)) {
            $description = '<p class="iec-event-desc">' . nl2br(esc_html($text)) . '</p>';
        }
    }

    return '<li class="iec-event' . ('' !== $thumb ? ' has-thumb' : '') . '">'
        . '<span class="iec-event-date">'
        . '<span class="iec-event-month">' . esc_html($start->format('M')) . '</span>'
        . '<span class="iec-event-day">' . esc_html($start->format('j')) . '</span>'
        . '</span>'
        . $thumb
        . '<div class="iec-event-body">'
        . '<time class="iec-event-time" datetime="' . esc_attr($start->format('c')) . '">'
        . esc_html($range) . '</time>'
        . '<span class="iec-event-title">' . $title . '</span>'
        . $description
        . '</div>'
        . '</li>';
}
