<?php

namespace Skythel\Websocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Amp\Mysql;

class Socket implements MessageComponentInterface {

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $client) {

        echo "Connecting new client...\n";

        // Store the new connection in $this->clients
        $this->clients->attach($client);

        // Change the token in db to connected
        \Amp\Loop::run(function() use ($client) {
            // Get the auth token
            parse_str($client->httpRequest->getUri()->getQuery(), $queryParameters);
    
            require "../../../secrets.php";
            $config = \Amp\Mysql\ConnectionConfig::fromString(
                "host=127.0.0.1 user=$username password=$password db=$db"
            );
            
            /** @var \Amp\Mysql\Pool $pool */
            $pool = \Amp\Mysql\pool($config);
            
            /** @var \Amp\Mysql\Statement $statement */
            $statement = yield $pool->prepare("update socket_connections set status='Connected', resource_id=:id where token=:token");
            
            /** @var \Amp\Mysql\ResultSet $result */
            if(yield $statement->execute(['id' => $client->resourceId, 'token' => $queryParameters['token']])) {

                // Save the client's username
                $statement2 = yield $pool->prepare("select accounts.username, accounts.account_id, students.character_type, students.teacher_account_id from accounts join socket_connections on accounts.account_id = socket_connections.account_id join students on accounts.account_id = students.student_id where token=:token and status='Connected' order by timestamp desc limit 1");

                $result = yield $statement2->execute(['token' => $queryParameters['token']]);
                yield $result->advance();
                $row = $result->getCurrent();
                $client->userinfoUsername = $row["username"];
                $client->userinfoID = $row["account_id"];
                $client->userinfoCharacter = $row["character_type"];
                $client->userinfoWorld = $queryParameters["world"];
                $client->teacherID = $row["teacher_account_id"]; 
                $client->posX = 200;
                $client->posY = 400;
                
                // Hold the username of opponent, time updated, and status in an array
                // ["Available", <anything>, <unix_time>]: the client is available for pvp
                // ["Sent", "user1", <unix_time>]: the client has sent a challenge to user1
                // ["Received", "user1", <unix_time>]: the client has received a challenge from user1
                // ["Playing", "user1", <unix_time>]: the client is currently playing against user1
                $client->pvpStatus = ["Available", "", time()]; 
                $client->pvpScore = 0;

                // Hold the client's current room that they are doing adventure or assignment in
                // $client->currentRoom = [];

                // Pass the coordinates and character id to all logged in players in the game world
                $allPlayers["firstload"] = 0;
                foreach ($this->clients as $player) {
                    // var_dump($player->posX);
                    // var_dump($player->posY);
                    if($client->userinfoUsername == $player->userinfoUsername) continue;
                    if($player->userinfoWorld == $client->userinfoWorld && $client->userinfoUsername !== $player->userinfoUsername) {
                        $player->send("[connect] $client->userinfoUsername: $client->userinfoCharacter");
                        $allPlayers[$player->userinfoUsername] = "$player->userinfoCharacter-$player->posX-$player->posY";
                    }
                }

                // Send the client the data of all logged in users
                $client->send(json_encode($allPlayers));
                
                echo "$client->userinfoUsername#$client->userinfoID just connected as Client$client->resourceId with token {$queryParameters['token']}!\n";
            }
            else {
                echo "Client ({$client->resourceId}) encountered error.\n";
            }
            $pool->close();
        });
    }

    public function onMessage(ConnectionInterface $client, $msg) {

        // Character moving
        if(preg_match_all("/^\/move x(.+) y(.+) t(.+)$/", $msg, $matches)) {
            $posX = $matches[1][0];
            $posY = $matches[2][0];
            $time = $matches[3][0];
            // Save the player's updated location
            $client->posX = $posX;
            $client->posY = $posY;
            echo "$client->userinfoUsername moved to x$client->posX y$client->posY t$time\n";

            // Broadcast the update to all other players
            foreach ($this->clients as $player) {
                // if($client->userinfoUsername == $player->userinfoUsername) continue;
                if($player->userinfoWorld == $client->userinfoWorld && $client->userinfoUsername !== $player->userinfoUsername) {
                    $player->send("[move] $client->userinfoUsername: c$client->userinfoCharacter x$client->posX y$client->posY t$time");
                }
            }
        }

        // Update the client
        if(preg_match("/^\/update$/", $msg)) {
            $allPlayers = [];
            foreach ($this->clients as $player) {
                // if($client->userinfoUsername == $player->userinfoUsername) continue;
                if($player->userinfoWorld == $client->userinfoWorld && $client->userinfoUsername !== $player->userinfoUsername) {
                    $allPlayers[$player->userinfoUsername] = "$player->userinfoCharacter-$player->posX-$player->posY";
                }
            }

            // Send the client the data of all logged in users
            $client->send(json_encode($allPlayers));

            // TODO: Also refresh assignments 

            return;
        }

        if(preg_match_all("/^\/exit (.+)$/", $msg, $matches)) {
            if(strtolower($matches[1][0]) == "adventure") {
                $client->pvpStatus = ["Available", "", time(), 0];

            }
            elseif(strtolower($matches[1][0]) == "adventure") {
                $client->pvpStatus = ["Available", "", time(), 0];
            }
            elseif(strtolower($matches[1][0]) == "pvp") {
                if($client->pvpStatus[0] !== "Playing") {
                    unset ($client->currentRoom);
                    unset ($client->currentQuestion);
                }
                else {
                    foreach ($this->clients as $player) {
                        if(!strcasecmp($player->userinfoUsername, $client->pvpStatus[1])) {
                            $player->pvpStatus = ["Available", "", time(), 0];
                            unset ($player->currentRoom);
                            unset ($player->currentQuestion);
                            // unset ($player->slowpoke);
                            $player->send("[pvp] forfeit: Your opponent has forfeited.");
                        }
                    }
                    // unset ($client->slowpoke);
                    $client->send("[pvp] forfeit: You have forfeited the match.");
                }
                $client->pvpStatus = ["Available", "", time(), 0];
            }
        }

        // Start adventure mode
        if(preg_match_all("/^\/adventure (.+)/", $msg, $matches)) {
            $section = strtolower($matches[1][0]); // lower or upper

            // Make the player unavailable for pvp
            $client->pvpStatus = ["Playing", "", time()];

            \Amp\Loop::run(function() use ($client, $section) {
                require "../../../secrets.php";
                $config = \Amp\Mysql\ConnectionConfig::fromString(
                    "host=127.0.0.1 user=$username password=$password db=$db"
                );
            
                $pool = \Amp\Mysql\pool($config);
                
                // Get the client's accuracy data
                $statement = yield $pool->prepare("select * from students where student_id = :id");

                $result = yield $statement->execute(['id' => $client->userinfoID]);
                yield $result->advance();
                $row = $result->getCurrent();

                $resetdate = $row["{$client->userinfoWorld}_{$section}_reset_date"];

                // The reset date saved in db is the date at which the scores will be reset. Attempting adventure before this reset date will change the reset date to 2 days from the attempt. 
                if(time() > $resetdate) {
                    // Reset the scores
                    $correct = 0;
                    $attempted = 0;
                    $accuracy = 0;
                }
                else {
                    $correct = $row["{$client->userinfoWorld}_{$section}_correct"];
                    $attempted = $row["{$client->userinfoWorld}_{$section}_attempted"];
                    $accuracy = $correct / $attempted;
                }
                
                // Create the room id from <userid><timestamp> to be unique. These values won't ever be extracted from the room id, it is only used as an identifier. 
                $rid = intval($client->userinfoID.time());

                // Assign player to a new room
                $client->currentRoom = array("room" => $rid, "type" => "adv", "section" => $section, "totalCorrect" => $correct, "totalAttempted" => $attempted, "sessionCorrect" => [], "sessionAttempted" => []);
                // "sessionCorrect" and "sessionAttempted" hold arrays of question ids and answer given, only within currentRoom
                
                if($accuracy < 0.5) {
                    // Give easy question
                    $sql = "select * from questions where question_type like :world and section like :section and level = 'Easy' order by rand() limit 1";
                }
                elseif($accuracy < 0.75) {
                    // Give medium question
                    $sql = "select * from questions where question_type like :world and section like :section and level = 'Medium' order by rand() limit 1";
                }
                else {
                    // Give hard question
                    $sql = "select * from questions where question_type like :world and section like :section and level = 'Hard' order by rand() limit 1";
                }

                $statement = yield $pool->prepare($sql);
                $result = yield $statement->execute(['world' => $client->userinfoWorld, 'section' => "$section Pri"]);
                yield $result->advance();
                $row = $result->getCurrent();

                $client->currentQuestion = $row;
                $client->send("[question] adv: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}, {$row["level"]}");

                $pool->close();
            });
        }

        // Start assignment mode
        if(preg_match_all("/^\/assignment (.+)/", $msg, $matches)) {
            $assignment = $matches[1][0];  

            \Amp\Loop::run(function() use ($client, $assignment) {
                require "../../../secrets.php";
                $config = \Amp\Mysql\ConnectionConfig::fromString(
                    "host=127.0.0.1 user=$username password=$password db=$db"
                );
            
                $pool = \Amp\Mysql\pool($config);

                $statement = yield $pool->prepare("select assignments.account_id, assignments.assignment_id, count(*) as count from assignments join questions_bank on assignments.assignment_name = questions_bank.assignment_name where assignments.assignment_name = :name");
                $result = yield $statement->execute(['name' => $assignment]);
                yield $result->advance();
                $row = $result->getCurrent();
                // Make sure the client is allowed to answer this assignment
                if($client->teacherID !== $row["account_id"]) {
                    $client->send("[error] You are not allowed to take this assignment.");
                    echo "$client->userinfoUsername is not allowed to take this assignment.";
                    return;
                }

                // Make the player unavailable for pvp
                $client->pvpStatus = ["Playing", "", time()];
                
                // Create the room id from <userid><timestamp> to be unique. These values won't ever be extracted from the room id, it is only used as an identifier. 
                $rid = intval($client->userinfoID.time());

                // Assign player to a new room
                $client->currentRoom = array("room" => $rid, "type" => "ass", "asname" => $assignment, "asid" => $row["assignment_id"], "qns" => $row["count"], "sessionCorrect" => [], "sessionAttempted" => []);
                // "sessionCorrect" and "sessionAttempted" hold arrays of question ids and answer given, only within currentRoom
                
                $sql = "select * from questions_bank where assignment_name like :name order by rand() limit 1";
                $statement = yield $pool->prepare($sql);
                $result = yield $statement->execute(['name' => $assignment]);
                yield $result->advance();
                $row = $result->getCurrent();

                $client->currentQuestion = $row;
                $type = "ass-{$client->currentRoom["qns"]}";
                $client->send("[question] {$type}: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}");

                $pool->close();
            });
        }

        // Mark client's answer
        if(preg_match_all("/^\/answer (.+)/", $msg, $matches)) {

            // if(isset($client->slowpoke)) unset($client->slowpoke);
            // if(isset($player->slowpoke)) unset($player->slowpoke);

            $time = time();

            // Check game mode 
            if(isset($client->currentRoom["type"]) && $client->currentRoom["type"] == "adv") {
                $max_qns = 10;
            }
            elseif(isset($client->currentRoom["type"]) && $client->currentRoom["type"] == "ass") {
                $max_qns = $client->currentRoom["qns"];
            }
            elseif(isset($client->currentRoom["type"]) && $client->currentRoom["type"] == "pvp") {
                $max_qns = 5;
            }
            else {
                echo "$client->userinfoUsername is not allowed to answer\n";
                return;
            }
            $answer = $matches[1][0]; 
            
            \Amp\Loop::run(function() use ($client, $answer, $max_qns, $time) {
                require "../../../secrets.php";
                $config = \Amp\Mysql\ConnectionConfig::fromString(
                    "host=127.0.0.1 user=$username password=$password db=$db"
                );
            
                $pool = \Amp\Mysql\pool($config);

                $roomObject = $client->currentRoom;
                if(isset($roomObject["totalAttempted"])) $roomObject["totalAttempted"]++;
                $roomObject["sessionAttempted"][] = [$client->currentQuestion["question_id"], $answer];
                $correct = false;

                // Check against client's current question info
                if($client->currentQuestion["answer"] == $answer) {
                    // Correct
                    if(isset($roomObject["totalCorrect"])) $roomObject["totalCorrect"]++;
                    $roomObject["sessionCorrect"][] = [$client->currentQuestion["question_id"], $answer];
                    $correct = true;
                }
                $client->currentRoom = $roomObject;
                unset($roomObject);

                echo $client->currentQuestion["question_id"]; 

                // Record in database
                if($client->currentRoom["type"] == "adv") {
                    $sql = "insert into adventure_tracking (adventure_room_id, account_id, question_id, answer, timestamp) values (:rid, :acc_id, :q_id, :ans, :time)";
                    $statement = yield $pool->prepare($sql);
                    yield $statement->execute(['rid' => $client->currentRoom["room"], 'acc_id' => $client->userinfoID, 'q_id' => $client->currentQuestion["question_id"], 'ans' => $answer, 'time' => $time]);
                }
                elseif($client->currentRoom["type"] == "ass") { 
                    $sql = "insert into assignments_log (assignment_id, account_id, question_id, answer, timestamp) values (:asid, :acc_id, :q_id, :ans, :time)"; 
                    $statement = yield $pool->prepare($sql);
                    yield $statement->execute(['asid' => $client->currentRoom["asid"], 'acc_id' => $client->userinfoID, 'q_id' => $client->currentQuestion["question_id"], 'ans' => $answer, 'time' => $time]);
                }
                elseif($client->currentRoom["type"] == "pvp") {
                    $sql = "insert into pvp_tracking (pvp_room_id, account_id, question_id, answer, timestamp) values (:rid, :acc_id, :q_id, :ans, :time)";
                    $statement = yield $pool->prepare($sql);
                    yield $statement->execute(['rid' => $client->currentRoom["room"], 'acc_id' => $client->userinfoID, 'q_id' => $client->currentQuestion["question_id"], 'ans' => $answer, 'time' => $time]);

                    // $client->lastAnswer = $time;

                    // foreach ($this->clients as $player) {
                    //     if($player->pvpStatus[0] == "Playing" && !strcasecmp($player->pvpStatus[1], $client->userinfoUsername)) {
                            // if(isset($player->lastAnswer) && $player->lastAnswer < $time) {
                            
                            // Send the client's timestamp to opponent
                            // $player->send("[time] $time");

                            // Check if the opponent has already completed the question
                            // echo $player->userinfoUsername." ".count($player->currentRoom["sessionAttempted"]);
                            // echo $client->userinfoUsername." ".count($client->currentRoom["sessionAttempted"]);
                            // if(count($player->currentRoom["sessionAttempted"]) == count($client->currentRoom["sessionAttempted"])) {
                            //     echo "$player->userinfoUsername is slow\n";
                            //     // Set var for the opponent that they are slowwww
                            //     $parr = $player->slowpoke;
                            //     $carr = $client->slowpoke;
                            //     array_push($parr, false);
                            //     array_push($carr, true);
                            //     $player->slowpoke = $parr;
                            //     $client->slowpoke = $carr;
                            //     // $client->send("[slowpoke] you are slow");
                            //     // $player->send("[slowpoke] your opponent is slow");
                            // }
                            // break;

                            // }
                    //     }
                    // }

                }
                else {
                    echo "$client->userinfoUsername encountered an unknown error.";
                    return;
                }
                
                $correct = $correct ? 1 : 0;

                // Send the result and explanation
                if($client->currentRoom["type"] !== "pvp") {
                    $client->send("[answer] {$correct}!!!I LOVE CHINESEEE!!!{$client->currentQuestion["choice{$client->currentQuestion["answer"]}"]}!!!I LOVE CHINESEEE!!!{$client->currentQuestion["explanation"]}!!!I LOVE CHINESEEE!!!{$client->currentRoom["type"]}");
                }

                // Send the next question if any
                if(count($client->currentRoom["sessionAttempted"]) >= $max_qns) {
                    // Make sure opponent has finished before sending the result
                    // $stmt = yield $pool->prepare("select count(*) as count, pvp_tracking.answer as answer1, questions.answer as answer2 from pvp_tracking join questions on questions.question_id = pvp_tracking.question_id where pvp_room_id = :rid and account_id = (select account_id from accounts where username like :uname) and pvp_tracking.question_id = :qid and timestamp < :time");
                    // $res = yield $stmt->execute(['rid' => $client->currentRoom["room"], 'uname' => $client->pvpStatus[1], 'qid' => $client->currentQuestion["question_id"], 'time' => $time]);
                    // yield $res->advance();
                    // $roww = $res->getCurrent();
                    // var_dump($roww);

                    // if($roww["count"] > 0) {
                    
                    // echo "HELLO";

                    if($client->currentRoom["type"] == "adv") {
                        // Obtain the section
                        $section = $client->currentRoom["section"];
                        
                        // Update the players' record
                        // world_section_correct = e.g. idiom_lower_correct
                        // world_section_attempted = e.g. idiom_lower_attempted
                        $world_section_correct = $client->userinfoWorld.'_'.$section.'_correct';
                        $world_section_attempted = $client->userinfoWorld.'_'.$section.'_attempted';
                        $world_section_reset = $client->userinfoWorld.'_'.$section.'_reset_date';
                        $num_correct = count($client->currentRoom["sessionCorrect"]);
                        $num_attempted = count($client->currentRoom["sessionAttempted"]);
                        $reset_date = time()+86400*2;
                        
                        $sql = "UPDATE students SET {$world_section_correct} = {$world_section_correct} + {$num_correct}, {$world_section_attempted} = {$world_section_attempted} + {$num_attempted}, {$world_section_reset} = {$reset_date} WHERE student_id = {$client->userinfoID}";
                        $statement = yield $pool->query($sql);
                        // $statement->execute();
                    }
                    elseif($client->currentRoom["type"] == "ass") {

                    }
                    elseif($client->currentRoom["type"] == "pvp") {
                        $statement = yield $pool->prepare("select requester_id, opponent_id from pvp_session where pvp_room_id = :rid");
                        $result = yield $statement->execute(['rid' => $client->currentRoom["room"]]);
                        yield $result->advance();
                        $row = $result->getCurrent();

                        if($row["requester_id"] == $client->userinfoID) {
                            echo "test";
                            // $statement = yield $pool->prepare("update pvp_session set status = 2, requester_score = :score where pvp_room_id = :rid");
                            // $result = yield $statement->execute(['score' => $client->pvpScore, 'rid' => $client->currentRoom["room"]]);
                            $statement = yield $pool->query("update pvp_session set status = 2, requester_score = {$client->pvpScore} where pvp_room_id = {$client->currentRoom["room"]}");
                        }
                        elseif($row["opponent_id"] == $client->userinfoID) {
                            echo "test2";
                            // $statement = yield $pool->prepare("update pvp_session set status = 2, opponent_score = :score where pvp_room_id = :rid");
                            // $result = yield $statement->execute(['score' => $client->pvpScore, 'rid' => $client->currentRoom["room"]]);
                            $statement = yield $pool->query("update pvp_session set status = 2, opponent_score = {$client->pvpScore} where pvp_room_id = {$client->currentRoom["room"]}");
                        }

                        foreach ($this->clients as $player) {
                            if($player->pvpStatus[0] == "Playing" && !strcasecmp($player->pvpStatus[1], $client->userinfoUsername)) {
                                if(count($player->currentRoom["sessionAttempted"]) >= $max_qns) {
                                    // Send result
                                    // $clientWon = $client->pvpScore > $player->pvpScore ? 1 : 0;
                                    
                                    // Check if the scores didn't tie
                                    if($client->pvpScore !== $player->pvpScore) {
                                        // Retrieve player and client's rank points
                                        $result_player = yield $pool->query("select `rank`, `rank_points` from leaderboard where account_id = {$player->userinfoID}");
                                        echo "\nplayer id $player->userinfoID\n";
                                        // $result_player = yield $statement->execute();
                                        yield $result_player->advance();
                                        $row_player = $result_player->getCurrent();
                                        //$player_rank = $row_player['rank'];
                                        $player_rank_points = $row_player['rank_points'];
                                        switch($row_player['rank']) {
                                            case "Silver":
                                                $player_rank = 1; break;
                                            case "Gold":
                                                $player_rank = 2; break;
                                            case "Bling Bling":
                                                $player_rank = 3; break;
                                            default:
                                                $player_rank = 0; break;
                                        }

                                        $result_client = yield $pool->query("select `rank`, `rank_points` from leaderboard where account_id = {$client->userinfoID}");
                                        // $result_client = yield $statement->execute();
                                        yield $result_client->advance();
                                        $row_client = $result_client->getCurrent();
                                        //$client_rank = $row_client['rank'];
                                        $client_rank_points = $row_client['rank_points'];switch($row_client['rank']) {
                                            case "Silver":
                                                $client_rank = 1; break;
                                            case "Gold":
                                                $client_rank = 2; break;
                                            case "Bling Bling":
                                                $client_rank = 3; break;
                                            default:
                                                $client_rank = 0; break;
                                        }

                                        // The rank points multiplier matrix
                                        $multiplier = [
                                            [100, 125, 150, 200],
                                            [125, 100, 125, 150],
                                            [150, 125, 100, 125],
                                            [200, 150, 125, 100]
                                        ];

                                        if ($player->pvpScore > $client->pvpScore) {
                                            // Player won

                                            // Check player's multiplier, award high multiplier if the winner's rank is lower than loser's
                                            $player_multi = $player_rank < $client_rank ? $multiplier[$player_rank][$client_rank] : 200-$multiplier[$player_rank][$client_rank];
                                            $client_multi = 200-$player_multi;

                                            $player_new_rp = $player_rank_points + 25*($player_multi/100);
                                            $client_new_rp = $client_rank_points - 25*($client_multi/100);
                                        }
                                        elseif ($client->pvpScore > $player->pvpScore) {
                                            // Client won

                                            $client_multi = $client_rank < $player_rank ? $multiplier[$client_rank][$player_rank] : 200-$multiplier[$client_rank][$player_rank];
                                            $player_multi = 200-$client_multi;

                                            $player_new_rp = $player_rank_points - 25*($player_multi/100);
                                            $client_new_rp = $client_rank_points + 25*($client_multi/100);
                                        }
                                        else {
                                            // Tie
                                            echo "This error shouldn't be occurring";
                                        }

                                        echo "\nplayer multi: $player_multi\n";
                                        echo "\nclient multi: $client_multi\n";
                                        
                                        // If player's rank_points become negative - score, it will still be 0
                                        if  ($player_new_rp < 0) $player_new_rp = 0;
                                        if  ($client_new_rp < 0) $client_new_rp = 0; 
                                        
                                        // iterate twice because there is client/player 's
                                        // $new_rank_list will be overwritten with new rank 
                                        $new_rank_list = [$player_new_rp, $client_new_rp];
                                        for ($x=0; $x < count($new_rank_list); $x++)
                                        {
                                            $rank_points = $new_rank_list[$x];
                                            switch ($rank_points) {
                                            case $rank_points >= 100 && $rank_points < 300:
                                                $new_rank_list[$x] = "Silver";
                                                break;
                                            case $rank_points >= 300 && $rank_points < 1000:
                                                $new_rank_list[$x] = "Gold";
                                                break;
                                            case $rank_points >= 1000:
                                                $new_rank_list[$x] = "Bling Bling";
                                            default:
                                                $new_rank_list[$x] = "Bronze";
                                            }
                                        }
                                        // Update the database with new leaderboard points and rank
                                        $player_sql = "UPDATE leaderboard SET `rank`='{$new_rank_list[0]}', `rank_points`={$player_new_rp} WHERE `account_id` = {$player->userinfoID}";
                                        yield $pool->query($player_sql);

                                        $client_sql = "UPDATE leaderboard SET `rank`='{$new_rank_list[1]}', `rank_points`={$client_new_rp} WHERE `account_id` = {$client->userinfoID}";
                                        yield $pool->query($client_sql);
                                    }

                                    $client->send("[result] ".count($client->currentRoom["sessionCorrect"])." ".$client->pvpScore." ".count($player->currentRoom["sessionCorrect"])." ".$player->pvpScore);

                                    $player->send("[result] ".count($player->currentRoom["sessionCorrect"])." ".$player->pvpScore." ".count($client->currentRoom["sessionCorrect"])." ".$client->pvpScore);
                                    
                                    unset($client->currentQuestion);
                                    unset($client->currentRoom);
                                    unset($player->currentQuestion);
                                    unset($player->currentRoom);
                                    
                                    // Make the player available for pvp
                                    $client->pvpStatus = ["Available", "", time(), 0];
                                    $player->pvpStatus = ["Available", "", time(), 0];
                                    $client->pvpScore = 0;
                                    $player->pvpScore = 0;
                                    
                                    break;
                                }
                            }
                        }
                    }
                }
                else {
                    // Get array of attempted questions within this session
                    $attempted = $client->currentRoom["sessionAttempted"][0][0];
                    for($i = 1; $i<count($client->currentRoom["sessionAttempted"]); $i++) {
                        $attempted .= ", {$client->currentRoom["sessionAttempted"][$i][0]}";
                    }

                    if($client->currentRoom["type"] == "adv") {
                        $accuracy = $client->currentRoom["totalCorrect"] / $client->currentRoom["totalAttempted"];

                        // Send next question
                        if($accuracy < 0.5) {
                            // Give easy question
                            $sql = "select * from questions where question_type like :world and section like :section and level = 'Easy' and question_id not in ($attempted) order by rand() limit 1";
                        }
                        elseif($accuracy < 0.75) {
                            // Give medium question
                            $sql = "select * from questions where question_type like :world and section like :section and level = 'Medium' and question_id not in ($attempted) order by rand() limit 1";
                        }
                        else {
                            // Give hard question
                            $sql = "select * from questions where question_type like :world and section like :section and level = 'Hard' and question_id not in ($attempted) order by rand() limit 1";
                        }

                        $statement = yield $pool->prepare($sql);
                        $result = yield $statement->execute(['world' => $client->userinfoWorld, 'section' => "{$client->currentRoom["section"]} Pri"]);
                    }
                    elseif($client->currentRoom["type"] == "ass") {
                        $sql = "select * from questions_bank where assignment_name like :name and question_id not in ($attempted) order by rand() limit 1";
                        $statement = yield $pool->prepare($sql);
                        $result = yield $statement->execute(['name' => $client->currentRoom["asname"]]);
                    }
                    elseif($client->currentRoom["type"] == "pvp") {

                        var_dump($attempted);
                        // Get opponent's answer records
                        $stmt = yield $pool->prepare("select count(*) as count, pvp_tracking.answer as answer1, questions.answer as answer2 from pvp_tracking join questions on questions.question_id = pvp_tracking.question_id where pvp_room_id = :rid and account_id = (select account_id from accounts where username like :uname) and pvp_tracking.question_id = :qid and timestamp <= :time");
                        $res = yield $stmt->execute(['rid' => $client->currentRoom["room"], 'uname' => $client->pvpStatus[1], 'qid' => $client->currentQuestion["question_id"], 'time' => $time]);
                        yield $res->advance();
                        $roww = $res->getCurrent();
                        var_dump($roww);

                        echo $client->currentRoom["room"];
                        echo $client->pvpStatus[1];
                        echo $client->currentQuestion["question_id"];
                        echo $time;

                        if($roww["count"] > 0) {
                            // Get the next question from the opponent's variable
                            // foreach ($this->clients as $player) {
                            //     if($player->pvpStatus[0] == "Playing" && !strcasecmp($player->pvpStatus[1], $client->userinfoUsername)) {
                            //         $client->currentQuestion = $player->currentQuestion;

                            //         $client->send("[question] pvp: {$client->currentQuestion["question"]}, {$client->currentQuestion["choice1"]}, {$client->currentQuestion["choice2"]}, {$client->currentQuestion["choice3"]}, {$client->currentQuestion["choice4"]}");

                            //         break;
                            //     }
                            // }
                        // }
                        // else {
                            // Generate new question
                            if(isset($client->customQuestionQueue)) {
                                $sql = "select * from questions where question_type like :world and level like :level and question_id not in ($attempted) order by rand() limit 1";
                                $statement = yield $pool->prepare($sql);
                                $result = yield $statement->execute(['world' => $client->userinfoWorld, 'level' => $client->customQuestionQueue[0][1]]);
                                $arr = $client->customQuestionQueue;
                                array_shift($arr);
                                $client->customQuestionQueue = $arr;
                            }
                            else {
                                $sql = "select * from questions where question_type like :world and question_id not in ($attempted) order by rand() limit 1";
                                $statement = yield $pool->prepare($sql);
                                $result = yield $statement->execute(['world' => $client->userinfoWorld]);
                            }

                            yield $result->advance();
                            $row = $result->getCurrent();
                            $client->currentQuestion = $row; 
                            echo "sending client question\n";
                            $client->send("[question] pvp: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}");
                            
                            $client->send("[answer] {$correct}!!!I LOVE CHINESEEE!!!{$client->currentQuestion["choice{$client->currentQuestion["answer"]}"]}!!!I LOVE CHINESEEE!!!{$client->currentQuestion["explanation"]}!!!I LOVE CHINESEEE!!!{$client->currentRoom["type"]}");

                            foreach ($this->clients as $player) {
                                if($player->pvpStatus[0] == "Playing" && !strcasecmp($player->pvpStatus[1], $client->userinfoUsername)) {

                                    $player->currentQuestion = $row;
                                    $player->send("[question] pvp: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}");
                                    echo "sending player question\n";

                                    $opponentCorrect = $roww["answer1"] == $roww["answer2"] ? 1 : 0;

                                    // case where client submitted the answer after the player
                                    if($opponentCorrect) {
                                        $player->pvpScore += 50;
                                        $client->pvpScore += $correct ? 25 : 0;
                                    }
                                    else {
                                        $player->pvpScore += 0;
                                        $client->pvpScore += $correct ? 50 : 0;
                                    }

                                    $player->send("[answer] {$opponentCorrect}!!!I LOVE CHINESEEE!!!{$roww["answer2"]}!!!I LOVE CHINESEEE!!! !!!I LOVE CHINESEEE!!!{$client->currentRoom["type"]}!!!I LOVE CHINESEEE!!!first");

                                    $client->send("[pvp score] ".count($client->currentRoom["sessionAttempted"])." ".$client->pvpScore." ".count($player->currentRoom["sessionAttempted"])." ".$player->pvpScore);
                                    $player->send("[pvp score] ".count($player->currentRoom["sessionAttempted"])." ".$player->pvpScore." ".count($client->currentRoom["sessionAttempted"])." ". $client->pvpScore);

                                    break;
                                }
                            }
                        }

                        // if(isset($client->slowpoke) && $client->slowpoke == true) {
                        // if($client->currentRoom["type"] == "pvp") {
                        //     foreach ($this->clients as $player) {
                        //         if($player->pvpStatus[0] == "Playing" && !strcasecmp($player->pvpStatus[1], $client->userinfoUsername)) {
                        //             if(count($player->currentRoom["sessionAttempted"]) < count($client->currentRoom["sessionAttempted"])) {
                            // Get the next question from the opponent's variable
                            // foreach ($this->clients as $player) {
                            //     if($player->pvpStatus[0] == "Playing" && !strcasecmp($player->pvpStatus[1], $client->userinfoUsername)) {
                            //         $client->currentQuestion = $player->currentQuestion;

                            //         $client->send("[question] pvp: {$client->currentQuestion["question"]}, {$client->currentQuestion["choice1"]}, {$client->currentQuestion["choice2"]}, {$client->currentQuestion["choice3"]}, {$client->currentQuestion["choice4"]}");

                            //         return;
                            //     }
                            // }
                        // }
                        // else { 
                            
                                // }
                            // }
                        // }
                        return;
                    }
                    
                    if(isset($result)) {
                        yield $result->advance();
                        $row = $result->getCurrent();
                        $client->currentQuestion = $row; 
                        if($client->currentRoom["type"] == "adv") {
                            $client->send("[question] adv: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}, {$row["level"]}");
                        }
                        elseif($client->currentRoom["type"] == "ass") {
                            $client->send("[question] {$client->currentRoom["type"]}-$max_qns: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}");
                        }
                        else {
                            $client->send("[question] {$client->currentRoom["type"]}: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}");
                        }

                        // var_dump($row);
                        // Send the opponent at the same time even if opponent hasn't answered yet
                        // if($client->currentRoom["type"] == "pvp") {
                        //     foreach ($this->clients as $player) {
                        //         if($player->pvpStatus[0] == "Playing" && !strcasecmp($player->pvpStatus[1], $client->userinfoUsername)) {

                        //             $player->send("[question] {$type}: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}");
                        //             break;
                        //         }
                        //     }
                        //     // if(isset($client->slowpoke)) unset($client->slowpoke);
                        //     // if(isset($player->slowpoke)) unset($player->slowpoke);
                        // }
                    }
                    
                }
                $pool->close();
            });
        }

        // Message handler for slash commands

        echo "Client $client->resourceId said $msg\n";

        // /challenge <player_username> <customroom_id>: create a new challenge record in db
        if(preg_match_all("/^\/challenge ([^ ]+) ?(.*)$/", $msg, $matches)) {
            $recipientUsername = $matches[1][0];
            if(!strcasecmp($client->userinfoUsername, $recipientUsername)) {
                $client->send("[error] 1: You cannot challenge yourself!");
                return;
            }

            if($client->pvpStatus[0] !== "Available" && $client->pvpStatus[2] > time()-60) {
                $client->send("[error] 5: You currently have an ongoing challenge.");
                return;
            }
            $customRoom = "";
            if(isset($matches[2][0])) $customRoom = $matches[2][0];  

            \Amp\Loop::run(function() use ($client, $recipientUsername, $customRoom) {
                require "../../../secrets.php";
                $config = \Amp\Mysql\ConnectionConfig::fromString(
                    "host=127.0.0.1 user=$username password=$password db=$db"
                );
            
                $pool = \Amp\Mysql\pool($config);

                if($customRoom !== "") {
                    $statement = yield $pool->prepare("select count(*) as count from custom_levels where customLevelName = :custom and account_id = :acid");
                    $result = yield $statement->execute(['custom' => $customRoom, 'acid' => $client->userinfoID]);
                    // $s = yield $pool->query("select count(*) as count from custom_levels where custom_game_id = $customRoom and account_id = $client->userinfoID");
                    // while (yield $s->advance()) {
                    //     \var_dump($s->getCurrent());
                    // }
                    // yield $s->advance();
                    // $r = $s->getCurrent();
                    yield $result->advance();
                    $row = $result->getCurrent();
                    if($row["count"] < 1) {
                        $client->send("[error] This custom level does not exist.");
                        echo "$client->userinfoUsername tried to send a non-existent custom level.";
                        return;
                    }
                }
                
                // Loop through players in the socket to see if username matches
                foreach ($this->clients as $player) {
                    if ($player->userinfoWorld == $client->userinfoWorld && !strcasecmp($player->userinfoUsername, $recipientUsername)) {
                        // May need some constraint checks e.g. cannot challenge a player if they already have a challenge ongoing
                        if($player->pvpStatus[0] !== "Available" && $player->pvpStatus[2] > time()-60) {
                            $client->send("[error] 3: Your opponent is currently engaged in a match.");
                            return;
                        }

                        // Save the info in database
                        $statement = yield $pool->prepare("insert into pvp_session (requester_id, opponent_id, status, timestamp, pvp_room_type) values (:sender, :recipient, :status, :time, :custom) ");
                        $result = yield $statement->execute(['sender' => $player->userinfoID, 'recipient' => $client->userinfoID, 'status' => 0, 'time' => time(), 'custom' => $customRoom]);
                        $pvpRoomId = $result->getLastInsertId();

                        // Save the info in pvpStatus
                        $time = time();
                        $client->pvpStatus = ["Sent", $player->userinfoUsername, $time, $customRoom, $pvpRoomId];
                        $player->pvpStatus = ["Received", $client->userinfoUsername, $time, $customRoom, $pvpRoomId];

                        // Send to players
                        $client->send("[challenge sent] $client->userinfoUsername: I challenge you to battle, $player->userinfoUsername!");
                        $player->send("[challenge] $client->userinfoUsername: I challenge you to battle, $player->userinfoUsername! Will you accept or reject?");

                        echo "$client->userinfoUsername challenged user {$recipientUsername}!\n";
                        $pool->close();

                        return;
                    }
                }
                // Player was not found
                $client->send("[error] 2: The player cannot be found.");
                $pool->close();
            });
        }

        // /accept <player_username>: start the pvp match
        if(preg_match_all("/^\/accept (.+)$/", $msg, $matches)) {
            // Do some database checks for existing challenge id, permissions, etc
            $recipientUsername = $matches[1][0];
            if(!strcasecmp($client->userinfoUsername, $recipientUsername)) {
                $client->send("[error] 1: You cannot challenge yourself!");
                return;
            }
            
            // Check if client has existing invitation
            if($client->pvpStatus[0] !== "Received" || strcasecmp($client->pvpStatus[1], $recipientUsername) || $client->pvpStatus[2] < time()-60) {
                $client->send("[error] 4: This challenge request does not exist.");
                return;
            }

            // Loop through players in the socket to see if username matches
            foreach ($this->clients as $player) {
                if ($player->userinfoWorld == $client->userinfoWorld && !strcasecmp($player->userinfoUsername, $recipientUsername)) {
                    if($player->pvpStatus[0] !== "Sent" || strcasecmp($player->pvpStatus[1], $client->userinfoUsername) || $client->pvpStatus[2] < time()-60) {
                        $client->send("[error] 4: This challenge request does not exist.");
                        return;
                    }
                    
                    $customRoom = $client->pvpStatus[3];
                    $pvpRoomId = $client->pvpStatus[4];

                    // Save the info in pvpStatus
                    $time = time();
                    $client->pvpStatus = ["Playing", $player->userinfoUsername, time(), $customRoom, $pvpRoomId];
                    $player->pvpStatus = ["Playing", $client->userinfoUsername, time(), $customRoom, $pvpRoomId];
                    $client->send("[challenge accepted] $player->userinfoUsername: I am your opponent!");
                    $player->send("[challenge accepted] $client->userinfoUsername: I am your opponent!");
                    
                    \Amp\Loop::run(function() use ($client, $player, $customRoom, $pvpRoomId) {
                        require "../../../secrets.php";
                        $config = \Amp\Mysql\ConnectionConfig::fromString(
                            "host=127.0.0.1 user=$username password=$password db=$db"
                        );
                    
                        $pool = \Amp\Mysql\pool($config);

                        // Save info into database
                        $statement = yield $pool->prepare("update pvp_session set status = 1 where pvp_room_id = :rid and status = 0 and requester_id = :sender and opponent_id = :recipient");
                        $result = yield $statement->execute(['rid' => $pvpRoomId, 'sender' => $player->userinfoID, 'recipient' => $client->userinfoID]);

                        // get the first question
                        if($customRoom !== "") {
                            $statement = yield $pool->prepare("select * from custom_levels where customLevelName = :custom and account_id = :acid");
                            $result = yield $statement->execute(['custom' => $customRoom, 'acid' => $player->userinfoID]);
                            // var_dump($customRoom);
                            yield $result->advance();
                            $row = $result->getCurrent();
                            // var_dump($row);
                            // $s = yield $pool->query("select * from custom_levels where custom_game_id = $customRoom and account_id = $player->userinfoID");
                            // while (yield $s->advance()) {
                            //     \var_dump($s->getCurrent());
                            // }
                            // yield $s->advance();
                            // $row = $s->getCurrent();
                            
                            $arr = [];
                            $temp = explode('|', $row["question_type_difficulty"]);
                            for($i = 0; $i < count($temp); $i++) {
                                $arr[] = explode(',', $temp[$i]);
                            }

                            $statement = yield $pool->prepare("select * from questions where question_type like :type and level like :level order by rand() limit 1");
                            $result = yield $statement->execute(['type' => $arr[0][0], 'level' => $arr[0][1]]);
                            array_shift($arr);
                            $client->customQuestionQueue = $arr;
                            // var_dump($client->customQuestionQueue);
                        }
                        else {
                            $sql = "select * from questions where question_type like :world order by rand() limit 1";
                            $statement = yield $pool->prepare($sql);
                            $result = yield $statement->execute(['world' => $client->userinfoWorld]);
                        }
                        yield $result->advance();
                        $row = $result->getCurrent();
                        $client->currentQuestion = $row;
                        $player->currentQuestion = $row;
                        $client->send("[question] pvp: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}");
                        $player->send("[question] pvp: {$row["question"]}, {$row["choice1"]}, {$row["choice2"]}, {$row["choice3"]}, {$row["choice4"]}");

                        $pool->close();
                    });

                    // Assign players to a new room
                    $client->currentRoom = array("room" => $pvpRoomId, "type" => "pvp", "sessionCorrect" => [], "sessionAttempted" => []);
                    $player->currentRoom = array("room" => $pvpRoomId, "type" => "pvp", "sessionCorrect" => [], "sessionAttempted" => []);
                    $client->slowpoke = [];
                    $player->slowpoke = [];

                    // echo "$client->userinfoUsername challenged user {$recipientUsername}!\n";
                    return;
                }
            }

            // Player was not found
            $client->send("[error] 2: The player cannot be found.");
            // echo "Client $client->resourceId accepted challenge ID {$matches[1][0]}!\n";
        }

        // /reject <player_username>: reject the challenge
        if(preg_match_all("/^\/reject (.+)$/", $msg, $matches)) {
            // Do some database checks for existing challenge id, permissions, etc
            $recipientUsername = $matches[1][0];
            if(!strcasecmp($client->userinfoUsername, $recipientUsername)) {
                $client->send("[error] 1: You cannot challenge yourself!");
                return;
            }
            
            // Check if client has existing invitation
            if($client->pvpStatus[0] !== "Received" || strcasecmp($client->pvpStatus[1], $recipientUsername) || $client->pvpStatus[2] < time()-60) {
                $client->send("[error] 4: This challenge request does not exist.");
                return;
            }

            // Loop through players in the socket to see if username matches
            foreach ($this->clients as $player) {
                if ($player->userinfoWorld == $client->userinfoWorld && !strcasecmp($player->userinfoUsername, $recipientUsername)) {
                    if($player->pvpStatus[0] !== "Sent" || strcasecmp($player->pvpStatus[1], $client->userinfoUsername) || $client->pvpStatus[2] < time()-60) {
                        $client->send("[error] 4: This challenge request does not exist.");
                        return;
                    }
                    
                    // Revert the pvpStatus to available
                    $time = time();
                    $client->pvpStatus = ["Available", "", $time];
                    $player->pvpStatus = ["Available", "", $time];
                    $client->send("[challenge rejected] $client->userinfoUsername: I reject your challenge, $player->userinfoUsername!");
                    $player->send("[challenge rejected] $client->userinfoUsername: I reject your challenge, $player->userinfoUsername! sadface :'(");
                    
                    // Save the info in database
                    // ...

                    // echo "$client->userinfoUsername challenged user {$recipientUsername}!\n";
                    return;
                }
            }

            // Player was not found
            $client->send("[error] 2: The player cannot be found.");
            // echo "Client $client->resourceId rejected challenge ID {$matches[1][0]}!\n";
        }

        // /message <player_username> <message>: sends a new message to player's username
        if(preg_match_all("/^\/message (.+) (.+)$/", $msg, $matches)) {
            $recipientUsername = $matches[1][0];
            $message = $matches[2][0];
            echo "Sending message to user $recipientUsername: $message\n";

            foreach ($this->clients as $player) {
                if ($player->userinfoWorld == $client->userinfoWorld && !strcasecmp($player->userinfoUsername, $recipientUsername)) {
                    $player->send("[message] $client->userinfoUsername: $message");
                    $client->send("[to $player->userinfoUsername] $client->userinfoUsername: $message");
                    return;
                }
            }

            // Player doesn't exist or offline
            $client->send("[error] 2: The player cannot be found.");
        }

        // /world <message>: sends a new message to player
        if(preg_match_all("/^\/world (.+)$/", $msg, $matches)) {
            $message = $matches[1][0];
            echo "Client $client->resourceId messaged world: $message\n";

            foreach ($this->clients as $player) {
                if ($player->userinfoWorld == $client->userinfoWorld) {
                    // Don't send the message back to the person who sent it
                    // if ($client->resourceId == $player->resourceId) {
                    //     continue;
                    // }
                    $player->send("[world] $client->userinfoUsername: $message");
                }
            }
        }

        // /status <player_username>: reveals the pvpStatus of a player
        if(preg_match_all("/^\/status (.+)$/", $msg, $matches)) {
            $recipientUsername = $matches[1][0];
            echo "Client $client->resourceId checked the pvpStatus of $recipientUsername\n";

            foreach ($this->clients as $player) {
                if ($player->userinfoWorld == $client->userinfoWorld && !strcasecmp($player->userinfoUsername, $recipientUsername)) {
                    var_dump($player->pvpStatus);
                    $time = date('y M d h:i:s', $player->pvpStatus[2]);
                    $client->send("[status] $player->userinfoUsername: {$player->pvpStatus[0]} {$player->pvpStatus[1]} at $time)}");
                    return;
                }
            }

            // Player doesn't exist or offline
            $client->send("[error] 2: The player cannot be found.");
        }
    }

    public function onClose(ConnectionInterface $client) {
        echo "Client $client->resourceId left\n";

        // Disconnect the resource
        $this->clients->detach($client);

        // Change the token in db to disconnected
        \Amp\Loop::run(function() use ($client) {
            // Get the auth token
            // parse_str($client->httpRequest->getUri()->getQuery(), $queryParameters);
    
            require "../../../secrets.php";
            $config = \Amp\Mysql\ConnectionConfig::fromString(
                "host=127.0.0.1 user=$username password=$password db=$db"
            );
            
            /** @var \Amp\Mysql\Pool $pool */
            $pool = \Amp\Mysql\pool($config);
            
            /** @var \Amp\Mysql\Statement $statement */
            $statement = yield $pool->prepare("update socket_connections set status='Disconnected' where resource_id=:id");
            
            /** @var \Amp\Mysql\ResultSet $result */
            if(yield $statement->execute(['id' => $client->resourceId])) {
                echo "Disconnected client ({$client->resourceId}).\n";

                // Update all other players
                foreach ($this->clients as $player) {
                    // Remove the pvp request from any players with a pending request from disconnected player
                    if(!strcasecmp($player->pvpStatus[1], $client->userinfoUsername)) {
                        // Send message to the player
                        if($player->pvpStatus[0] == "Sent") {
                            $player->send("[pvp] sent: Your opponent has disconnected from the game.");
                        }
                        elseif($player->pvpStatus[0] == "Received") {
                            $player->send("[pvp] received: Your opponent has disconnected from the game.");
                        }
                        elseif($player->pvpStatus[0] == "Playing") {
                            $player->send("[pvp] forfeit: Your opponent has disconnected from the game.");
                        }

                        $player->pvpStatus = ["Available", "", time()];
                    }

                    if($player->userinfoWorld == $client->userinfoWorld) {
                        $player->send("[disconnect] $client->userinfoUsername: $client->userinfoCharacter");
                    }
                }
            }
            else {
                echo "Client ({$client->resourceId}) encountered error.\n";
            }
            $pool->close();
        });
    }

    public function onError(ConnectionInterface $client, \Exception $e) {
        echo "Client $client->resourceId received error $e\n";
    }
}
