# comment-draft-replies: architecture

This document describes how 0.1.0 is built from [PRD.md](PRD.md): the
modules, the data flow, and every decision, including the answers to the
PRD's open questions.

## Modules

| Path | Role |
|---|---|
| `comment-draft-replies.php` | Plugin header, constants, autoload, activation hooks, boot |
| `autoload.php` | Autoloader for use without Composer; loads `lib/llm-json-php/` when present |
| `uninstall.php` | Uninstall on every site unless `CDREPLIES_KEEP_DATA` |
| `src/Plugin.php` | Builds the services once and registers every hook |
| `src/Settings.php` | Defaults, fresh reads, constants, validation on save |
| `src/OptionStore.php` | Raw option reads and compare-and-swap, bypassing caches |
| `src/DailyCap.php` | Per-day call counter in the site timezone |
| `src/Hold.php` | Global provider hold |
| `src/Logger.php` | `cdreplies_log`, optional JSON-lines file, whitelist of context keys |
| `src/Clock.php` | Time source (frozen in tests), UTC storage, site-timezone days |
| `src/Schema.php` | Table definition, install, read-back verification and repair |
| `src/Draft.php`, `src/Decision.php` | Value objects of section 5.2 |
| `src/DraftRepository.php` | Every query on the drafts table; conditional status moves |
| `src/Comments.php` | Thread root, depth, reply parent, staff, author key, author history |
| `src/Text.php` | Pure text rules: length, links, contact, blocklist, invisible characters, script check |
| `src/Intake.php` | Section 4.2 |
| `src/Generation/` | Runner, prompt, validator, caller interface, llm-json-php caller, filter caller |
| `src/Integration/` | One adapter each for gen-queue, spend-meter, prompt-library |
| `src/Review/Publisher.php` | Publish, discard, regenerate: the only place a reply is created |
| `src/Rest/Controller.php` | REST routes and the authentication method check |
| `src/Lifecycle.php` | Comment and post state changes |
| `src/Housekeeping.php` | Expiry, abandoned claims, retention |
| `src/Backfill.php` | The backfill lane |
| `src/Installer.php` | Activation, deactivation, multisite, uninstall |
| `src/Privacy.php` | Exporter, eraser, policy text |
| `src/Admin/` | Reply drafts screen, inline panel, settings, notices, Site Health |
| `src/Cli/Command.php` | `wp comment-draft-replies` |
| `assets/` | Panel script and styles |
| `examples/` | HTTP API caller, must-use loader, common filters |

## Data flow

1. `comment_post` (priority 20) calls `Intake::onCommentPost`, wrapped in a
   catch-all so a reader's comment is never affected (case 65).
2. `Intake::evaluate` runs every rule and collects every failing code. Its
   own database work is three reads: one for the settings and the counter
   together, one grouped query on the drafts table (does a row exist, and
   the three rate-window counts), and one for the author's history. The
   thread root comes from `get_comment()`, which core caches.
3. An eligible comment gets one `INSERT IGNORE` row; the unique key on
   `comment_id` makes a second `comment_post` a no-op (case 18). A skip
   writes one comment meta entry. `Runner::wake` enqueues a gen-queue job
   or schedules one WP-Cron event 30 seconds out (if none is pending); a
   five-minute recurring event is the safety net.
4. `Runner::run` checks the switch, the hold, availability and the cap,
   recovers claims older than ten minutes, then claims due drafts one by
   one with a conditional update that stores a random token. Every later
   write is conditional on that token.
5. `Runner::process` re-checks the comment and the post, reads the switch
   and hold fresh, asks spend-meter, takes a cap slot, calls the model,
   records the spend, validates, and stores the result.
6. A moderator acts through the REST routes from the panel script.
   `Publisher` re-checks everything and moves the draft with one
   conditional update before creating the reply with `wp_new_comment()`.

## Decisions on the open questions (owner rulings)

1. **Draft storage: custom table.** Drafts never touch the comments table,
   so they cannot leak into comment queries, counts, feeds, the comments
   REST endpoints or any page cache, and rate-limit counts are one indexed
   query. A custom post type would put model text in `wp_posts`, where
   search, sitemaps and many plugins would find it.
