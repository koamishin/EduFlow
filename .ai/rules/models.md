---
paths:
  - app/Models/PaymentSubmissionOutbox.php
---

# Models

## `submitted` is open but never re-dispatchable
OPEN_STATES means 'work still owed'; DISPATCHABLE_STATES means 'a submission worker may pick this up'. `submitted` is deliberately in the first and not the second: settlement is unproven so capacity stays held, but the payment was already sent to the rail and handing the entry back to a worker is a double submission. Open does not mean unsent.
