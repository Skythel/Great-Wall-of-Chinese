<?php
include "config.php";
include "functions_utility.php";

// Retrieve account_id using SESSION
$account_id = getLoggedInAccountId();

$student = new Student($conn);

// Trigger createCustomGame
if(isset($_POST["customLevelName"]) && isset($_POST["question_type_difficulty"]) && isset($_POST["function_name"]) && $_POST["function_name"] == "createCustomGame"){
    echo $student->createCustomGame($account_id, $_POST["customLevelName"], $_POST["question_type_difficulty"]);
}

// Trigger viewAllCustomGame
if(isset($_POST["function_name"]) && $_POST["function_name"] == "viewAllCustomGame"){
    echo $student->viewAllCustomGame($account_id);
}

// Trigger viewAssignedAssignment
if(isset($_POST["function_name"]) && $_POST["function_name"] == "viewAssignedAssignment"){
    echo $student->viewAssignedAssignment($account_id);
}

// Trigger deleteCustomGame
if(isset($_POST["customLevelName"]) && isset($_POST["function_name"]) && $_POST["function_name"] == "deleteCustomGame"){
    echo $student->deleteCustomGame($account_id, $_POST["customLevelName"]);
}

// Trigger viewProfile
if(isset($_POST["username"]) && isset($_POST["function_name"]) && $_POST["function_name"] == "viewProfile"){
    echo $student->viewProfile($_POST["username"]);
}

// A Student class that holds all the function needed for students
class Student
{
    private $conn;
    
    // A constructor that calls database controller once.
    public function __construct($db)
    {
        $this->conn = $db;
    }
   
    // Function: Opponent choose to accept/reject Pvp request
    // Inputs: int $requester_id, int $opponent_id, $status
    // Outputs: Return the status(0/1/3) Update the Pvp_session with the status (Accept/Reject/Expired(If time exceeded)
    //          int 4 on requeseter_id/opponent_id is not exists
    //          int 2 on server error. 
    
    public function acceptPvpRequest(int $requester_id, int $opponent_id, $status)
    {
        // Check to see if requester_id or opponent_id is exists
        if (!checkAccountIdExists($requester_id) or !checkAccountIdExists($opponent_id)) return 4;
        
        // 0 status = accept; 1 status = reject, 2 status = Waiting, 3 status = Expired
        // Obtain the latest timestamp's pvp request
        $sql_1 = "SELECT * FROM pvp_session WHERE requester_id = ? AND opponent_id = ? ORDER BY timestamp DESC LIMIT 1";
        $stmt_1 = $this->conn->prepare($sql_1);
        
        if (
            $stmt_1->bind_param('ii', $requester_id, $opponent_id) &&
            $stmt_1->execute()
        ){
            $result_1 = $stmt_1->get_result();
            $row_1 = $result_1->fetch_assoc();
            $timestamp = time();
            // Accept/Reject Time - TimeOfPvpRequest send
            $time_diff = $timestamp-$row_1['timestamp'];
            
            $sql_2 = "UPDATE pvp_session SET status = ? WHERE requester_id = ? AND opponent_id = ? AND timestamp = ?";
            $stmt_2 = $this->conn->prepare($sql_2);
            
            // Pvprequest exceed 1minute, automatically set status become 3 = Expired
            if ($time_diff > 60)
            {   
                $status = 3;
            }
            
            // Update the Pvp session with the status Accept/Reject/Expired
            if(
                $sql_2 && 
                $stmt_2->bind_param('iiii', $status, $requester_id, $opponent_id, $row_1['timestamp']) &&
                $stmt_2->execute()
            ){
                return $status;
            }
            else
            {
                if($debug_mode) echo $this->conn->error;
                    return 2; // ERROR with database SQL
            }
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 2; // ERROR with database SQL
        }
    }
    
    // Function: helper function to check if the customLevelName has been created before
    //           To prevent having duplicates customGameName
    // Inputs: int int $account_id, string $customLevelName
    //                                    
    // Outputs: TRUE: database already have this name which is created before by the user
    //          False: database never find this custom
    public function checkCustomGameNameExists(int $account_id, string $customLevelName): bool
    {
        // Check through the database to see if the user has a customLevelName which is created before
        $sql = "SELECT * FROM custom_levels WHERE account_id = ? AND customLevelName = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $account_id, $customLevelName);
        $stmt->execute();
        $stmt->store_result();
        if($stmt->num_rows > 0) return true;
        return false;
    }
    
