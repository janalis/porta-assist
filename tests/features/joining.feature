Feature: Being a guest
  Porta opened on a guest's copy joins the session it was made for by
  itself. In the session the guest runs the host's run configurations, reaches
  the dev servers the host shares, watches and steps the host's debugger, and
  leaves when done — the copy stays. Here the host is the test speaking the
  session's wire, met through the assist plugin's own fake relay.

  Background:
    Given a PHP project
    And the bundled plugins "assist" are enabled
    And a file "src/A.php" containing:
      """
      <?php
      // two
      """
    And this is a guest's copy of a project shared by a host

  Scenario: The guest picks one of the host's run configurations, and it runs there
    Given I start the app with the arguments "src/A.php"
    When the host "Hal" lets this guest in to "edit"
    Then a toast should say "Connected to Hal"
    When I use the action "assist:run"
    Then the host should hear a "runs" message
    When the host says:
      """
      {"t": "runs", "labels": ["composer test", "make lint"], "allowed": true}
      """
    Then I should see "Run on the host"
    When I press "Down"
    And I press "Enter"
    Then the host should hear a "run" message with "label" "make lint"

  Scenario: A server the host shares is reached here, its address copied
    Given I start the app with the arguments "src/A.php"
    And the host "Hal" lets this guest in to "read"
    And a toast should say "Connected to Hal"
    When the host says:
      """
      {"t": "server", "port": 47123}
      """
    Then a toast should say "Hal shared localhost:47123"
    When I use the action "assist:servers"
    Then I should see "Shared servers"
    And I should see "localhost:47123"
    When I press "Enter"
    Then a toast should say "Address copied: http://127.0.0.1:"

  Scenario: The host's debugger is shown where it paused, and stepped from here
    Given I start the app with the arguments "src/A.php"
    And the host "Hal" lets this guest in to "edit"
    And a toast should say "Connected to Hal"
    When the host says:
      """
      {"t": "debug", "status": "paused", "path": "src/A.php", "line": 1, "frames": [{"id": 1, "name": "{main}", "path": "src/A.php", "line": 1}]}
      """
    Then a toast should say "The host's debugger paused at src/A.php:2"
    When I use the action "assist:debugger"
    Then I should see "Host's debugger — src/A.php:2"
    And I should see "Step Over"
    When I press "Down"
    And I press "Enter"
    Then the host should hear a "debug-step" message with "verb" "stepOver"

  Scenario: Leaving the session tells the host, and the copy stays
    Given I start the app with the arguments "src/A.php"
    And the host "Hal" lets this guest in to "edit"
    And a toast should say "Connected to Hal"
    When I use the action "assist:leave"
    Then the host should hear a "bye" message with "for" "good"
    And a toast should say "Left the session — this copy is yours to keep"
    And the file "src/A.php" should exist
