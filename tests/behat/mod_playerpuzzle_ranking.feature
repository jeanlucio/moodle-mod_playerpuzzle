@mod @mod_playerpuzzle @javascript
Feature: PlayerPuzzle class ranking in the Lobby
  In order to see how I compare with my classmates
  As a student
  I need to open the ranking from the Lobby without leaving it

  # A one-level campaign has 10 phases; a student sitting on phase N has won N - 1 of them,
  # so they rank at (N - 1) x 10 points out of 100.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
      | student3 | Student   | Three    | student3@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |

  Scenario: The ranking opens in place of the Lobby's panel and closes back to it
    Given the following "activities" exist:
      | activity     | course | name         | maxlevels |
      | playerpuzzle | C1     | Dungeon Quiz | 1         |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed | currentphase |
      | Dungeon Quiz | student1 | 1       | 4            |
      | Dungeon Quiz | student2 | 1       | 7            |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "Ranking" "button"
    Then I should see "Class ranking"
    And I should see "Student Two" in the ".pp-lobby-ranking-table" "css_element"
    And I should see "60" in the ".pp-lobby-ranking-table" "css_element"
    And I should see "(you)" in the "tr.pp-lobby-ranking-me" "css_element"
    And I should see "30" in the "tr.pp-lobby-ranking-me" "css_element"
    And "Play Game" "button" should not be visible
    And I click on "Back" "button" in the "#pp-lobby-ranking" "css_element"
    And "Play Game" "button" should be visible
    And "#pp-lobby-ranking" "css_element" should not be visible

  Scenario: A teacher can turn the ranking off
    Given the following "activities" exist:
      | activity     | course | name         | show_ranking |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And I log in as "student1"
    When I am on the "Dungeon Quiz" "playerpuzzle activity" page
    Then "Ranking" "button" should not exist
    And "Play Game" "button" should exist

  Scenario: With separate groups a student only sees their own group
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
      | student2 | GA    |
      | student3 | GB    |
    And the following "activities" exist:
      | activity     | course | name         | maxlevels | groupmode |
      | playerpuzzle | C1     | Dungeon Quiz | 1         | 1         |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed | currentphase |
      | Dungeon Quiz | student1 | 1       | 4            |
      | Dungeon Quiz | student2 | 1       | 7            |
      | Dungeon Quiz | student3 | 1       | 9            |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "Ranking" "button"
    Then I should see "Student Two" in the ".pp-lobby-ranking-table" "css_element"
    And I should not see "Student Three" in the ".pp-lobby-ranking-table" "css_element"
