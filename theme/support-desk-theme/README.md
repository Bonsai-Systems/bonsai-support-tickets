# Support Desk theme

The companion theme for the **Support Desk** plugin: a help centre, request form, client portal and team page, built with blocks. It takes its name, logo and colours from the plugin (**Support → Settings → Appearance**), so the site, the portal and every email match.

No other plugins needed (no ACF, no page builder).

## Requirements

- WordPress 6.2+, PHP 8.1+
- The **Support Desk** plugin for the ticket blocks, help articles and branding. The theme still works without it: ticket blocks show an editor-only notice, and the theme's default colours are used.

## Setup

1. Activate the Support Desk plugin. Under **Support → Settings → General → Get set up**, click **Install the Support Desk theme**, then **Activate the theme**. (The plugin keeps the theme updated; see "Customising".)
2. Click **Create starter pages** (shown on Support → Overview, Appearance → Themes and Pages). This:
   - creates **Home**, **Submit a request**, **My requests** and **Meet the team**, built from blocks (existing pages with the same address are left alone);
   - sets Home as the front page (only if the site was showing latest posts);
   - selects the request and portal pages in **Support → Settings** (only if they weren't already set);
   - creates a **Support menu** for the main navigation (only if none was assigned).
3. **Support → Settings → Appearance**: support name, logo and colours.
4. **Appearance → Customise → Support Desk theme**: header button, footer details, support hours, contact details and help centre text. **Site Identity** takes a separate logo for the site if you want one (otherwise the Support Desk logo is used, then the support name as text).
5. Add **Team** members (featured image = headshot, 3:4 portrait, at least 600 × 800).
6. Write help articles under **Support → Help articles** and give each a topic.

## Blocks

Pages use the block editor. The **Support Desk** category in the inserter has:

| Block | Use |
|---|---|
| Help search hero | Home page heading + help search + popular articles |
| Support links | Up to four cards: Submit a request, My requests, Help centre (linked automatically) or your own |
| Help topics | Topic cards with their top articles |
| My requests | The client's requests, or a log-in form |
| Submit a request | Guidance, tips and support hours beside the request form |
| Page header | Page title (H1) and lead text |
| Content | A section of normal blocks (text, lists, images, tables), H1 or H2 heading |
| FAQ | Accordion with FAQ schema |
| Feature cards | Two or three column cards |
| Process steps | Numbered steps |
| Call to action | Dark band with one or two buttons |
| Meet the team | Everyone, or selected people |

Each block's settings are in the sidebar, with a live preview on the page. Most have a **Background**: white, page background, tint (Customise → Colours) or dark.

A page built with Support Desk blocks prints them full width, one section each. A page without them (a privacy policy written with normal blocks, or a page with just a shortcode) gets a simple title + content layout.

New pages start with a Page header and a Content block.

## Help centre templates

| URL | Template |
|---|---|
| `/help/` | `archive-bst_article.php` (search, topics, articles without a topic, "Still stuck?") |
| `/help/topic/{topic}/` | `taxonomy-bst_article_topic.php` |
| `/help/{article}/` | `single-bst_article.php` (breadcrumbs, related articles) |
| `/?s=…&post_type=bst_article` | `search.php` |
| 404 | `404.php` (search + links) |

## Customising

Use a **child theme** for changes. Updates to Support Desk replace this theme's files.

- Override a block's markup: copy `template-parts/modules/{layout}.php` into the child theme. The block's attributes arrive as `$args`.
- Override a block's CSS: add `assets/css/modules/{layout}.css` to the child theme.
- Add or change blocks: `bsup_block_definitions` filter (see `inc/blocks.php` for the field types).

## Structure

```
inc/            one file per concern (see functions.php for load order)
template-parts/ header, footer, help partials, block templates (modules/)
assets/css/     base, header, footer, help, editor, fonts, modules/
assets/js/      main.js (jQuery: header, mobile menu, reveal, FAQ), blocks.js (block editor)
assets/fonts/   Plus Jakarta Sans and Bricolage Grotesque (SIL OFL 1.1)
```

## Filters

- `bsup_block_definitions`: the blocks and their fields.
- `bsup_default_page_blocks`: blocks pre-added to new pages.
- `bsup_hide_posts`: return `false` to show the Posts menu again.

## Notes

- Fonts are self-hosted: no requests to Google.
- Module CSS is only loaded on pages that use the block, in `<head>` (no layout shift).
- Help articles and team members use the classic editor.
- The login screen uses your logo and colours. It's left alone if another plugin defines `BWL_VERSION` (a white-label login plugin).
