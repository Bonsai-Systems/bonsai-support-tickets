# Changelog

All notable changes to this theme are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.2.0] - Unreleased

### Added
- Ships with the Support Desk plugin.
- 12 Support Desk blocks (help search hero, support links, help topics, my requests, submit a request, page header, content, FAQ, feature cards, process steps, call to action, meet the team), server-rendered with a live preview and sidebar settings. No build step.
- Customiser settings (Appearance → Customise → Support Desk theme): header label and button, footer logo, tagline, support hours, contact details, main website link, copyright, optional credit, help centre text, tint colour.
- Site logo via Site Identity, falling back to the Support Desk logo, then the support name.
- Brand colours follow Support → Settings → Appearance.
- Team details box (role, bio, LinkedIn) as plain post meta.
- Self-hosted fonts (Plus Jakarta Sans, Bricolage Grotesque), preloaded.
- New pages start with a Page header and a Content block.
- `bsup_block_definitions` and `bsup_default_page_blocks` filters.
- Theme screenshot.

### Changed
- Pages use the block editor. Help articles and team members stay on the classic editor.
- Neutral defaults: indigo palette, no company names, links or credits.
- Starter pages are built from blocks.
- Block library CSS is no longer removed (normal blocks inside Content need it).

### Removed
- ACF dependency: page builder, options page, field groups and the "needs ACF" notice.
- Google Fonts.
