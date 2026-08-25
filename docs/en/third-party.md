# 🔎 Third-party Service Disclosure

**Not applicable today.** PlayerPuzzle does not currently call any external service — every
question comes from Moodle's own Question Bank (see [Question Engine](#questions)), and no
network request leaves the server as part of gameplay.

## Planned

A second question source — manual entry plus **optional** AI-assisted generation via the
companion [local_aihub](https://github.com/jeanlucio/moodle-local_aihub) plugin, falling back to
Moodle's own `core_ai` — is designed for a future phase (see [Features](#features)). When it
ships, this section will disclose the exact providers, what data is transmitted, and any cost
implications, following the same pattern already documented for
[local_playergames](https://jeanlucio.github.io/moodle-local_playergames/#security) and
[local_aihub](https://github.com/jeanlucio/moodle-local_aihub) itself. As with those plugins, AI
generation will be entirely opt-in and will never include student data in a prompt.
