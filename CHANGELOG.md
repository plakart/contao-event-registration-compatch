# Changelog

## [Unreleased]

### Added

- Replacement controllers for the "event_registration_confirm" and
  "event_registration_cancel" front end modules: notifications only on real status
  changes, waiting list only after a real cancellation.
- Optional button mode per module (`compatch_requireButton`) against e-mail link
  scanners.
- Config switches `confirm` and `cancel`.

### Fixed

- Parallel requests (link scanners) no longer send more than one mail.
- Different spellings of the same UUID in one link count as one registration.
- Insert tags in the request URL no longer reach the button form's action.
