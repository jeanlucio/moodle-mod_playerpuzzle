@mod @mod_playerpuzzle @javascript
Feature: A student chooses a hero
  In order to play as someone of my own
  As a student
  I need to choose a hero when I press Play on a Campaign, a match or a demo

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

  Scenario: A Campaign opens at the Lobby and asks for the hero when Play is pressed, then starts the match
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And I log in as "student1"
    When I am on the "Dungeon Quiz" "playerpuzzle activity" page
    Then I should see "Play Game"
    And I should not see "Choose your hero"
    When I click on "Play Game" "button"
    Then I should see "Choose your hero"
    And I should see "Female mage"
    And I should see "Specialty: Health (HP)"
    And I should see "stays with you for the whole Campaign"
    When I click on "Female mage" "radio"
    And I press "Play"
    Then "#playerpuzzle-canvas-container" "css_element" should exist

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
    And I should see "small head start"
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

  Scenario: A new Campaign warns, then asks for a hero, and starts the match
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
    And I press "Play"
    Then "#playerpuzzle-canvas-container" "css_element" should exist

  Scenario: A student gives up a Campaign after a warning, and may start another
    Given the following "activities" exist:
      | activity     | course | name         | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | 0            |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed | status | currentphase |
      | Dungeon Quiz | student1 | 1       | lost   | 3            |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "Give up the Campaign" "button"
    Then I should see "The Campaign ends here and is graded on how far you got"
    And I should not see "last Campaign this activity allows"
    When I press "Cancel"
    Then I should see "Give up the Campaign"
    When I click on "Give up the Campaign" "button"
    And I press "Give up the Campaign"
    Then I should see "You gave up Campaign 1"
    And I should see "Start a new Campaign"
    And I should not see "Give up the Campaign"

  Scenario: Giving up the last Campaign an activity allows says it ends the play for good
    Given the following "activities" exist:
      | activity     | course | name         | minquestions | maxcampaigns |
      | playerpuzzle | C1     | Dungeon Quiz | 0            | 1            |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed | status | currentphase |
      | Dungeon Quiz | student1 | 1       | lost   | 3            |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "Give up the Campaign" "button"
    Then I should see "last Campaign this activity allows"
    And I should see "you will not be able to play again"
    When I press "Give up the Campaign"
    Then I should see "You gave up Campaign 1"
    And I should see "You have played every Campaign available in this activity"
    And I should not see "Start a new Campaign"

  Scenario: The Lobby counts the attempts of a limited Campaign
    Given the following "activities" exist:
      | activity     | course | name         | minquestions | maxattempts |
      | playerpuzzle | C1     | Dungeon Quiz | 0            | 2           |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And I log in as "student1"
    When I am on the "Dungeon Quiz" "playerpuzzle activity" page
    Then I should see "Attempt 1 of 2"
    And I should not see "This is your last attempt"

  Scenario: The Lobby warns before the last attempt of a Campaign
    Given the following "activities" exist:
      | activity     | course | name         | minquestions | maxattempts |
      | playerpuzzle | C1     | Dungeon Quiz | 0            | 2           |
    And the following "mod_playerpuzzle > heroes" exist:
      | playerpuzzle | user     | hero  |
      | Dungeon Quiz | student1 | magef |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed | status | currentphase |
      | Dungeon Quiz | student1 | 1       | lost   | 3            |
    And I log in as "student1"
    When I am on the "Dungeon Quiz" "playerpuzzle activity" page
    Then I should see "Attempt 2 of 2"
    And I should see "This is your last attempt: if you lose, the Campaign ends."

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
    Then I should see "Full" in the ".pp-hero-upgrade[data-attribute='hp']" "css_element"
    And ".pp-hero-upgrade[data-attribute='hp'] .pp-hero-pips[aria-label='1 of 1 bought']" "css_element" should exist
    And I should see "To evolve to stage 2" in the ".pp-evolve" "css_element"
    And I should see "28" in the ".pp-shop-panel [data-role='coinvalue']" "css_element"
    And ".pp-hero-upgrade[data-attribute='attack'] .pp-hero-buy" "css_element" should exist