    // Function: Student can create their own custom game based on their input
    // Inputs: int int $account_id, string $customLevelName, string $question_type_difficulty
    //                                    
    // Outputs: Int 0 on success, successfully created CustomGame
    //          int 1 on account_id is not exists
    //          int 2 on server error. 
    public function createCustomGame(int $account_id, string $customLevelName, string $question_type_difficulty)
    {
        // Check to see if account_id exists
        if (!checkAccountIdExists($account_id)) return 1;
        
        // Check to see if the customLevelName exists
        if ($this->checkCustomGameNameExists($account_id, $customLevelName)) return 2;
        
        // customGameName must be at least be 2 letters
        if (strlen($customLevelName) < 2) return 3;
        
        // Insert a row into custom_levels table based on user's input
        // $question_type_difficulty is a string variable, example "Idioms, Medium|Pinyin, Hard"
        $sql = "INSERT INTO custom_levels (account_id, customLevelName, question_type_difficulty, timestamp) VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $timestamp = time();
        
        // After that, a custom game Id row will be created in the custom_levels table
        if( 
            $stmt->bind_param('issi', $account_id, $customLevelName, $question_type_difficulty, $timestamp) &&
            $stmt->execute()
        ){
            return 0;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 4; // ERROR with database SQL
        }
    }
    
    // Function: Student can delete their own custom game
    // Inputs: int int $account_id, string $customLevelName
    //                                    
    // Outputs: Int 0 on success, successfully deleted CustomGame
    //          int 1 on account_id is not exists
    //          int 2 on server error. 
    public function deleteCustomGame(int $account_id, string $customLevelName)
    {
        // Check to see if account_id exists
        if (!checkAccountIdExists($account_id)) return 1;
        
        // Delete the custom level from the table, based on account_id and customLevelName
        $sql = "DELETE FROM custom_levels WHERE account_id = ? AND customLevelName = ?";
        $stmt = $this->conn->prepare($sql);
        
        // After that, that specific customgame row will be deleted from the database
        if( 
            $stmt->bind_param('is', $account_id, $customLevelName) &&
            $stmt->execute()
        ){
            return 0;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 2; // ERROR with database SQL
        }
    }
        
    // Functions: Send Pvp request to opponent
    // Inputs: int $requester_id, int $opponent_id, int $pvp_room_type
    // Outputs: Int 0 on success, successfully sendPvp request to opponent
    //          int 1 on requester/opponent_id is not exists
    //          int 2 on requester choose CustomGame, but has no customGame created. error
    //          int 3 on server error. 
    public function sendPvpRequest(int $requester_id, int $opponent_id, int $pvp_room_type)
    {
        // Check to see if requester_id or opponent_id is valid
        if (!checkAccountIdExists($requester_id) or !checkAccountIdExists($opponent_id)) return 1;
        
        // pvp_room_type -> 0 denotes choose CustomGame for PVP; 1 denotes choose RandomizeGame for PVP;
        if ($pvp_room_type == 0){
            // Check to see if user has create a custom game before anot
            // No Custom Game created return 1; (Please go create ..)
            if (!$this->checkCustomGameExists($requester_id)){
                return 2;
            }
        }
        // When you send a pvp request, it will create a row in the pvp_session table
        $status = 2; // when u send request the status default is 2 = Waiting 
        $timestamp = time();
        
        $sql = "INSERT INTO pvp_session (requester_id, opponent_id, status, timestamp, pvp_room_type) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        
        if (
            $stmt->bind_param('iiiii', $requester_id, $opponent_id, $status, $timestamp, $pvp_room_type) &&
            $stmt->execute()
        ){
            return 0;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 3; // ERROR with database SQL
        }
    }
    
