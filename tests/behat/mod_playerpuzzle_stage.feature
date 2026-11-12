@mod @mod_playerpuzzle @javascript
Feature: PlayerPuzzle game controls and questions work through the keyboard and for a screen reader
  In order to play without a mouse and without seeing the screen
  As a student
  I need to select pieces, give up and answer questions with real, labelled controls

  # Every attempt below uses PRNG seed 2, so the board is always the same (see the match feature).

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |

  Scenario: Enter selects the focused piece and Enter again drops the selection
    Given the following "activities" exist:
      | activity     | course | name         | gamemode | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | single   | 0            |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed |
      | Dungeon Quiz | student1 | 2       |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Play Game" "button"
    And I wait until the PlayerPuzzle board is ready
    When I press the enter key
    Then I should see "selected, row 1, column 1" in the "#pp-aria-live" "css_element"
    And "#pp-board-grid-body td[data-row='0'][data-col='0'][aria-selected='true']" "css_element" should exist
    And I press the enter key
    And I should see "Selection cancelled." in the "#pp-aria-live" "css_element"
    And "#pp-board-grid-body td[aria-selected='true']" "css_element" should not exist

  Scenario: Giving up asks for confirmation and then ends the match as a defeat
    Given the following "activities" exist:
      | activity     | course | name         | gamemode | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | single   | 0            |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed |
      | Dungeon Quiz | student1 | 2       |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Play Game" "button"
    And I wait until the PlayerPuzzle board is ready
    When I click on ".pp-round-giveup" "css_element"
    Then I should see "Give up the match?" in the "#playerpuzzle-giveup" "css_element"
    And I click on "Keep playing" "button" in the "#playerpuzzle-giveup" "css_element"
    And "#playerpuzzle-giveup[open]" "css_element" should not exist
    And "#playerpuzzle-gameover" "css_element" should not exist
    And I click on ".pp-round-giveup" "css_element"
    And I click on "Give up" "button" in the "#playerpuzzle-giveup" "css_element"
    And I wait until the PlayerPuzzle match has ended
    And I should see "DEFEAT" in the "#playerpuzzle-gameover" "css_element"

  Scenario: A right opening answer says so, marks the answer for a screen reader and hands the result to Continue
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > questions" exist:
      | playerpuzzle | questiontext                  | correct | wrong      |
      | Dungeon Quiz | What is the capital of Italy? | Rome    | Pisa, Bari |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Play Game" "button"
    And I wait until "#playerpuzzle-answers-container button" "css_element" exists
    When I click on "Rome" "button" in the "#playerpuzzle-answers-container" "css_element"
    And I click on "#playerpuzzle-btn-confirm" "css_element"
    Then I should see "Right! You move first." in the "#playerpuzzle-answers-container" "css_element"
    And "your answer, correct" "button" should exist
    And the focused element is "#playerpuzzle-btn-continue" "css_element"
    And "#playerpuzzle-btn-continue[aria-labelledby='playerpuzzle-outcome-0 playerpuzzle-btn-continue']" "css_element" should exist
    And I click on "#playerpuzzle-btn-continue" "css_element"
    And I wait until the PlayerPuzzle board is ready

  Scenario: A wrong opening answer marks the wrong one and the right one for a screen reader
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > questions" exist:
      | playerpuzzle | questiontext                  | correct | wrong      |
      | Dungeon Quiz | What is the capital of Italy? | Rome    | Pisa, Bari |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Play Game" "button"
    And I wait until "#playerpuzzle-answers-container button" "css_element" exists
    When I click on "Pisa" "button" in the "#playerpuzzle-answers-container" "css_element"
    And I click on "#playerpuzzle-btn-confirm" "css_element"
    Then I should see "Wrong. The opponent moves first." in the "#playerpuzzle-answers-container" "css_element"
    And "your answer, wrong" "button" should exist
    And "correct answer" "button" should exist
    And the focused element is "#playerpuzzle-btn-continue" "css_element"

  Scenario: Skipping the opening question gives the first move to the opponent
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > questions" exist:
      | playerpuzzle | questiontext                  | correct | wrong      |
      | Dungeon Quiz | What is the capital of Italy? | Rome    | Pisa, Bari |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Play Game" "button"
    And I wait until "#playerpuzzle-answers-container button" "css_element" exists
    When I click on "#playerpuzzle-btn-skip" "css_element"
    Then I wait until "#playerpuzzle-modal[open]" "css_element" does not exist
    And I wait until the PlayerPuzzle board is ready
