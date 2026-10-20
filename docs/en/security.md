# 🔐 Security & Anti-Cheat

Every combat number a student sees is a claim the client makes about itself. PlayerPuzzle treats
none of it as trustworthy until the server confirms it, through three pillars:

## 1. Server as the Source of Truth

Boss and student HP for the current level/phase are computed once, server-side
(`combat::calculate_boss_hp()`/`calculate_student_hp()`), and handed to the client as read-only
configuration. The client never recalculates them.

## 2. Verified Outcomes

Capping a number is not the same as knowing it was earned by playing, so the server works the
outcome out itself. When a match starts it issues a random seed, and the board is generated from
it on both sides. The client logs every swap and every question outcome, and sends the log with
the checkpoint it already saves every few seconds; the server **re-plays** the log with its own
copy of the rules and derives the real damage, HP, coins and result.

* The value credited is always the one the server derived — an inflated claim is ignored (and
  recorded as a `replay_diverged` event, so a systematic mismatch is visible).
* A victory the server cannot confirm restarts the phase, and the attempt still counts. If no
  restart is left, it counts as a defeat. A fight the replay cannot follow (`replay_inconclusive`)
  credits only what the log can still verify.
* Whether each question was answered correctly always comes from the server's own judgement,
  never from the client's log.
* Coins are tracked in a server-side ledger, with a plausibility ceiling tied to the damage dealt.
* Advancing a phase additionally requires the reported damage to clear that phase's boss HP.
* Visitors (the guest account, or a logged-in user who is in the course only through guest access)
  can play the Demo only: nothing they do is graded or banked.

The client and server rules are kept identical by shared test vectors: a headless run of the real
JavaScript engine produces the expected boards and cascades, and the PHP port must match them bit
for bit.

## 3. Single-Use Anti-Replay Tokens

Every state-changing call (starting a match, resuming a campaign attempt, advancing a phase)
issues a fresh token and invalidates the previous one. A captured request can't be replayed to
re-trigger the same reward twice — resuming a campaign mid-run rotates the token exactly like
finishing one does, so the guarantee holds across a session that spans multiple page loads, not
just within a single match. Economic operations (using up a token, advancing a phase, buying or
transferring coins) also run under an atomic lock per attempt or per student, so two parallel
requests cannot both succeed.

## Blind JSON

The correct answer id is never sent to the client — see [Question Engine](#questions) for how
this is enforced at the data layer, not just the UI layer.