    // Functions: Student to view other Players profiles
    // Inputs: int string $viewPlayerName
    // Outputs: Upon success, will return a string of information of the player that you want view
    // Example of Output: 0,0,0,0,0,0,0,0,0,0,0,0,NO RECORD YET (idiom.js got more information about it)
    //          int 1 on player that you want to view does not exists
    //          int 2 on database error
    public function viewProfile(string $viewPlayerName)
    {
        // Retrieve account_id using SESSION
        $account_id = getLoggedInAccountId();
        
        // Check to see if player that you want view is valid
        if (!checkAccountIdExists($account_id)) return 1;
        
        // variable of the viewProfile information
        $viewProfile_str = '';
        $sql = "SELECT s.student_id, s.idiom_lower_correct, s.idiom_lower_attempted, s.idiom_upper_correct, s.idiom_upper_attempted,
                 s.fill_lower_correct, s.fill_lower_attempted, s.fill_upper_correct, s.fill_upper_attempted,
                 s.pinyin_lower_correct, s.pinyin_lower_attempted, s.pinyin_upper_correct, s.pinyin_upper_attempted
                 FROM students s INNER JOIN accounts a ON s.student_id = a.account_id WHERE a.username = ?";
        
        $stmt = $this->conn->prepare($sql);
        if( 
            $stmt->bind_param('s', $viewPlayerName) &&
            $stmt->execute()
        ){
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            // Obtain the profile information such as the (IDIOM_correct/Fill_correct/Pinyin_correct)
            $viewProfile_str = "$viewProfile_str{$row['idiom_lower_correct']},{$row['idiom_lower_attempted']},"
                            . "{$row['idiom_upper_correct']},{$row['idiom_upper_attempted']},{$row['fill_lower_correct']},"
                            . "{$row['fill_lower_attempted']},{$row['fill_upper_correct']},"
                            . "{$row['fill_upper_attempted']},{$row['pinyin_lower_correct']},"
                            . "{$row['pinyin_lower_attempted']},{$row['pinyin_upper_correct']},"
                            . "{$row['pinyin_upper_attempted']}";

            $view_studentId = $row['student_id'];

            $sql_2 = "SELECT l.rank FROM leaderboard l WHERE account_id = ?";
            $stmt_2 = $this->conn->prepare($sql_2);
            if(
               $stmt_2->bind_param('i', $view_studentId) &&
               $stmt_2->execute()
            ){
                $result_2 = $stmt_2->get_result();
                $num_rows = $result_2->num_rows;
                
                if ($num_rows > 0){
                    $row_2 = $result_2->fetch_assoc();
                    $viewProfile_str = "$viewProfile_str,{$row_2['rank']}";
                }
                else{
                    $viewProfile_str = "$viewProfile_str,NO RECORD YET";
                }   
            }
            return $viewProfile_str;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 2; // ERROR with database SQL
        }
    }

    // Functions: Student to view assigned assignment
    // Inputs: int $account_id
    // Outputs: Upon success, will return a list of assignments assigned to the student
    //          int 1 on player that you want to view does not exists
    //          int 2 on database error
    public function viewAssignedAssignment()
    {
        // Retrieve account_id using SESSION
        $account_id = getLoggedInAccountId();
        
        // Check to see if player that you want view is valid
        if (!checkAccountIdExists($account_id)) return 1;
        
        $sql = "SELECT a.assignment_id, a.assignment_name, a.due_timestamp 
            FROM assignments a INNER JOIN students s ON a.account_id = s.teacher_account_id 
            WHERE s.student_id = ? AND a.sent_to_students = 1 AND a.due_timestamp > unix_timestamp() AND
                NOT EXISTS (SELECT * FROM assignments_log a_l 
                            WHERE a_l.account_id = s.student_id AND a.assignment_id = a_l.assignment_id)";
        
        $stmt = $this->conn->prepare($sql);
        $assigned_assignment_str = "";

        if( 
            $stmt->bind_param('i', $account_id) &&
            $stmt->execute()
        ){
            $result = $stmt->get_result();
            $num_rows = $result->num_rows;
            $count = 0;
            $comma = ',';
            while ($row = $result->fetch_assoc())
            {
                // Concatenate all the customName created by the user into a string format
                $dueTime = convertIntToDate($row['due_timestamp']);
                $assigned_assignment_str = "$assigned_assignment_str{$row['assignment_name']},{$row['assignment_id']},{$dueTime}";

                if ($count+1 != $num_rows)
                    $assigned_assignment_str = $assigned_assignment_str.'|';
                $count = $count + 1;
            }

            return $assigned_assignment_str;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 2; // ERROR with database SQL
        }
    }
    
    // Functions: Student to view all its created Custom Game
    // Inputs: int $account_id
    // Outputs: Upon success, will return a string of CustomLevelName 
    //          int 1 on player that you want to view does not exists
    //          int 2 on database error
    public function viewAllCustomGame(int $account_id)
    {
        // Check if user id exists
        if (!checkAccountIdExists($account_id)) return 1;
        
        $customLevelName_str = '';
        
        // sql statement to retrieve all the data of customGame created by the account_id
        $sql = "SELECT customLevelName FROM custom_levels WHERE account_id = ?";
        $stmt = $this->conn->prepare($sql);
        
        if( 
            $stmt->bind_param('i', $account_id) &&
            $stmt->execute()

        ){
            $result = $stmt->get_result();
            $num_rows = $result->num_rows;
            $count = 0;
            while ($row = $result->fetch_assoc())
            {
                // Concatenate all the customName created by the user into a string format
                $customLevelName_str = $customLevelName_str.$row['customLevelName'];
                if ($count+1 != $num_rows)
                    $customLevelName_str = $customLevelName_str.',';
                $count = $count + 1;
            }
            return $customLevelName_str;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                    return 2; // ERROR with database SQL
        }
    }
}
?>