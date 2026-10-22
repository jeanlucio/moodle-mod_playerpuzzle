@mod @mod_playerpuzzle @javascript
Feature: PlayerPuzzle smoke test
  As a student
  I want to open a PlayerPuzzle activity
  In order to start playing

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | teacher1 | Teacher   | One      | teacher1@example.com  |
      | student1 | Student   | One      | student1@example.com  |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity      | course | name        | minquestions |
      | playerpuzzle   | C1     | Dungeon Quiz | 0            |

  Scenario: Student opens the lobby and can start the game
    When I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    Then I should see "Play Game"
    When I click on "Play Game" "button"
    Then "#playerpuzzle-canvas-container" "css_element" should exist

  Scenario: The lobby offers no match while the activity requires questions and has none
    Given the following "activities" exist:
      | activity     | course | name       | minquestions |
      | playerpuzzle | C1     | Empty Quiz | 3            |
    When I log in as "student1"
    And I am on the "Empty Quiz" "playerpuzzle activity" page
    Then I should see "This activity has no questions yet"
    And I should not see "Play Game"