2. **Thread depth: attach to the parent.** When threading is on and the
   comment sits at the maximum depth, the reply's parent is the comment's
   own parent, as WordPress itself does for replies at that depth, so the
   theme shows the reply beside the comment instead of nesting past the
   limit. Below the maximum, and when threading is off, the parent is
   the comment itself. This overrides case 52 as first written; test T52
   checks the ruling.
3. **Language check: yes, built in, flag only.** `Text::dominantScript`
   counts letters per Unicode script (27 scripts, kana folded into
   Japanese, Han matching Japanese). When the comment and the reply each
   have at least five letters and their dominant scripts differ, the draft
   gets the flag `script_mismatch`. It never blocks or fails a draft.
4. **Reply author default: the moderator who clicks.** One named account
   can be set instead. Publishing as that account needs the filterable
   capability `edit_others_posts` (decision below).
5. **"Approve comment and publish reply": on by default**, with the setting
   "Approve comment and publish reply button" to turn it off. When off, a
   held comment must be approved through core first.
6. **Editor note: cleared at publish.** The note is only an instruction for
   the next generation; keeping it after publishing adds stored text with no
   use. It stays while the draft is not yet published.
7. **Example caller: shipped, not loaded.** `examples/http-api-caller.php`
   is a must-use plugin that calls an OpenAI-compatible endpoint through the
   WordPress HTTP API and maps HTTP failures to the three error codes. It is
   tested (`ExampleCallerTest`) but never loaded by the plugin.
8. **Default limits: kept.** Cap 30 calls a day, 2 per thread per 24 hours,
   6 per post per hour, 3 per author per 24 hours.

## Other decisions

- **Counter storage.** `cdreplies_calls` holds JSON: day, the timezone the
  day began in, calls, and whether the cap line was logged. Each increment
  is a compare-and-swap on the whole value (`UPDATE ... WHERE option_value
  = old`), retried up to eight times, failing closed. Judging "today" in
  the timezone the day began in is what makes a timezone change wait for
  the next day boundary (case 69).
- **Fresh reads.** The kill switch, cap counter and hold are read with raw
  queries on the options table, never through `get_option` or the object
  cache (case 54, section 10).
- **Rejected output and the cap.** Each of the two attempts takes its own
  cap slot and its own spend-meter reservation; a denied meter answer
  releases nothing because nothing was reserved; a cap refusal after a meter
  approval fires the meter's release action.
- **Retries.** Retryable errors set `run_after` to now plus the backoff for
  the attempt number and go back to `queued`. The third failure, or a retry
  that would land beyond the queue age limit, ends in `failed` with
  `provider`. Under gen-queue the plugin returns the draft to `queued` and
  throws the queue's exception type, so the queue's own backoff applies.
- **Publish claim.** The draft moves `ready` to `published` first; if
  approving the parent or core's insert then fails, it moves back to
  `ready` and nothing is published. This is the only way two concurrent
  clicks can produce exactly one reply without a lock table.
- **Expiry at publish time.** Publishing checks the draft age itself, so a
  15-day-old draft is refused even when housekeeping has not run yet.
- **Authentication method.** Publish requires that the logged-in cookie
  validates to the current user and that no application password
  authenticated the request (`401 cdreplies_auth_method`), plus core's REST
  cookie nonce and the plugin's own nonce `cdreplies_action` in the
  `X-Cdreplies-Nonce` header. Discard and regenerate need the plugin nonce
  and the capabilities but accept any authentication, since they spend
  nothing irreversible and publish nothing.
- **Reply author capability.** The PRD asks that the moderator "can edit
  that user's comments". In core every comment on a post is editable by
  whoever can edit the post, which the moderator already must; so the
  check uses `edit_others_posts` (filter
  `cdreplies_reply_author_capability`) as the meaningful bar for speaking
  as another account.
- **Own replies.** A reply created by the plugin is skipped at intake with
  `own_reply` through an in-process flag set around `wp_new_comment()`
  (the provenance meta is written after the insert); backfill also checks
  the provenance meta.
- **Draft anyway.** Stored as the flag `anyway` until the next generation;
  the prompt then says the editor wants a reply.
- **Deleted post.** Handled per draft as a deleted comment: `stale` with
  `comment_gone` and text cleared. Core deletes the post's comments too.
