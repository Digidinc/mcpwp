# Elementor Widget Reference for MCPWP

Correct control keys for building pages via the API. Using wrong keys causes silent rendering failures.

## Element Structure

```
section/container (top-level)
  → column (only in sections)
    → widget
  → container (nested, only in containers)
    → widget
```

Every element needs: `id` (exactly 7 alphanumeric characters — Elementor Pro's Search live results and Taxonomy Filter/Loop Grid AJAX reject any other length; see `wp_get_guide(topic="elementor")`), `elType`, `settings`, `elements` (array, except widgets). Missing ids are generated; non-conforming ones are warned about, and rewritten only when you pass `repair_ids=true` to `wp_patch_elementor` or `wp_set_elementor`.

## Section Settings

```json
{
  "id": "abc1234",
  "elType": "section",
  "settings": {
    "background_background": "classic",
    "background_color": "#FFFFFF",
    "padding": {"unit": "px", "top": "70", "right": "0", "bottom": "70", "left": "0", "isLinked": false},
    "structure": "20"
  },
  "elements": [...]
}
```

| Key | Values | Notes |
|-----|--------|-------|
| `background_background` | `"classic"`, `"gradient"` | Required before `background_color` |
| `background_color` | `"#RRGGBB"` | |
| `padding` | `{unit, top, right, bottom, left, isLinked}` | |
| `structure` | `"20"` (2-col), `"30"` (3-col), `"40"` (4-col) | Required for multi-column |

## Column Settings

```json
{
  "id": "col1234",
  "elType": "column",
  "settings": {"_column_size": 50},
  "elements": [...]
}
```

| Key | Notes |
|-----|-------|
| `_column_size` | Percentage width. All columns in a section must sum to 100 |

## Free Widgets

### heading
```json
{"title": "Page Title", "header_size": "h1", "align": "center", "title_color": "#0B1220"}
```

### text-editor
```json
{"editor": "<p>Rich HTML content with <strong>bold</strong> and <a href='/'>links</a></p>"}
```

### html
```json
{"html": "<div style=\"text-align:center;\">Raw HTML here</div>"}
```

### image
```json
{
  "image": {"url": "https://example.com/img.png", "id": 123},
  "image_size": "full",
  "align": "center",
  "link_to": "none",
  "caption": ""
}
```

### button
```json
{
  "text": "Click Me",
  "link": {"url": "/page/", "is_external": false},
  "align": "center",
  "button_type": "default",
  "size": "md",
  "background_color": "#1B4DFF",
  "button_text_color": "#FFFFFF",
  "border_radius": {"unit": "px", "top": "8", "right": "8", "bottom": "8", "left": "8", "isLinked": true}
}
```

### icon-box
```json
{
  "selected_icon": {"value": "fas fa-check", "library": "fa-solid"},
  "title_text": "Feature Title",
  "description_text": "Feature description here",
  "position": "left",
  "icon_color": "#1B4DFF",
  "title_color": "#0B1220",
  "description_color": "#4A5568",
  "title_typography_typography": "custom",
  "title_typography_font_size": {"unit": "px", "size": 18},
  "title_typography_font_weight": "600"
}
```

**WARNING:** Do NOT use `title_size` — it crashes the renderer. Use `title_typography_font_size`. The plugin auto-renames this.

### counter
```json
{
  "starting_number": 0,
  "ending_number": 500,
  "prefix": "",
  "suffix": "+",
  "title": "Happy Customers",
  "number_color": "#1B4DFF",
  "title_color": "#4A5568"
}
```

### divider
```json
{"style": "solid", "weight": {"unit": "px", "size": 2}, "color": "#E2E8F0", "width": {"unit": "%", "size": 100}}
```

### spacer
```json
{"space": {"unit": "px", "size": 40}}
```

### icon
```json
{
  "selected_icon": {"value": "fas fa-star", "library": "fa-solid"},
  "view": "default",
  "primary_color": "#1B4DFF",
  "align": "center",
  "size": {"unit": "px", "size": 40}
}
```

### image-box
```json
{
  "image": {"url": "https://example.com/img.png"},
  "title_text": "Box Title",
  "description_text": "Description",
  "position": "top",
  "title_color": "#0B1220",
  "description_color": "#4A5568"
}
```

### social-icons
```json
{
  "social_icon_list": [
    {"social_icon": {"value": "fab fa-twitter", "library": "fa-brands"}, "link": {"url": "https://twitter.com/..."}},
    {"social_icon": {"value": "fab fa-github", "library": "fa-brands"}, "link": {"url": "https://github.com/..."}}
  ],
  "align": "center",
  "shape": "rounded",
  "icon_color": "custom",
  "icon_primary_color": "#FFFFFF",
  "icon_secondary_color": "#1B4DFF"
}
```

### tabs
```json
{
  "tabs": [
    {"tab_title": "Tab 1", "tab_content": "<p>Content 1</p>"},
    {"tab_title": "Tab 2", "tab_content": "<p>Content 2</p>"}
  ],
  "type": "horizontal"
}
```

### accordion
```json
{
  "tabs": [
    {"tab_title": "Question 1?", "tab_content": "<p>Answer 1</p>"},
    {"tab_title": "Question 2?", "tab_content": "<p>Answer 2</p>"}
  ],
  "selected_icon": {"value": "fas fa-plus", "library": "fa-solid"},
  "selected_active_icon": {"value": "fas fa-minus", "library": "fa-solid"}
}
```

### alert
```json
{"alert_type": "info", "alert_title": "Note", "alert_description": "Important info here", "show_dismiss": "show"}
```

### progress
```json
{"title": "Completion", "percent": {"unit": "%", "size": 75}, "display_percentage": "show", "bar_color": "#1B4DFF"}
```

### star-rating
```json
{"rating_scale": 5, "rating": 4.5, "star_style": "star_fontawesome", "title": "Our Rating"}
```

## Pro Widgets

### flip-box
```json
{
  "title_text_a": "Front Title",
  "description_text_a": "Front description",
  "title_text_b": "Back Title",
  "description_text_b": "Back description",
  "button_text": "Click Me",
  "link": {"url": "/page/", "is_external": false},
  "graphic_element": "icon",
  "selected_icon": {"value": "fas fa-rocket", "library": "fa-solid"},
  "icon_color": "#FFFFFF",
  "background_color_a": "#1B4DFF",
  "background_color_b": "#0B1220",
  "title_color_a": "#FFFFFF",
  "description_color_a": "#E2E8F0",
  "title_color_b": "#FFFFFF",
  "description_color_b": "#E2E8F0"
}
```

**WARNING:** Do NOT use `front_title_text`/`back_title_text`. Use `_a`/`_b` suffix. The plugin auto-renames these.

### animated-headline
```json
{
  "headline_style": "rotate",
  "animation_type": "typing",
  "before_text": "We build with",
  "rotating_text": "Claude\nChatGPT\nAI Assistants",
  "after_text": "",
  "highlighted_text": "AI",
  "header_size": "h2",
  "align": "center"
}
```

| `animation_type` values | `headline_style` values |
|------------------------|------------------------|
| typing, clip, flip, swirl, blinds, drop-in, wave, slide, slide-down | rotate, highlight |

### call-to-action
```json
{
  "title": "Get Started Today",
  "description": "Start your free trial",
  "button": "Sign Up Free",
  "link": {"url": "/signup/", "is_external": false},
  "skin": "classic",
  "layout": "left",
  "title_color": "#FFFFFF",
  "description_color": "#94A3B8",
  "button_color": "#FFFFFF",
  "button_background_color": "#1B4DFF"
}
```

### price-table
```json
{
  "heading": "Pro Plan",
  "sub_heading": "Most Popular",
  "price": "49",
  "currency_symbol": "$",
  "period": "/month",
  "features_list": [
    {"item_text": "Unlimited pages", "selected_item_icon": {"value": "fas fa-check", "library": "fa-solid"}},
    {"item_text": "Priority support", "selected_item_icon": {"value": "fas fa-check", "library": "fa-solid"}}
  ],
  "button_text": "Get Started",
  "link": {"url": "/pricing/"},
  "header_background_color": "#1B4DFF"
}
```

### countdown
```json
{
  "countdown_type": "due_date",
  "due_date": "2026-12-31 23:59",
  "show_days": "yes",
  "show_hours": "yes",
  "show_minutes": "yes",
  "show_seconds": "yes",
  "label_days": "Days",
  "label_hours": "Hours"
}
```

### posts / archive-posts
```json
{
  "posts_per_page": 6,
  "columns": 3,
  "excerpt_length": 20,
  "show_read_more": "yes",
  "read_more_text": "Read More"
}
```

### form
```json
{
  "form_name": "Contact Form",
  "form_fields": [
    {"field_type": "text", "field_label": "Name", "required": "true", "width": "100"},
    {"field_type": "email", "field_label": "Email", "required": "true", "width": "100"},
    {"field_type": "textarea", "field_label": "Message", "required": "false", "width": "100"}
  ],
  "button_text": "Send Message",
  "email_to": "hello@example.com"
}
```

### nav-menu
```json
{
  "menu": "main-menu",
  "layout": "horizontal",
  "align_items": "center",
  "pointer": "underline"
}
```

## Theme Builder Widgets (Pro)

These widgets are used in Elementor Pro Theme Builder templates (header, footer, single post, archive). All use the `theme-` prefix.

### theme-post-title
```json
{"title_tag": "h1", "align": "left", "title_color": "#0B1220", "link": "home"}
```

### theme-post-content
```json
{"align": "left"}
```

No settings required — renders the post content automatically.

### theme-post-excerpt
```json
{"align": "left"}
```

### theme-post-featured-image
```json
{"image_size": "large", "align": "center", "link": "none"}
```

| Key | Values | Notes |
|-----|--------|-------|
| `image_size` | `thumbnail`, `medium`, `large`, `full` | WordPress image size |
| `link` | `none`, `file`, `custom` | Image link behavior |

### theme-post-info
```json
{
  "layout": "default",
  "icon_list": [
    {"type": "author", "icon": {"value": "far fa-user-circle"}},
    {"type": "date", "icon": {"value": "far fa-calendar"}},
    {"type": "categories", "icon": {"value": "far fa-folder-open"}},
    {"type": "comments", "icon": {"value": "far fa-comment-dots"}}
  ],
  "text_color": "#4A5568"
}
```

| Key | Notes |
|-----|-------|
| `icon_list` | Array of meta items. `type`: `author`, `date`, `time`, `comments`, `terms`, `categories`, `tags`, `custom` |

### theme-post-navigation
```json
{
  "show_label": "yes",
  "prev_label": "Previous",
  "next_label": "Next",
  "show_arrow": "yes",
  "show_title": "yes",
  "show_borders": "yes"
}
```

### theme-archive-title
```json
{"title_tag": "h1", "align": "center"}
```

### theme-archive-posts
```json
{
  "columns": "3",
  "posts_per_page": 6,
  "show_image": "yes",
  "image_position": "top",
  "show_title": "yes",
  "show_excerpt": "yes",
  "show_read_more": "yes",
  "read_more_text": "Read More"
}
```

### theme-site-logo
```json
{"width": {"size": 200, "unit": "px"}, "align": "left"}
```

### theme-site-title
```json
{"title_tag": "h1", "link": "home"}
```

### theme-search-form
```json
{"skin": "classic", "placeholder": "Search...", "button_text": "Search"}
```

### theme-author-box
```json
{"show_avatar": "yes", "show_name": "yes", "show_biography": "yes", "show_link": "yes"}
```

### theme-builder-comments
```json
{}
```

No settings required — renders the comment form and list.

## Typography Pattern

All widgets follow the same pattern for typography controls:

```json
{
  "{control}_typography_typography": "custom",
  "{control}_typography_font_family": "Inter",
  "{control}_typography_font_size": {"unit": "px", "size": 16},
  "{control}_typography_font_weight": "600",
  "{control}_typography_line_height": {"unit": "em", "size": 1.5},
  "{control}_typography_letter_spacing": {"unit": "px", "size": 0}
}
```

Where `{control}` is the prefix like `title`, `description`, `button`, etc.

## Common Pitfalls

| Problem | Cause | Fix |
|---------|-------|-----|
| Page is blank | Missing `_elementor_template_type` meta | Plugin sets this automatically since v1.0.55 |
| Only first section renders | Invalid control key crashes renderer | Check warnings in save response |
| Flip-box shows defaults | Used `front_title_text` instead of `title_text_a` | Plugin auto-renames since v1.0.56 |
| Multi-column shows as single | Missing `structure` setting on section | Add `"structure": "20"` for 2-col etc |
| Containers don't render | Missing element IDs | Plugin auto-generates since v1.0.57 |
| Widget not found warning | Typo in `widgetType` | Check "did you mean?" in response |
| Cached old version | Server-side caching | Add `?v=timestamp` to URL, or use local WP |

## Testing Workflow

```bash
# 1. Edit PHP in mcp-for-wp/mcpwp/ (changes are live instantly via Docker volume)

# 2. Test locally (no rate limits, no cache, no deploy wait)
curl -s -X POST "http://localhost:8080/wp-json/mcpwp/v1/elementor/PAGE_ID" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"elementor_data": "..."}' | jq .warnings

# 3. Check rendering
curl -s "http://localhost:8080/?page_id=PAGE_ID" | grep -c 'elementor-section'

# 4. When satisfied, bump version and deploy to production
```

## Reusable Section Templates

Pre-built section templates saved in the Elementor library. Use these to maintain consistent design across pages.

### Available Templates (mcpwp.net)

| ID | Name | Widgets | Use For |
|----|------|---------|---------|
| 146 | MCPWP: CTA Section | heading, text-editor, button | Dark CTA blocks at page bottom |
| 147 | MCPWP: Feature Grid (3-col) | icon-box ×3 | Feature/benefit showcases |
| 148 | MCPWP: Pricing Card | price-table | Pricing sections |
| 149 | MCPWP: Testimonial Strip | testimonial ×3 | Social proof sections |
| 150 | MCPWP: FAQ Accordion | heading, accordion | FAQ sections |
| 151 | MCPWP: Hero Banner | heading, text-editor, button | Page hero sections |

### Workflow: Using Templates

```bash
# 1. List available templates
curl -s "$URL/elementor/templates" -H "X-API-Key: $KEY" | jq '.templates[] | select(.title | startswith("MCPWP:")) | {id, title, type}'

# 2. Get template data (to inspect or modify before use)
curl -s "$URL/elementor/templates/146" -H "X-API-Key: $KEY" | jq .elementor_data

# 3. Apply template to a page (copies Elementor data)
# NOTE: After applying, re-save via POST /elementor/{id} to set all required meta
curl -X POST "$URL/elementor/templates/146/apply" -H "X-API-Key: $KEY" \
  -d '{"page_id": PAGE_ID}'

# 4. Re-save to set _elementor_template_type and regenerate CSS
DATA=$(curl -s "$URL/elementor/templates/146" -H "X-API-Key: $KEY" | jq -r .elementor_data)
curl -X POST "$URL/elementor/PAGE_ID" -H "X-API-Key: $KEY" \
  -d "{\"elementor_data\": $DATA}"
```

### Combining Templates

To build a full page from templates, fetch each template's data and merge the section arrays:

```python
import json

# Fetch templates you want
hero_data = get_template(151)["elementor_data"]   # Hero Banner
features_data = get_template(147)["elementor_data"]  # Feature Grid
cta_data = get_template(146)["elementor_data"]     # CTA Section

# Merge into one page
hero = json.loads(hero_data) if isinstance(hero_data, str) else hero_data
features = json.loads(features_data) if isinstance(features_data, str) else features_data
cta = json.loads(cta_data) if isinstance(cta_data, str) else cta_data

full_page = hero + features + cta
# POST to /elementor/{page_id} with elementor_data = json.dumps(full_page)
```

### Design System

All templates use the site's design system:
- **Colors**: `#0B1220` (dark), `#1B4DFF` (primary), `#F6F8FF` (light bg), `#FFFFFF` (white), `#4A5568` (body text), `#94A3B8` (muted)
- **Font**: Poppins (via Elementor global fonts)
- **Padding**: Hero sections 80/40, body sections 70/70
- **Layout**: Section-based (classic Elementor mode)
