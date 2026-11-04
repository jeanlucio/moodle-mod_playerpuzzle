@mod @mod_playerpuzzle @javascript
Feature: A student chooses a hero
  In order to play as someone of my own
  As a student
  I need to choose a hero before a Campaign, a match or a demo

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

  Scenario: A Campaign starts on the choice screen and goes to the Lobby once a hero is chosen
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And I log in as "student1"
    When I am on the "Dungeon Quiz" "playerpuzzle activity" page
    Then I should see "Choose your hero"
    And I should see "Female mage"
    And I should see "Specialty: HP"
    And I should not see "Play Game"
    When I click on "Female mage" "radio"
    And I press "Choose this hero"
    Then I should see "Play Game"
    And I should not see "Choose your hero"

  Scenario: A Campaign student who already has a hero goes straight to the Lobby
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero    |
      | Dungeon Quiz | student1 | archerf |
    And I log in as "student1"
    When I am on the "Dungeon Quiz" "playerpuzzle activity" page
    Then I should see "Play Game"
    And I should not see "Choose your hero"

  Scenario: A Single Match asks for a hero when Play is pressed, then starts the match
    Given the following "activities" exist:
      | activity     | course | name         | gamemode | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | single   | 0            |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "Play Game" "button"
    Then I should see "Choose your hero"
    And I should see "only changes how you look"
    When I click on "Male warrior" "radio"
    And I press "Play"
    Then "#playerpuzzle-canvas-container" "css_element" should exist

  Scenario: The Demo asks for a hero too, and starts the demo
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero    |
      | Dungeon Quiz | student1 | archerf |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "Play Demo" "button"
    Then I should see "Choose your hero"
    When I click on "Male archer" "radio"
    And I press "Play"
    Then "#playerpuzzle-canvas-container" "css_element" should exist

  Scenario: A new Campaign warns, then asks for a hero, and the Lobby then offers it as under way
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed | status | currentphase |
      | Dungeon Quiz | student1 | 1       | won    | 10           |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I should see "Campaign 1 complete!"
    When I click on "Start a new Campaign" "button"
    And I click on "Start a new Campaign" "button" in the "Start a new Campaign" "dialogue"
    Then I should see "Starting a new Campaign empties your PuzzleCoin and consumables"
    When I click on "Male mage" "radio"
    And I press "Choose this hero"
    Then I should see "Campaign 2"
    And I should see "Play Game"
    And I should not see "Start a new Campaign"

  Scenario: Buying a hero upgrade in the Lobby shop spends PuzzleCoin and uses up the stage's buy
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And the following "mod_playerpuzzle > user stocks" exist:
      | playerpuzzle | user     | consumabletype | quantity |
      | Dungeon Quiz | student1 | coin           | 40       |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    And I click on "Shop" "button"
    And I should see "Hero upgrades"
    When I click on ".pp-hero-upgrade[data-attribute='hp'] .pp-hero-buy" "css_element"
    Then I should see "1 of 1 bought" in the ".pp-hero-upgrade[data-attribute='hp']" "css_element"
    And I should see "Evolve to buy more" in the ".pp-hero-upgrade[data-attribute='hp']" "css_element"
    And I should see "28" in the ".pp-shop-panel [data-role='coinvalue']" "css_element"
    And ".pp-hero-upgrade[data-attribute='attack'] .pp-hero-buy" "css_element" should exist
