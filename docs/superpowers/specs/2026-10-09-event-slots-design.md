# Event slots inside a closure note

**Status:** agreed with the operator on 2026-10-09, not implemented.
Belongs with the work that turns closure notes into real records.

## The problem

The extension can only ever *remove* bookable times. A restaurant that opens
specially for one evening — a themed dinner, a New Year's menu — cannot offer
that evening online, because the location's working hours are stored per
weekday, with a single window each. Opening one Friday evening would open
every Friday evening.

The operator already writes the event's time into the note:

    MÄRCHENABEND 17 UHR MAX 30 PAX. NUR PER TELEFON BUCHBAR

Their reading of that note is not "16:00 to 20:00 is blocked". It is "17:00 is
the time, and nothing else".

## The two times do different jobs

A closure note carries a stored window (`reserve_time` plus `duration`) and,
separately, times written into its comment. They are not redundant:

| | job |
|---|---|
| stored window, e.g. 16:00–20:00 | the blocked envelope: nothing is bookable inside it, not even a large party |
| time in the text, e.g. "17 UHR" | the event slot: the one time that opens inside the envelope |

The event slot only exists when the note opts in online (`online buchbar`).
Without the keyword the envelope blocks and the text times open nothing —
which is what keeps the operator's Christmas notes closed, although they
read "2 Gänge: 11 Uhr und 13 Uhr".

## What happens to the day's normal hours

Decided by whether the envelope overlaps the day's opening hours. No keyword:

| envelope vs. opening hours | result |
|---|---|
| no overlap (e.g. 16:00–20:00 against 11:30–15:00) | normal hours stay, the event slot is added |
| overlap (e.g. 10:00–16:00 against 11:30–15:00) | the event slots replace the normal hours |

Against the operator's real notes:

- 27.11. Märchenabend, envelope 16:00–20:00, no overlap: lunch runs as on any
  Friday, 17:00 is bookable for up to 30 guests, 16:00–20:00 otherwise closed.
- 25./26.12. Christmas, envelope 10:00–16:00, overlaps: the stated times would
  replace lunch — but the notes carry no opt-in, so the day stays closed
  online, unchanged from today.

## Why this is not a small change

Everything built so far subtracts from a schedule the platform produced.
Creating a slot the weekday schedule does not contain touches capacity
checking, table allocation, the phone intake and the daily sheet. The hook
exists — the extension already answers `WorkingScheduleCreatedEvent` to close
days — but it has only ever been used in one direction.

Parsing times out of German free text is the fragile part, and it is the part
that disappears once a closure note is a record with fields for start, end,
guest cap and an event time. This design should be built *after* that, not
before: then the event slot is a field, not a regex.
