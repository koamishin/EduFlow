---
paths:
  - app/Actions/RollOverFundingWindow.php
  - app/Actions/ReserveVendorPayment.php
---

# Actions

## Rollover renews the clock, never the money
Only an expired window may be rolled over, and a predecessor may be superseded at most once (unique lineage). Approving a successor retires the predecessor's approval via retire() — a state transition, not a delete — so at most one approval is live per institution while payments already authorized under the old one stay authorized. Retirement columns are the ONLY ones the model's updating hook permits, and they stay outside content() so retirement cannot break a digest.

## Replay a hold before checking whether its window is still live
The idempotent-replay path (returning an existing hold by invoice/key) must run BEFORE any expired-window or superseded-approval guard. A worker retrying a hold it already recorded would otherwise be refused after its window expired, and identity stability across replays is the whole point of the outbox pattern.
