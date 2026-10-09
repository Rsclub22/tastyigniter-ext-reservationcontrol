# What the guest should see next

**Status:** agreed in conversation on 2026-10-09, not designed in detail,
not implemented. Two related pieces.

## 1. A way out when online does not fit

Until today the extension's online limits did not actually block anything —
a format mismatch and a location setting between them made the cut-off, the
guest cap and a closure note's window inert. From today they bite. Guests
will therefore meet "nothing available" far more often than before, and the
booking form currently leaves them there with no way forward.

Every one of those refusals is a table the restaurant might still have given
away by phone: the cut-off exists because the kitchen closes, not because the
room is full; a guest cap is a kitchen's limit, not a hard wall; a closure
note's envelope is about not disturbing an event, and a small party at the
edge is often fine.

So when a day or time is unavailable, say so and offer the telephone. Not as
an error — as the obvious next step. The number is already configured in
TastyIgniter's location settings, so nothing new needs entering.

Worth deciding when this is designed: whether the invitation appears always,
or only when something actually blocked (as opposed to the restaurant simply
being closed that day — nobody should be invited to ring about a Monday).

## 2. Special events visible before a date is picked

The guest notice built today only appears once a guest has already landed on
the right date. A visitor who does not know a Märchenabend exists will never
select 27 November, so they will never see it.

A short list of the coming special evenings, on the booking page and
independent of the selected date, turns the mechanism from a safety net into
something that fills the event. The data exists: blocked days carry a guest
notice, and a closure note carries one after `online buchbar:`.

Worth deciding: how far ahead to look, whether an event that cannot be booked
online is listed at all (the restaurant may well want it listed *with* the
telephone invitation above), and where in the theme's layout it belongs
without the extension taking over the page.

## Both share one problem worth solving once

Each needs to put markup on a page owned by a theme. The guest notice solves
this already, through a `Livewire::listen('render')` hook that reads the
component's shape defensively and renders nothing when it does not recognise
it. Reuse that, and keep its rule: a missing notice is a disappointment, a
broken booking form costs the restaurant its evening.
