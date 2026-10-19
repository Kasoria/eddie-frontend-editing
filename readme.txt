=== Eddie – Frontend Editing for Clients ===
Contributors: kasoria
Tags: frontend editing, client, inline editing, bricks, acf
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let clients edit texts and images directly on the website, while logged in.

== Description ==

Eddie adds a small editor bar to the frontend for users who have the `eddie_edit_frontend` capability (the plugin creates a "Client Editor" role). Click text to edit it in place, click an image or button to change it. Everything that cannot be edited safely links straight to the right place in the backend.

Natively editable:

* WordPress: post title, featured image
* Gutenberg blocks: paragraph, heading, list item, button, image
* Bricks: heading, text, rich text, button and image elements, including `{post_title}`, `{post_excerpt}`, `{featured_image}` and `{acf_field}` dynamic tags
* ACF: text, textarea, WYSIWYG, number, email, URL, image, gallery (add, remove and reorder images; the result shows after a reload), select, radio, button group, true/false (through the Bricks bridge, the `eddie_field()` function or the `[eddie_field]` shortcode)

Two save modes (Settings → Eddie): publish immediately with undo and a change log, or hold changes until an administrator approves them.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it through Plugins → Add New.
2. Activate the plugin. It creates the "Client Editor" role and gives administrators the editing capability.
3. Go to Settings → Eddie to choose the save mode, the content sources and which roles may edit.
4. Log in as a permitted user and open any page on the website: the Eddie bar appears at the bottom.

== Frequently Asked Questions ==

= Who can edit? =

Administrators, the "Client Editor" role and every role or single user you allow under Settings → Eddie or on the user's profile page. Each save is checked against WordPress' own permissions for the post, term or field.

= What happens with content Eddie cannot edit safely? =

It is highlighted on the page with a link that opens the right place in the backend, so nothing gets broken by an inline edit.

= Does Eddie work with page caching? =

Pages are marked as not cacheable (`DONOTCACHEPAGE`) only for people who see the editor. Visitors are not affected. Use the `eddie/content_updated` action to clear your cache after a change.

== Privacy ==

Eddie does not contact external services. Every change is stored in its own database table together with the ID of the user who made it, the old and new value and the address of the page it was made on. Finished entries are deleted after 180 days (filter `eddie/log_retention_days`); pending changes stay until they are approved or rejected. Deleting the plugin removes the table and all settings.

== Template helpers ==

`<?php eddie_the_field( 'headline' ); ?>` prints an ACF field. Editors get an editable wrapper, everyone else gets the plain value. The same works as `[eddie_field field="headline"]`.

== Hooks ==

* `eddie/sources` – add your own content source (extend `Eddie\Source`).
* `eddie/is_editing_context` – turn the editor on or off for a request.
* `eddie/mark_title` – disable the inline wrapper around the main post title.
* `eddie/acf_can_edit_options` – allow ACF options page fields to be edited from the frontend.
* `eddie/client_allowed_admin_pages` – wp-admin pages the Client Editor role may open.
* `eddie/content_updated` – fires after Eddie changed content (for cache plugins).

== Changelog ==

= 0.1.0 =
* First version.
