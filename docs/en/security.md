# 🔐 Security & Anti-Cheat

Every combat number a student sees is a claim the client makes about itself. PlayerPuzzle treats
none of it as trustworthy until the server confirms it, through three pillars:

## 1. Server as the Source of Truth

Boss and student HP for the current level/phase are computed once, server-side
(`combat::calculate_boss_hp()`/`calculate_student_hp()`), and handed to the client as read-only
configuration. The client never recalculates them.

## 2. Sanity-Checked Damage

Whatever damage the client reports at the end of a match is clamped against the real, phase-
scaled boss HP before it's accepted — not the base HP, and not whatever the client claims the
cap should be. Advancing a phase (`advance_phase`) applies the same rule: the server requires the
reported damage to actually clear that phase's boss HP before allowing the transition.

A fuller sanity check — accounting for PlayerHUD upgrade multipliers and elapsed play time — is
designed but not implemented yet; see [Features](#features).

## 3. Single-Use Anti-Replay Tokens

Every state-changing call (starting a match, resuming a campaign attempt, advancing a phase)
issues a fresh token and invalidates the previous one. A captured request can't be replayed to
re-trigger the same reward twice — resuming a campaign mid-run rotates the token exactly like
finishing one does, so the guarantee holds across a session that spans multiple page loads, not
just within a single match.

## Blind JSON

The correct answer id is never sent to the client — see [Question Engine](#questions) for how
this is enforced at the data layer, not just the UI layer.
