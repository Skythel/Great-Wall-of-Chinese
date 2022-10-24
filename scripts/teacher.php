<?php
include "config.php";
include "functions_utility.php";

// Retrieve the account_id(teacher_id) using session
$account_id = getLoggedInAccountId();
$created_timestamp = time();

$teacher = new Teacher($conn);

// triggerCreateAssignment
if(isset($_POST["assignmentName"]) && isset($_POST["dateInput"]) && isset($_POST["qnSendToBackend"])
        && isset($_POST["function_name"]) && $_POST["function_name"] == "createAssignment"){
    echo $teacher->createAssignment($_POST["assignmentName"], $account_id, $created_timestamp, convertDateToInt($_POST["dateInput"]), $_POST["qnSendToBackend"]);
}

// triggerViewSummaryReport
if(isset($_POST["function_name"]) && $_POST["function_name"] == "viewSummaryReport"){
    echo $teacher->viewSummaryReport($account_id);
}

// A Teacher class that holds all the function needed for teacher
class Teacher{
    
    private $conn;
    
    // A constructor that calls database controller once.
    public function __construct($db)
    {
        $this->conn = $db;
    }
   
    // Function: helper function to check if the assignmentName has been created before
    //           To prevent having duplicates assignmentName
    // Inputs: int int $account_id, string assignmentName
    //                                    
    // Outputs: TRUE: database already have this name which is created before by the user
    //          False: database never find this custom
    public function checkAssignmentNameExists(int $account_id, string $assignment_name): bool
    {
        // Check through the database to see if the user has a customLevelName which is created before
        $sql = "SELECT * FROM assignments WHERE account_id = ? AND assignment_name = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $account_id, $assignment_name);
        $stmt->execute();
        $stmt->store_result();
        if($stmt->num_rows > 0) return true;
        return false;
    }

    // Functions: A function for teachers to create assignment
    // Inputs: int $account_id (teacher_id)
    // Outputs: Upon success, will return 0. Successfully create assignment
    //          int 1 on the teacher is not exists
    //          int 2 on database error
    function createAssignment(string $assignment_name, int $account_id, int $created_timestamp, int $due_timestamp, string $questions)
    {
        // Check if account id exists
        if(!checkAccountIdExists($account_id)) return 1;

        // Check if AssignmentName exists
        if($this->checkAssignmentNameExists($account_id, $assignment_name)) return 2;
        
        // Iterate through the questions, as questions is an arrayList
        // $sql_var[0] - question
        // $sql_var[1] - choice1, questions[x][2]-choice2, questions[x][3]-choice3, questions[x][4]-choice4
        // $sql_var[5] - answer
        // $sql_var[6] - explanation

        $arrayOfQuestion =  stringToArray($questions, '|');

        for ($x = 0; $x < count($arrayOfQuestion); $x++)
        {
            // First SQL statement is to insert the questions to the questions_bank table
            
            $sql_1 = "INSERT INTO questions_bank(question, choice1, choice2, choice3, choice4, answer, explanation, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_1 = $this->conn->prepare($sql_1);
            
            $sql_var = stringToArray($arrayOfQuestion[$x], ',');

            if(
                $stmt_1->bind_param('sssssssi', $sql_var[0], $sql_var[1], $sql_var[2], $sql_var[3],
                                                $sql_var[4], $sql_var[5], $sql_var[6], $account_id) &&
                $stmt_1->execute()
            ){
                continue;
            }
            else
            {
                if($debug_mode) echo $this->conn->error;
                        return 3; // ERROR with database SQL
            }
        }
        // Second SQL statement is to insert into the assignment_table
        $sql_2 = "INSERT INTO assignments(assignment_name, account_id, created_timestamp, due_timestamp, questions) VALUES (?, ?, ?, ?, ?)";
        $stmt_2 = $this->conn->prepare($sql_2);

        if(
            $stmt_2->bind_param('siiis', $assignment_name, $account_id, $created_timestamp, $due_timestamp,
                                            $questions) &&
            $stmt_2->execute()
        ){
            return 0;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 3; // ERROR with database SQL
        }
    }
    
    // A utility function to check if the teacher has any students
    public function checkTeacherHasStudentExists($teacher_account_id) : bool
    {
        $sql = "SELECT * FROM students WHERE teacher_account_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $teacher_account_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $num_row = $result->num_rows;
        if($num_row < 1){
            return false;
        } 
        return true;
    }
    
    // Functions: A function for teachers to view students' summary report
    // Inputs: int $teacher_account_id
    // Outputs: Upon success, will return a string of information of all its students
    // Example: Kelvin,5,10,0,0,0,0,0,0,15,20,0,0 | Kelly,10,10,0,0,0,0,0,0,20,20,0,0
    //          int 1 on the teacher is not exists
    //          int 2 on teacher want to viewSummaryReport but has no students under him/her
    //          int 3 database error
    public function viewSummaryReport($teacher_account_id)
    {
        // Check to see if account_id exist
        if (!checkTeacherExists($teacher_account_id)) return 1;

        // Check to see if teacher has students
        if (!$this->checkTeacherHasStudentExists($teacher_account_id)) return 2;
        
        $sql = "SELECT a.name, s.idiom_lower_correct, s.idiom_lower_attempted, s.idiom_upper_correct, s.idiom_upper_attempted,
                 s.fill_lower_correct, s.fill_lower_attempted, s.fill_upper_correct, s.fill_upper_attempted,
                 s.pinyin_lower_correct, s.pinyin_lower_attempted, s.pinyin_upper_correct, s.pinyin_upper_attempted
                 FROM students s INNER JOIN accounts a ON s.student_id = a.account_id WHERE s.teacher_account_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $students_summary_str = "";
        
        if (
                $stmt->bind_param('i', $teacher_account_id) &&
                $stmt->execute()
        ){
            $result = $stmt->get_result();
            $num_rows = $result->num_rows;
            $count = 0;
            $comma = ',';
            while ($row = $result->fetch_assoc())
            {
                // Concatenate all the customName created by the user into a string format
                $students_summary_str = $students_summary_str.$row['name'].$comma.
                        $row['idiom_lower_correct'].$comma.$row['idiom_lower_attempted'].
                        $comma.$row['idiom_upper_correct'].$comma.$row['idiom_upper_attempted'].$comma.$row['fill_lower_correct'].
                        $comma.$row['fill_lower_attempted'].$comma.$row['fill_upper_correct'].
                        $comma.$row['fill_upper_attempted'].$comma.$row['pinyin_lower_correct'].
                        $comma.$row['pinyin_lower_attempted'].$comma.$row['pinyin_upper_correct'].
                        $comma.$row['pinyin_upper_attempted'];

                if ($count+1 != $num_rows)
                    $students_summary_str = $students_summary_str.'|';
                $count = $count + 1;
            }
            return $students_summary_str;
        }
        else
        {
            if($debug_mode) echo $this->conn->error;
                return 3; // ERROR with database SQL
        }       
    }
}

