# comment-draft-replies

A WordPress plugin that drafts a reply to each eligible reader comment with a
language model and holds it beside the comment for a moderator. Nothing is
published until a logged-in person clicks, because a model can be wrong, can
be baited, and speaks in the site's name.

Written from scratch; not derived from any third-party plugin.

Measured result: none yet. This is a new feature written from an idea; the
rules in it come from running rules-only moderation on a site that holds
every comment.

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party
plugin it integrates with.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Hooks and commands](#hooks-and-commands)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

PHP 8.1 or later, WordPress 6.4 or later.

With Composer (recommended, pulls in the model client
[`indianopinion/llm-json-php`](https://github.com/IndianOpinion/llm-json-php)):

```sh
cd wp-content/plugins
git clone https://github.com/IndianOpinion/comment-draft-replies.git
cd comment-draft-replies
composer install --no-dev
```

Then activate it under Plugins. On a network, network-activate it; every
existing site is set up and new sites are set up when they are created.

Without Composer, two choices:

1. Copy a release of `llm-json-php` to `lib/llm-json-php/` inside the plugin
   folder. The plugin's own `autoload.php` loads its `bootstrap.php`.
2. Run without the library and supply your own caller through the
   `cdreplies_model_caller` filter. A working caller that uses the WordPress
   HTTP API is in [`examples/http-api-caller.php`](examples/http-api-caller.php).
   It is not loaded by default; copy it to `wp-content/mu-plugins/`.

As a must-use plugin: copy the plugin folder to
`wp-content/mu-plugins/comment-draft-replies/` and
[`examples/mu-loader.php`](examples/mu-loader.php) to `wp-content/mu-plugins/`.
The table and the scheduled events are created on the first admin page load.

## Quick start

1. Settings, Comment reply drafts: set the model (for example
   `openai/gpt-4o-mini` on the default OpenRouter endpoint). Put the key in
   `wp-config.php`:

   ```php
   define('CDREPLIES_PROVIDER_KEY', 'your-key');
   ```

2. Optional: write a few lines of voice notes ("Plain, warm, short. Sign
   nothing.").
3. Wait for a reader comment. Within a minute or so a draft appears under
   Comments, Reply drafts, and below the comment on the Comments screen.
4. Read it. Publish it as written, edit it and publish, regenerate it with a
   note, or discard it. A held comment gets one button, "Approve comment and
   publish reply".

To stop everything at once:

```php
define('CDREPLIES_DISABLED', true);
```

## Configuration

Settings, Comment reply drafts (needs `manage_options`). Every value is
checked on save; a bad value is refused with a message and the old one is
kept.

| Setting | Default | Notes |
|---|---|---|
| Kill switch | off | `CDREPLIES_DISABLED` forces it on and locks the box |
| Provider base URL | `https://openrouter.ai/api/v1` | Any OpenAI-compatible endpoint, https only |
| Provider key | empty | `CDREPLIES_PROVIDER_KEY` is preferred; the field is write-only and shows the last four characters |
| Model | empty | Must be set; no model is guessed |
| Fallback model | empty | Optional second route on the same endpoint |
| Voice notes | empty | Up to 2000 characters |
| Send commenter names | off | Otherwise the prompt says "a reader" |
| Daily cap (calls) | 30 | Every call counts, retries and regenerations included. 0 means no generation. `CDREPLIES_DAILY_CAP` overrides |
| Per thread limit | 2 per 24 h | Count 0 turns a limit off |
| Per post limit | 6 per 1 h | |
| Per author limit | 3 per 24 h | By user id, else email address (stored only as a salted hash) |
| Minimum comment length | 15 | Unicode characters |
| Maximum comment length | 5000 | |
| Skip comments with links | on | URLs, `www.`, `<a`, bare domains, author website |
| Skip comments with contact details | on | Email addresses and phone numbers |
| Blocked words | empty | One per line; core's disallowed comment keys also apply |
| Reply maximum length | 1000 | For model output |
| Queue age limit | 48 h | Queued drafts older than this expire |
| Draft age limit | 14 days | Ready drafts older than this expire |
| Reply author | the moderator who clicks | Or one named account; publishing as it needs `edit_others_posts` |
| Approve comment and publish reply button | on | Turn off to keep moderation and replies apart |
| Log file | none | Absolute path for JSON lines; otherwise warnings go to PHP's error log |

Constants:

| Constant | Effect |
|---|---|
| `CDREPLIES_DISABLED` | `true` forces the kill switch on |
| `CDREPLIES_PROVIDER_KEY` | Provider key |
| `CDREPLIES_DAILY_CAP` | Integer, overrides the setting |
| `CDREPLIES_KEEP_DATA` | `true` keeps everything on uninstall |

## How it works

```
reader posts comment
  -> intake (comment_post): rules only, no network, at most 3 reads and 2 writes
       skip: reason codes saved on the comment (_cdreplies_skip)
       pass: draft row "queued", WP-Cron event in 30 s (or a gen-queue job)
  -> runner (cron every 5 min, or WP-CLI, or gen-queue)
       claim -> re-check comment and post -> switch, hold, meter, cap
       -> one model call -> validate -> "ready" / "declined" / "failed"
  -> moderator (Comments screen or Reply drafts screen)
       publish / edit and publish / approve comment and publish / regenerate / discard
       publish goes through core's wp_new_comment(), as the moderator
```

- **Intake rules.** Ordinary comments only (the empty type and `comment`),
  not spam or trash, not staff (decided by user id), not the plugin's own
  replies, on a published, public post of an allowed type with comments
  open, between the length bounds, with letters in some script, without
  links, contact details or blocked words, from an author with nothing in
  spam or trash, within the rate limits and the daily cap, with the switch
  off. Every failing rule is recorded. `cdreplies_should_draft` can veto.
- **What the model sees.** Site name and language, voice notes, post title
  and excerpt (or the first 1500 characters), up to three earlier comments
  in the thread, the comment, and the commenter's name only when you allow
  it. Never email, IP address, user agent, website, user id or other
  comments by the same person. Reader text sits inside marked data blocks
  the prompt says not to obey.
- **What a reply must pass.** Not empty, not over the length limit, no link
  to a host outside the allowed list (default: your own host), no email or
  phone number, no invisible or control characters, no prompt markers, not
  a copy of the comment. One retry, then `failed`. A reply in a different
  writing system from the comment is flagged `script_mismatch`, never
  blocked.
- **Publishing.** Only through the REST route, only with a logged-in browser
  cookie for the same user, the REST nonce and the plugin's own nonce, and
  only for users with `moderate_comments` and `edit_post` on the post.
  Application passwords get `401`. One conditional update decides the
  winner when two moderators click at once. Core sends exactly the
  notifications it sends for any hand-written reply.
- **Spending.** A daily call cap counted with an atomic compare-and-swap,
  a kill switch read fresh before every call, a one-hour hold after an
  authentication or credit error, and backoff (1, 5, 15 minutes) on
  timeouts, 429 and 5xx.

Design notes and every decision are in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md); the requirements are in
[`docs/PRD.md`](docs/PRD.md).

## Hooks and commands

Actions: `cdreplies_skipped`, `cdreplies_queued`, `cdreplies_generated`,
`cdreplies_failed`, `cdreplies_declined`, `cdreplies_published`,
`cdreplies_discarded`, `cdreplies_stale`, `cdreplies_cap_reached`,
`cdreplies_provider_hold`, `cdreplies_log`.

Filters (default in brackets): `cdreplies_should_draft` (veto only),
`cdreplies_comment_types` (`''`, `comment`), `cdreplies_post_types`
(`post`, `page`), `cdreplies_allow_closed` (false), `cdreplies_link_tlds`,
`cdreplies_allowed_reply_hosts` (site host), `cdreplies_prompt_values`,
`cdreplies_post_context_chars` (1500), `cdreplies_max_output_tokens` (600),
`cdreplies_batch_size` (10), `cdreplies_run_seconds` (20),
`cdreplies_backoff` (60, 300, 900), `cdreplies_hold_seconds` (3600),
`cdreplies_max_regenerations` (3), `cdreplies_backfill_max_days` (7),
`cdreplies_retention_days` (90), `cdreplies_capability`
(`moderate_comments`), `cdreplies_settings_capability` (`manage_options`),
`cdreplies_reply_author_capability` (`edit_others_posts`),
`cdreplies_client`, `cdreplies_model_caller`.

Functions: `cdreplies_get_draft()`, `cdreplies_evaluate()`,
`cdreplies_queue()`, `cdreplies_is_enabled()`, `cdreplies_calls_today()`,
`cdreplies_cap_remaining()`. There is no publish function.

REST, namespace `comment-draft-replies/v1`: `GET /drafts`,
`GET /drafts/<id>`, `POST /drafts/<id>/publish`, `POST /drafts/<id>/discard`,
`POST /drafts/<id>/regenerate`.

WP-CLI, `wp comment-draft-replies`: `status`, `list`, `show`, `run`,
`backfill --since=<date>`, `discard`, `kill on|off`, `cap <n>`,
`hold clear`, `purge`. Exit codes: 0 done, 1 error, 3 stopped by the kill
switch, 4 stopped at the cap. There is no publish command.

Optional integrations, each detected at run time and never required:
`gen-queue` (runs generation jobs), `spend-meter` (asked before every call),
`prompt-library` (supplies the template `comment-draft-replies-reply`).

## Limitations

- Comments inserted with `wp_insert_comment()` directly (imports, other
  plugins) do not fire `comment_post` and get no draft. Use
  `wp comment-draft-replies backfill` on purpose.
- Rate limits and the cap count drafts and calls, not money. Use
  `spend-meter` if you need a cost ceiling.
- The script check knows the common scripts by Unicode property; it is a
  hint, not a language detector.
- The integrations follow the published 0.1.0 interfaces of `gen-queue`
  (`GEN_QUEUE_VERSION`, `gen_queue_enqueue_job`,
  `gen_queue_register_type()` on `gen_queue_register_types`),
  `spend-meter` (contract `SPEND_METER_API` 1, `spend_meter_pre_spend`,
  `spend_meter_record`, `spend_meter_release`) and `prompt-library`
  (`promptlib_render`, `promptlib_register_default()`). Each lives in one
  adapter class under `src/Integration/`.
- Drafts are kept for 90 days after they end. Published replies are
  ordinary comments and follow core's rules.

## FAQ

**Can it publish on its own if I trust it?** No. There is no setting,
constant, filter, cron event, CLI command or REST route for that, and a test
walks every one of them to prove it.

**Why did this comment get no draft?** Open the comment's meta or run
`wp comment-draft-replies status`; every skipped comment carries
`_cdreplies_skip` with the reason codes, time and lane.

**Does a reader ever see a draft?** No. Drafts live in their own table and
are shown only on the two admin screens, to users who can review that post.

**Will approving a held comment send mail?** Core sends what it always
sends when a comment is approved and when a reply is posted. The plugin
sends nothing itself.

**Where does the comment text go?** To the provider you configure, over
https, and nowhere else. The plugin adds a paragraph to the privacy policy
guide saying so.

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.

Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md).
