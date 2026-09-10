# IEC Events

A lightweight upcoming-events list for WordPress. One custom post type, one shortcode,
no front-end JavaScript, no build step, no external dependencies.

Events are entered in the admin and displayed with a shortcode. An event drops off the
list by itself once it has finished — there is no cron job and no "past event" status to
maintain.

## Installation

Zip the three PHP files and upload via **Plugins → Add New → Upload Plugin**:

```powershell
Compress-Archive -Path iec-events.php, admin.php, render.php -DestinationPath iec-events.zip -Force
```

Only those three files are needed; this README and anything else in the repository can
stay out of the zip.

## The shortcode

There is exactly one shortcode:

```
[iec_events]
```

On its own it prints the next 4 upcoming events with no heading. Everything else is
optional.

### Attributes

| Attribute | Default | Accepts | What it does |
|---|---|---|---|
| `count` | Settings page (4) | 1–12 | How many events to show. Values outside the range are clamped, not rejected. |
| `title` | none | any text | Heading above the list, output as an `<h3>`. Omit it and no heading is printed. |
| `title_align` | Settings page (theme default) | `left`, `center`, `right` | Aligns **the heading only**, not the events. Anything unrecognised falls back to the theme's alignment. |
| `tz_label` | none | any text | Replaces the automatic timezone abbreviation. Use `ET` to avoid showing `EST` half the year and `EDT` the other half. |
| `show_desc` | Settings page (off) | `1`, `true`, `yes`, `on` / `0` | Shows each event's description under its title. Events without a description show nothing either way. |
| `show_thumb` | `0` | `1`, `true`, `yes`, `on` / `0` | Shows the event's Featured image to the left of the text. Events without one are unaffected. |
| `class` | none | CSS class names | Extra classes on the wrapping `<div>`, for targeting one instance in your own CSS. |

### Examples

The usual case — a heading, a fixed timezone label, four events:

```
[iec_events title="Upcoming Events (ET Time)" tz_label="ET"]
```

A longer list with descriptions, for a dedicated events page:

```
[iec_events count="12" title="All Upcoming Events" tz_label="ET" show_desc="1"]
```

A compact sidebar list — no heading, three events, no description:

```
[iec_events count="3"]
```

With images and a custom class for styling:

```
[iec_events count="4" show_thumb="1" class="homepage-events"]
```

Automatic timezone abbreviation (shows `EST` in January, `EDT` in July):

```
[iec_events count="4" title="Upcoming Events"]
```

## Settings page

**Events → Settings** sets the defaults for `count`, `show_desc` and `title_align`.

Settings are defaults only. **An attribute written on a shortcode always wins.** If a page
contains `[iec_events count="4"]`, changing "Events shown" on the settings page will not
affect that page until the `count="4"` is removed from the shortcode.

`title`, `tz_label`, `show_thumb` and `class` are shortcode-only — a heading is per-page by
nature, so it is not a global setting.

## Where to put it

The shortcode goes in a core **Shortcode** block, which can sit inside a Spectra container
like any other block. There is no custom block.

## Behaviour worth knowing

- **Nothing is printed when no events are upcoming** — the heading included. An empty
  "Upcoming Events" box on a quiet week looks broken.
- **Events disappear when they end, not when they start.** The list filters on the event's
  end time, so an 8–9pm session stays visible while it is actually running and drops off at
  9:01pm. Duration is what decides this.
- **Times display in the event's own timezone.** An event set in London reads in London
  time regardless of where the site or the reader is.
- **Descriptions are plain text.** Line breaks are preserved; HTML is not rendered.
- **Page caching will keep a finished event visible** until the cache clears, since the
  plugin does no cache invalidation.

## Styling

The CSS is a single inline `<style>` block, printed once per page and only on pages that
use the shortcode. Fonts and colours are inherited from the theme.

One custom property is exposed for the date block:

```css
.iec-events { --iec-accent: #e8720c; }
```

It defaults to `currentColor`, so the month abbreviation matches surrounding text until you
set it. Useful class names: `.iec-events`, `.iec-events-title`, `.iec-events-list`,
`.iec-event`, `.iec-event-date`, `.iec-event-month`, `.iec-event-day`, `.iec-event-thumb`,
`.iec-event-body`, `.iec-event-time`, `.iec-event-title`, `.iec-event-desc`.

Each row is a CSS Grid with no fixed widths, so it reflows inside a narrow column.

## Not included

Recurring events, venues, organisers, tickets, RSVPs, a calendar grid view, ICS export,
front-end submission, caching, cron jobs, or a custom block. This plugin does one job.
