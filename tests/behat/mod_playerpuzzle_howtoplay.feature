@mod @mod_playerpuzzle @javascript
Feature: PlayerPuzzle explains how to play
  In order to learn the rules without leaving the activity
  As a student
  I need a How to play help in the Lobby and inside the game

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

  Scenario: The Lobby's How to play opens in place of the menu and closes back to it
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "How to play" "button"
    Then I should see "The goal" in the "#pp-lobby-howto" "css_element"
    And I should see "The seven pieces" in the "#pp-lobby-howto" "css_element"
    And I should see "Coins and shop" in the "#pp-lobby-howto" "css_element"
    And I click on "Back" "button" in the "#pp-lobby-howto" "css_element"
    And "#pp-lobby-howto" "css_element" should not be visible
    And I should see "Play Game"

  Scenario: The help leaves out the questions section while the activity has no questions
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "How to play" "button"
    Then I should not see "When the Mana Orb bar fills" in the "#pp-lobby-howto" "css_element"

  Scenario: The help explains the questions once the activity has one
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
    When I click on "How to play" "button"
    Then I should see "When the Mana Orb bar fills" in the "#pp-lobby-howto" "css_element"

  Scenario: The game's help button opens the same help in a dialog and closes it again
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
    When I click on "How to play" "button"
    Then I should see "The goal" in the "#playerpuzzle-howto" "css_element"
    And I click on "Close" "button" in the "#playerpuzzle-howto" "css_element"
    And "#playerpuzzle-howto[open]" "css_element" should not exist
