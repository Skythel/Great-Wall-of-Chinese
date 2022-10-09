<?php
// Require the config.php file at the top of every function file. 
//require "config.php";

// A Student class that holds all the function needed for students
class Student
{
    private $conn;
    
    // A constructor that calls database controller once.
    public function __construct($db)
    {
        $this->conn = $db;
    }
   
    // Opponent choose to accept/reject Pvp request
    public function acceptPvpRequest(int $requester_id, int $opponent_id, $status)
    {
        // 0 status = accept; 1 status = reject, 2 status = Wating, 3 status = Expired
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
                return 0;
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
    
    // student can create their own custom game based on their input
    public function createCustomGame(int $account_id, int $idiom_lower_count, int $idiom_upper_count,
                                     int $fill_lower_count, int $fill_upper_count,
                                    int $pinyin_lower_count, int $pinyin_upper_count)
    {
        
        // Everytime when a user successfully create a custom game.
        // generateQnBank will be called first to randomly generate the questions from the question table
        // and store it under question_bank 
        $this->generateQnBank($account_id, $idiom_lower_count, $idiom_upper_count, $fill_lower_count, $fill_upper_count, $pinyin_lower_count, $pinyin_upper_count);
        $timestamp = time();
        $sql_1 = "INSERT INTO custom_levels (account_id, idiom_lower_count, idiom_upper_count,"
                . "fill_lower_count, fill_upper_count, pinyin_lower_count,"
                . " pinyin_upper_count, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_1 = $this->conn->prepare($sql_1);
        $timestamp = time();
        // After that, a custom game Id row will be created in the custom_levels table
        if( 
            $stmt_1->bind_param('iiiiiiii', $account_id, $idiom_lower_count, $idiom_upper_count,
                                     $fill_lower_count, $fill_upper_count,
                                    $pinyin_lower_count, $pinyin_upper_count, $timestamp) &&
            $stmt_1->execute()
        ){
            return 0;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 2; // ERROR with database SQL
        }
    }
    
    // A helper function for createCustomGame function.
    public function generateQnBank(int $account_id, int $idiom_lower_count, int $idiom_upper_count,
                                        int $fill_lower_count, int $fill_upper_count,
                                        int $pinyin_lower_count, int $pinyin_upper_count)
    {
        $qn_category = ['idiom_Lower pri', 'idiom_Upper pri','fill_Lower_pri',
                        'fill_Upper_pri','pinyin_Lower pri', 'pinyin_Upper pri'];
        
        $qn_list = [$idiom_lower_count, $idiom_upper_count, $fill_lower_count,
            $fill_upper_count, $pinyin_lower_count, $pinyin_upper_count];
        
        for ($x=0; $x <count($qn_list); $x++)
        {
            if ($qn_list[$x] > 0)
            {
                
                // Using delimiter to extract the section name question type name
                // word[0] = fill/pinyin/idiom
                // word[1] = Lower pri / Upper pri
                $delimiter = '_';
                $word = explode($delimiter, $qn_category[$x]); 
                
                $sql_1 = "SELECT * FROM questions WHERE section = ? AND question_type = ?
                        ORDER BY RAND()
                        LIMIT ?";
                $stmt_1 = $this->conn->prepare($sql_1);
                if( 
                    $stmt_1->bind_param('ssi', $word[1], $word[0], $qn_list[$x]) &&
                    $stmt_1->execute()
                ){
                    $result = $stmt_1->get_result();
                    while ($row = $result->fetch_assoc())
                    {
                        $sql_2 = "INSERT INTO questions_bank (question_type, section, level, question, choice1, choice2, choice3, choice4, answer, explanation, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt_2 = $this->conn->prepare($sql_2);
                    
                        if(
                            $stmt_2->bind_param('ssssssssssi', $row['question_type'], $row['section'],
                                                $row['level'], $row['question'], $row['choice1'],
                                                $row['choice2'], $row['choice3'], $row['choice4'],
                                                $row['answer'], $row['explanation'], $account_id) &&
                            $stmt_2->execute()  
                        ){
                            continue;
                        }
                        else
                        {
                            if($debug_mode) echo $this->conn->error;
                                return 2; // ERROR with database SQL
                        }
                    }
                }
                else
                {
                    if($debug_mode) echo $this->conn->error;
                        return 2; // ERROR with database SQL
                }
            }
        }
        return 0;
    }
    
    // Send Pvp request to opponent
    public function sendPvpRequest(int $requester_id, int $opponent_id, int $choice)
    {
        // When you send a pvp request, it will create a row in the pvp_session table
        $status = 2; // when u send request the status default is 2 = Waiting 
        $timestamp = time();
        $sql = "INSERT INTO pvp_session (requester_id, opponent_id, status, timestamp, choice) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        
        if (
            $stmt->bind_param('iiiii', $requester_id, $opponent_id, $status, $timestamp, $choice) &&
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
    
    // Function for Student to view other Players profiles
    public function viewProfile(int $account_id)
    {
        $sql = "SELECT * FROM students s LEFT JOIN accounts a ON a.account_id = s.student_id WHERE s.student_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        
        if( 
            $stmt->bind_param('i', $account_id) &&
            $stmt->execute()
        ){
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            return $row;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 2; // ERROR with database SQL
        }
    }
}
?>