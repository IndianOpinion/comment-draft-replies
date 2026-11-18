=== Comment Draft Replies ===
Contributors: indianopinion
Tags: comments, moderation, replies, ai, drafts
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Drafts a reply to each eligible reader comment with a language model and holds it for a moderator. Nothing is published without a person's click.

== Description ==

Readers ask questions in comments and editors rarely have time to answer. This plugin writes a first draft of a reply for each eligible comment, in the voice you describe and in the language of the comment, and puts it beside the comment for a moderator to publish, edit, regenerate or discard.

Written from scratch; not derived from any third-party plugin.

* Never publishes on its own. There is no setting, cron event, CLI command or REST route that does.
* Skips spam, trash, pingbacks, trackbacks, staff comments, comments with links or contact details, blocked words, and authors with comments in spam or trash. Every skip is recorded on the comment.
* Daily call cap, kill switch (option and constant), and per-thread, per-post and per-author limits.
* Drafts beside held comments, with one "Approve comment and publish reply" button.
* Sends only the post title and opening, up to three earlier comments in the thread and the comment itself to the provider. Never email, IP address or user agent.
* Works with any OpenAI-compatible endpoint, with your own key.

== Installation ==

1. Install with Composer (`composer install --no-dev` in the plugin folder), or copy a release of llm-json-php to `lib/llm-json-php/`, or supply your own caller through the `cdreplies_model_caller` filter (see `examples/http-api-caller.php`).
2. Activate the plugin.
3. Settings, Comment reply drafts: set the model. Put the key in `wp-config.php` as `CDREPLIES_PROVIDER_KEY`.

== Frequently Asked Questions ==

= Can it publish replies automatically? =

No, and it never will.

= Why did a comment get no draft? =

The reasons are stored on the comment in the `_cdreplies_skip` meta entry.

= What happens on uninstall? =

The table, options, scheduled events and the plugin's comment meta are removed on every site. Published replies stay. Define `CDREPLIES_KEEP_DATA` as true to keep everything.

== Privacy ==

Comment text, the article title and opening, and up to three earlier comments in the same thread are sent to the language model provider you configure. The plugin adds a suggested paragraph to the privacy policy guide and registers a personal data exporter and eraser.

== Changelog ==

= 0.1.0 =
* First release.
