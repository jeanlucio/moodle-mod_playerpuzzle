@mod @mod_playerpuzzle @javascript
Feature: PlayerPuzzle accessible board
  As a student using assistive technology
  I want the match-3 board to be readable and operable through real DOM elements
  In order to play PlayerPuzzle without relying on the game canvas

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | student1 | Student   | One      | student1@example.com  |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity     | course | name         |
      | playerpuzzle | C1     | Dungeon Quiz |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Play Game" "button"

  Scenario: The accessible board announces whose turn it is
    Then "#pp-board-grid-body td[role='gridcell']" "css_element" should exist
    And "#pp-aria-live[role='status']" "css_element" should exist
    And I should see "Your turn." in the "#pp-aria-live" "css_element"

  Scenario: The board grabs keyboard focus on its own, so it does not need to be tabbed to
    Then the focused element is "#pp-board-grid-body td[data-row='0'][data-col='0']" "css_element"

  Scenario: An arrow key moves the roving tabindex focus
    When I press the right key
    Then "#pp-board-grid-body td[data-row='0'][data-col='1'][tabindex='0']" "css_element" should exist

  Scenario: Space re-reads the available moves without acting
    When I press the space key
    Then I should see "Your turn." in the "#pp-aria-live" "css_element"

  Scenario: A digit key executes a move without erroring
    When I press the 1 key
    Then I should see "Your turn." in the "#pp-aria-live" "css_element"
