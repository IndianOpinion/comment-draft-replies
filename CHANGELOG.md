# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-15

### Added

- Intake on `comment_post` with every rule of the PRD: comment type, spam
  and trash, staff, own replies, post state, closed comments, length in
  Unicode characters, links, contact details, blocked words across scripts,
  author history, kill switch, rate limits, daily cap, no letters, and a
  veto-only filter. Every skip is recorded on the comment.
- Generation in WP-Cron, WP-CLI or gen-queue, with claims, re-checks,
  a generic prompt with marked data blocks, schema and reply validation,
  one retry, backoff, a provider hold, and a writing-system flag.
- Reply drafts screen, inline panel on the Comments screen, publish, edit
  and publish, approve and publish, regenerate with a note, draft anyway,
  discard and bulk discard.
- Cookie-only publish route; no automatic, bulk or CLI publish path.
- Daily call cap with an atomic counter in the site timezone, kill switch
  option and constant, per-thread, per-post and per-author limits.
- Optional integrations with gen-queue, spend-meter and prompt-library.
- `wp comment-draft-replies` with status, list, show, run, backfill,
  discard, kill, cap, hold and purge.
- Housekeeping, retention, privacy exporter and eraser, privacy policy
  text, Site Health test, multisite support, uninstall.
- Example HTTP API caller, must-use loader and filter examples.

[Unreleased]: https://github.com/IndianOpinion/comment-draft-replies/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/comment-draft-replies/releases/tag/v0.1.0
