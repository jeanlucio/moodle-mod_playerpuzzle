@mod @mod_playerpuzzle @javascript
Feature: PlayerPuzzle teacher-facing settings behaviour
  As a teacher
  I want to add a PlayerPuzzle activity to my course
  So that students can play it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |

  Scenario: A teacher adds a PlayerPuzzle activity and it appears on the course page
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add a "playerpuzzle" activity to course "Course 1" section "1" and I fill the form with:
      | Phase name | Dungeon Quiz |
    When I am on "Course 1" course homepage
    Then I should see "Dungeon Quiz"
