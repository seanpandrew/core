Feature: restrict resharing

As admin
I would like to be able to forbid the sharing of a received share globaly
As user
I would like to be able to forbid a user that received a share from me to share it further

	Background:
		Given these users exist:
		|username|password|displayname|email       |
		|user1   |1234    |User One   |u1@oc.com.np|
		|user2   |1234    |User Two   |u2@oc.com.np|
		|user3   |1234    |User Three |u2@oc.com.np|
		And these groups exist:
		|groupname|
		|grp1     |
		And the user "user1" is in the group "grp1"
		And the user "user2" is in the group "grp1"
		And I am on the login page
		And I login with username "user1" and password "1234"
		And I logout
		And I login with username "user2" and password "1234"

	Scenario: share a folder with another internal user and prohibit resharing
		And the setting "Allow resharing" in the section "Sharing" is enabled
		And I am on the files page
		When the folder "simple-folder" is shared with the user "User One"
		And the sharing permissions of "User One" for "simple-folder" are set to
		| share | no |
		And I logout
		And I login with username "user1" and password "1234"
		Then it should not be possible to share the folder "simple-folder (2)"

	Scenario: forbid resharing globaly
		When the setting "Allow resharing" in the section "Sharing" is disabled
		And I am on the files page
		And the folder "simple-folder" is shared with the user "User One"
		And I logout
		And I login with username "user1" and password "1234"
		Then it should not be possible to share the folder "simple-folder (2)"