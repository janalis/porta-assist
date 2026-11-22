Feature: Sharing a project
  The host shares the project it has open: Porta hands out a code, copied as
  the command a guest runs, and asks the host whether to let each guest edit,
  read along, or not come in. While sharing, the host follows someone, asks
  everyone to follow it, shares a terminal or a dev server, and stops. Here
  the mailbox server and relay are the assist plugin's own fake, and the
  guest is the test speaking the session's wire — nothing reaches the
  public servers.

  Background:
    Given a PHP project
    And the bundled plugins "assist" are enabled
    And a file "src/A.php" containing "<?php"
    And guests meet the host at the suite's own mailbox and relay

  @setting:assist.mailbox @setting:assist.transit
  Scenario: Sharing hands out a code, copied as the command to run
    Given I start the app with the arguments "src/A.php"
    When I use the action "assist:share"
    Then a toast should say "copied: porta assist"
    And the clipboard should hold the command that joins with the code
    And I should see "⇄ 0"

  @setting:assist.transit
  Scenario: A relay that is not host:port is refused before anything is shared
    Given the project configuration:
      """
      {"plugins": {"assist": {"transit": "nowhere"}}}
      """
    And I start the app with the arguments "src/A.php"
    When I use the action "assist:share"
    Then a toast should say "is not host:port"
    And I should not see "⇄"

  @setting:assist.mailbox
  Scenario: A mailbox server that cannot be reached is said
    Given the project configuration:
      """
      {"plugins": {"assist": {"mailbox": "ws://127.0.0.1:1/v1"}}}
      """
    And I start the app with the arguments "src/A.php"
    When I use the action "assist:share"
    Then a toast should say "Cannot share: 127.0.0.1:1 refused the connection"

  Scenario: A guest asks in and is let edit; stopping ends the session for them
    Given I start the app with the arguments "src/A.php"
    And I use the action "assist:share"
    When a guest "Ana" asks to join with the code
    Then I should see "Ana asks to join"
    When I press "Enter"
    Then the guest should hear a "welcome" message with "role" "edit"
    And I should see "⇄ 1"
    When I use the action "assist:stop"
    Then the guest should hear a "bye" message with "reason" "ended"
    And I should not see "⇄"

  Scenario: The participants are listed, and the host follows one of them
    Given I start the app with the arguments "src/A.php"
    And I use the action "assist:share"
    And a guest "Ana" asks to join with the code
    And I should see "Ana asks to join"
    And I press "Enter"
    And the guest should hear a "welcome" message
    When I use the action "assist:participants"
    Then I should see "Participants"
    And I should see "Ana"
    When I press "Escape"
    And I use the action "assist:follow"
    And I should see "Follow"
    And I press "Enter"
    Then I should see "⇄ 1 → Ana"

  Scenario: The host asks everyone to follow it
    Given I start the app with the arguments "src/A.php"
    And I use the action "assist:share"
    And a guest "Ana" asks to join with the code
    And I should see "Ana asks to join"
    And I press "Enter"
    And the guest should hear a "welcome" message
    When I use the action "assist:focus"
    Then the guest should hear a "focus" message with "peer" "host"

  Scenario: A terminal is shared with the guests
    Given a file "src/Wait.php" containing:
      """
      <?php
      echo "ready\n";
      sleep(30);
      """
    And I start the app with the arguments "src/Wait.php"
    And I use the action "assist:share"
    And a guest "Ana" asks to join with the code
    And I should see "Ana asks to join"
    And I press "Enter"
    And the guest should hear a "welcome" message
    And I use the action "ide_run"
    And I press "Enter"
    And I should see "ready"
    When I use the action "assist:share_terminal"
    Then I should see "Share which terminal?"
    When I press "Enter"
    Then the guest should hear a "terminal" message

  Scenario: A dev server's port is shared with the guests
    Given I start the app with the arguments "src/A.php"
    And I use the action "assist:share"
    And a guest "Ana" asks to join with the code
    And I should see "Ana asks to join"
    And I press "Enter"
    And the guest should hear a "welcome" message
    When I use the action "assist:share_server"
    Then I should see "Share which local port?"
    When I type "8000"
    And I press "Enter"
    Then the guest should hear a "server" message with "port" "8000"

  @setting:assist.maxGuests
  Scenario: Past the most guests at once, the next is turned away
    Given the project configuration:
      """
      {"plugins": {"assist": {"maxGuests": 1}}}
      """
    And I start the app with the arguments "src/A.php"
    And I use the action "assist:share"
    And a guest "Di" asks to join with the code
    And I should see "Di asks to join"
    And I press "Enter"
    And the guest should hear a "welcome" message
    When another guest "Ed" asks to join
    Then the guest should hear a "denied" message with "reason" "full"

  @setting:assist.maxFileSize
  Scenario: A file larger than the limit stays on the host
    Given the project configuration:
      """
      {"plugins": {"assist": {"maxFileSize": 1}}}
      """
    And a file "big.txt" of more than 2 MB beginning with "big"
    And I start the app with the arguments "src/A.php"
    And I use the action "assist:share"
    And a guest "Ana" asks to join with the code
    And I should see "Ana asks to join"
    And I press "Enter"
    And the guest should hear a "welcome" message
    When the guest asks for the project's files
    Then the files offered should include "src/A.php" but not "big.txt"

  Scenario: Joining from inside Porta hands over the command to run
    Given I start the app with the arguments "src/A.php"
    When I use the action "assist:join"
    Then I should see "Code from the host"
    When I type "42-k7qm-x2pa"
    And I press "Enter"
    Then a toast should say "run it in a terminal: porta assist 42-K7QM-X2PA"
    And the clipboard should contain "porta assist 42-K7QM-X2PA"
