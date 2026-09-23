@mod @mod_playerpuzzle @javascript
Feature: A PlayerPuzzle match counts only as the server's own replay verifies it
  In order to trust grades, coins and rankings
  As a student
  I need a real match to count exactly as I played it

  # Every attempt below is created with PRNG seed 2, so its board is always the same: the
  # accessible board announces its moves in a fixed order, where move 1 is a Coin match and
  # move 4 a Sword match. With boss HP equal to one Sword combo, move 4 wins on the spot.

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

  Scenario: A winning move ends in a verified victory that banks its coins
    Given the following "activities" exist:
      | activity     | course | name         | gamemode | basebosshp | bossdamage | coingain | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | single   | 10         | 10         | 10       | 0            |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed |
      | Dungeon Quiz | student1 | 2       |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "Play Game" "button"
    And I wait until the PlayerPuzzle board is ready
    And I press the 4 key
    And I wait until the PlayerPuzzle match has ended
    Then I should see "Progress saved! (Total coins: 15)" in the "#pp-save-status" "css_element"
    And I should see "VICTORY" in the "#playerpuzzle-gameover" "css_element"

  Scenario: A move that leaves the student to the boss ends in a verified defeat
    Given the following "activities" exist:
      | activity     | course | name         | gamemode | basebosshp | bossdamage | coingain | minquestions | basestudenthp |
      | playerpuzzle | C1     | Dungeon Quiz | single   | 1000       | 10         | 10       | 0            | 1             |
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed |
      | Dungeon Quiz | student1 | 2       |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "Play Game" "button"
    And I wait until the PlayerPuzzle board is ready
    And I press the 1 key
    And I wait until the PlayerPuzzle match has ended
    Then I should see "DEFEAT" in the "#playerpuzzle-gameover" "css_element"
    And I should see "Progress saved! (Total coins: 0)" in the "#pp-save-status" "css_element"

  Scenario: A victory the server cannot verify restarts the phase instead of counting
    Given the following "activities" exist:
      | activity     | course | name         | gamemode | basebosshp | bossdamage | coingain | minquestions |
      | playerpuzzle | C1     | Dungeon Quiz | single   | 10         | 10         | 10       | 0            |
    # An attempt started under an older engine version cannot be replayed, so its victory
    # stays unverified — the same outcome a lost connection or a blocked event log leads to.
    And the following "mod_playerpuzzle > attempts" exist:
      | playerpuzzle | user     | rngseed | engineversion |
      | Dungeon Quiz | student1 | 2       | 1             |
    And I log in as "student1"
    And I am on the "Dungeon Quiz" "playerpuzzle activity" page
    When I click on "Play Game" "button"
    And I wait until the PlayerPuzzle board is ready
    And I press the 4 key
    And I wait until the PlayerPuzzle match has ended
    Then I should see "so this phase will restart" in the "#pp-save-status" "css_element"
    # The page reloads by itself into a fresh start of the same phase.
    And I wait until "#playerpuzzle-gameover" "css_element" does not exist
    And I wait until the PlayerPuzzle board is ready
