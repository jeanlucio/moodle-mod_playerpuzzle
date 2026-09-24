@mod @mod_playerpuzzle @javascript
Feature: PlayerPuzzle pre-match loadout shop
  As a student
  I want to buy consumables in the Lobby before a match starts
  So that I can prepare my loadout without spending anything mid-match

  # Scope note: this feature covers only the Moodle frame around the loadout shop (Lobby
  # rendering, the buy AJAX call, entering play.php) — never board/combat mechanics
  # themselves, which run entirely in the Canvas and have no deterministic seed to drive a
  # real match against without a flaky retry-until-it-matches scenario.

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
    And the following "activities" exist:
      | activity     | course | name         |
      | playerpuzzle | C1     | Dungeon Quiz |

  Scenario: The Lobby shows the PuzzleCoin balance and the loadout shop
    Given the following "mod_playerpuzzle > user stocks" exist:
      | playerpuzzle | user     | consumabletype | quantity |
      | Dungeon Quiz | student1 | coin           | 20       |
    When I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    Then I should see "20" in the "[data-role='coinvalue']" "css_element"
    And I click on "Shop" "button"
    And I should see "Loadout Shop"
    And I should see "Owned: 0" in the ".pp-lobby-shop-item[data-type='potion']" "css_element"

  Scenario: Buying a consumable debits PuzzleCoin and credits stock without reloading the page
    Given the following "mod_playerpuzzle > user stocks" exist:
      | playerpuzzle | user     | consumabletype | quantity |
      | Dungeon Quiz | student1 | coin           | 20       |
    When I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Shop" "button"
    And I click on ".pp-lobby-shop-item[data-type='potion'] .pp-lobby-buy" "css_element"
    Then I should see "Owned: 1" in the ".pp-lobby-shop-item[data-type='potion']" "css_element"
    And I should see "12" in the "#pp-lobby-shop [data-role='coinvalue']" "css_element"

  Scenario: Buying without enough PuzzleCoin shows an error instead of silently failing
    Given the following "mod_playerpuzzle > user stocks" exist:
      | playerpuzzle | user     | consumabletype | quantity |
      | Dungeon Quiz | student1 | coin           | 0        |
    When I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Shop" "button"
    And I click on ".pp-lobby-shop-item[data-type='potion'] .pp-lobby-buy" "css_element"
    Then I should see "Not enough coins for this purchase." in the ".modal-body" "css_element"
    And I should see "Owned: 0" in the ".pp-lobby-shop-item[data-type='potion']" "css_element"

  Scenario: A loadout purchase does not block entering the game
    Given the following "mod_playerpuzzle > user stocks" exist:
      | playerpuzzle | user     | consumabletype | quantity |
      | Dungeon Quiz | student1 | coin           | 20       |
    When I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Shop" "button"
    And I click on ".pp-lobby-shop-item[data-type='potion'] .pp-lobby-buy" "css_element"
    And I should see "Owned: 1" in the ".pp-lobby-shop-item[data-type='potion']" "css_element"
    And I click on "Back" "button" in the "#pp-lobby-shop" "css_element"
    And I click on "Play Game" "button"
    Then "#playerpuzzle-canvas-container" "css_element" should exist