- **Retention clock.** Purge age is measured from `created_at`. Purged rows
  are added to `cdreplies_daily_stats` by creation day and status.
- **Privacy erase.** Clears reply, original reply, editor note and reason,
  and sets `last_reason` to `erased`. The row and its status stay for the
  counts.
- **Schema repair.** dbDelta can skip a column add silently, so after it
  runs the plugin reads `SHOW COLUMNS`, adds each missing column with an
  explicit `ALTER TABLE`, and reads back again. A failed intake write marks
  the schema broken; the next admin page load repairs it. Admin loads also
  re-verify once an hour.
- **Log file.** Written only if its directory is writable; paths with `..`
  or a PHP extension are refused at save time.
- **Integration names.** Checked at review against the published 0.1.0
  READMEs and PRDs of the three projects. gen-queue: detected by
  `GEN_QUEUE_VERSION`; jobs go through the soft enqueue filter
  `gen_queue_enqueue_job` (job id, null when absent, `WP_Error` on
  failure); the type is registered with `gen_queue_register_type()` on
  `gen_queue_register_types`, and the handler takes the job and its
  context; failures map to its `RetryableException` (message, retry
  after, code, previous), `PermanentException` and `AccountException`;
  usage goes to `JobContext::addCost(0, [...])` with `metered` true when
  spend-meter recorded the call (this plugin holds no prices).
  spend-meter: contract constant `SPEND_METER_API` equal to 1; the intent
  carries `purpose`, `caller`, `provider` (the endpoint host as a slug),
  `model`, `est_input_tokens` and `max_output_tokens`; a null decision
  means no meter; anything but `allowed === true` is a deny; each attempt
  is recorded with a UUID `call_id`, the attempt number and the
  reservation id, and token counts only when the response had them.
  prompt-library: `promptlib_render` with the slug, the values, `live`
  and the consumer id; a `WP_Error` (for example `promptlib_not_found`)
  falls back to the built-in prompt; the default definition is shipped in
  the `prompt-library/1` format with `context` (the built-in body, reader
  text already fenced) as its only required variable.
- **Intake lock.** Intake counts the rate windows and inserts the row
  under one MySQL named lock per site (`GET_LOCK`, two-second wait), so two
  comments in the same thread, post or by the same author that arrive
  together cannot both pass a limit. If the lock is not granted in time,
  intake goes on without it and logs once an hour: the reader is never
  blocked, and the daily cap still bounds spending. The two lock
  statements are not counted as data reads in the section 9 budget.
- **Publishing model text.** The model's reply is stored as plain text and
  encoded (`<`, `>` and `&`) when it is published, so it can never become
  markup, even for a moderator with `unfiltered_html`. Edited text goes
  through `wp_kses` with the comment tags, as case 41 says.
- **Publish authentication.** On top of refusing application passwords and
  requiring a same-user logged-in cookie, the publish route requires that
  core authenticated the REST request by cookie (`$wp_rest_auth_cookie`),
  so a user set by any other method is refused even when a cookie is also
  present.
- **Untrusted prompt text.** Reader text (comment, ancestors, commenter
  name) has hidden and direction-control characters removed (zero-width
  joiner and non-joiner kept for Indic scripts) and every double bracket
  broken, so no reader can forge a data-block marker. The commenter's name
  sits inside the comment's data block on one line.
- **Script check on mixed text.** A reply is flagged `script_mismatch` only
  when its dominant script makes up less than 20 per cent of the comment's
  letters, so a Hindi comment with English words accepts a reply in either
  script, and Han with kana counts as Japanese.
- **llm-json-php use.** One `Request` per attempt with the schema, a token
  cap of 600 (filter `cdreplies_max_output_tokens`) and a 60 second
  deadline; the library's WordPress transport and transient store, with the
  store prefix `cdreplies_llmjson` so uninstall can clear it. Credit
  exhaustion and authentication failure become a hold; schema, guard,
  truncation and malformed outcomes become an output rejection (`schema`);
  transport, rate limit and server errors become a retry.
- **Tests.** PHPUnit against a stub layer of WordPress functions over a
  real MySQL database, because conditional updates, unique keys and the
  compare-and-swap counter are the behaviour under test. CI starts a MySQL
  service.
