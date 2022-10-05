<?php 
// Require the config.php file at the top of every function file. 
require "config.php";

// Login function and also function template
// Function: Login
// Inputs: string $username, string $password
// Outputs: int 0 on success, int 1 on incorrect password or username not found, int 2 on server error. 

// We are returning the same error code 1 for incorrect password OR username not found to limit the amount of info given to user for security purposes. This prevents user from trying a random username and being shown the message "your password is incorrect", meaning the username exists. Instead, they will just see "your login details are incorrect" which doesn't give them any hint that the account exists. Not so impt for username logins, moreso for email logins, but we'll just do it anyway. 

// Function declarations start with function <function name> (<arguments>). 
// This is the login function. It accepts the arguments string username and string password and returns the integer 0 on success or 1 or 2 on error. 
// PHP automatically assigns data types to variables, so you do not need to specify a data type for a new variable. However, we will use data type declarations in our function arguments to prevent unexpected errors from incorrect user input. 
function login(string $username, string $password) {

    // To prevent sql injection, we will use mysqli_prepare. It is like a string format that tells the system to treat each user input only as its specified data type. 
    // There are a few ways to do this but we are using the object-oriented method. 
    // So, even if a user were to input `Bob"; DROP TABLE users;`, the system will treat that whole input as just a string and not the "DROP TABLE" sql command. 
    // WITHOUT mysqli_prepare: "select password from users where username = Bob"; DROP TABLE users; <- the DROP TABLE command is executed
    // WITH mysqli_prepare: "select password from users where username = Bob\"; DROP TABLE users;" <- the user-input " is escaped and the DROP TABLE command is treated as part of the string. 
    // https://www.php.net/manual/en/mysqli.prepare.php

    // The ? is a placeholder for our variable. 
    $sql = $conn->prepare("SELECT `account_id`, `password` FROM `accounts` WHERE `username` = ?");
    
    if(
        // Bind the parameter $username (from our function argument) as a string ("s"). The number of bind params have to match the number of "?"s in the prepare statement. 
        // To bind multiple parameters (and multiple datatypes), it will look like: $stmt->bind_param("sidb", $someString, $someInteger, $someFloat, $someBlob) 
        $sql->bind_param("s", $username) &&
        // Execute the query
        $sql->execute() &&
        // Store result
        $sql->store_result() &&
        // Bind the result(s) to new variables, according to the order of variables selected in the sql statement. Datatypes are auto assigned. 
        $sql->bind_result($account_id, $hash) &&
        // Fetch the value
        $sql->fetch()
    ) {
        // Check if any record exists; number of matching record rows greater than 0
        if($sql->num_rows > 0) {
            // To store passwords securely, we are using PHP's built-in password_hash and password_verify functions. We do not store plaintext or encrypted passwords in the database, only hashes. 
            // https://www.php.net/manual/en/function.password-hash.php
            // Compare the user-input $password with the $hash we just fetched from database. 
            if(password_verify($password, $hash)) {
                // Correct password, log the user in. 
                // $_SESSION is a global variable used across the website. It stores user data across multiple accesses of any page of the website that has session_start(). -> included in all our files, because we require config.php which has the session_start()
                // https://www.php.net/manual/en/intro.session.php
                $_SESSION["account_id"] = $account_id;
                $_SESSION["username"] = $username;

                // The user is "logged in" at this point - their identifiers are stored in the session. However, we will also add a new record of the login to the database. 
                $sql2 = $conn->prepare("INSERT INTO `logins` (`account_id`, `timestamp`, `ip_address`) VALUES (?, ?, ?)");
                // Get the current unix timestamp
                $time = time();
                if( 
                    $sql2 &&
                    $sql2->bind_param('iis', $account_id, $time, $_SERVER['REMOTE_ADDR']) &&
                    $sql2->execute()
                    // Notice that we don't need to bind_result or store_result for INSERT queries, because no result is produced.
                ) {
                    // Successfully created new login record. 
                    return 0;
                }
                else {
                    // Database error
                    if($debug_mode) echo $conn->error;
                    return 2;
                }
            }
            else {
                // Wrong password
                return 1;
            }
        }
        else {
            // Username not found
            return 1;
        }
    }
    else {
        // Database error
        if($debug_mode) echo $conn->error;
        return 2;
    }
}
?>