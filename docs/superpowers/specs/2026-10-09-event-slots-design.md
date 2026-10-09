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

---

## Revision after the first deployment (2026-10-09, evening)

The first implementation opened the whole envelope as bookable and left table
availability in charge. Seeing it on the real internal page, the operator
corrected three things. Their words: *"Wenn im Sperrvermerk 17 Uhr steht, geht
auch nur 17 Uhr"* and *"Ohne Tisch, aber mit den anderen Daten"*.

### An event time is exactly the time that is written

`17 UHR` yields one bookable time, 17:00 — not a window from 17:00 to the end
of the envelope. `11 Uhr und 13 Uhr` yields exactly those two. Everything else
inside the envelope stays blocked, which is what the envelope is for.

### At an event time, capacity is people, not tables

The operator explains the name: a closure note is called a *Sperrvermerk*
only because it blocks tables during normal opening hours. At the event time
itself, the restaurant does not think in tables at all — they count heads
against the number in the note and place people by hand on a paper plan.

So at an event time:

- no table check decides availability, and no table is assigned;
- the note's guest cap decides, counting what is already booked at that time
  (a per-time cap wins over the general one);
- everything else about the reservation is recorded as usual.

This is not a new mechanism. It is what the internal page already does for an
all-day note (`DayData`, the `ohne_tisch` branch). It was simply unreachable
for a note that is not all-day — which is every note with an evening envelope,
including the one this feature exists for.

### Internal and online now agree

Phone intake takes event times **always**, with or without the keyword. Staff
must never be locked out of a day by a note they wrote themselves; the footer
of the internal page has promised this all along.

Online takes them **only** with `online buchbar`, unchanged.

Outside the envelope nothing changes: the normal lunch slots keep the ordinary
table logic.

### Worked through the operator's own two days

| | online, with the keyword | internal |
|---|---|---|
| 27.11. Märchenabend, envelope 16:00–20:00, `17 UHR`, `MAX 30 PAX` | 11:30–14:00 with tables, plus 17:00 for up to 30 guests without a table | the same, plus 14:15–14:45, which the cut-off removes online |
| 25./26.12. Christmas, envelope 10:00–16:00, `11 Uhr und 13 Uhr`, `MAX 120 PAX` | would be 11:00 and 13:00 only, up to 120 guests, no tables — the envelope covers lunch, so it replaces it | the same |

Christmas carries no keyword today and stays closed online. The table above
describes what the keyword would do, not what happens now.
