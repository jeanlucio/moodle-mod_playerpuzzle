# 🔎 Third-party Service Disclosure

PlayerPuzzle works without any external service. Gameplay never makes a network request, and the
questions a student sees come from Moodle's own Question Bank (see
[Question Engine](#questions)) or from the activity's own question bank.

## Optional AI question generation

The only feature that reaches an external service is the **optional** AI question generator on
the *Manage questions* page. It is available only to users who hold the
`mod/playerpuzzle:managequestions` capability, and only when an AI source is available.

* **What is sent:** a prompt made of the topic the teacher typed, the number of questions
  requested (at most 10), the name of the interface language, and fixed formatting instructions.
  No student data and no course content is included.
* **Where it goes:** through the companion
  [local_aihub](https://github.com/jeanlucio/moodle-local_aihub) plugin when it is installed and
  has a key configured (bring your own key), otherwise through Moodle's own `core_ai` subsystem,
  where the course or activity "Enable AI tools" setting is honoured. The provider is therefore
  whichever one the administrator configured in the hub or in `core_ai`, and that provider's own
  terms and cost apply.
* **What happens to the answer:** it is treated as untrusted input. Every question is validated
  and shaped, a malformed entry is dropped, and what is kept is saved as *pending*
  (`approved = 0`). A pending question is never drawn in a match until the teacher approves it.
* **Without an AI source:** the generator is not offered, and manual entry and Question Bank
  import keep working.
