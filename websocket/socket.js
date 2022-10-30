function generateSocketAuth() {
    return new Promise(function(resolve, reject) {
        var xmlhttp = new XMLHttpRequest();
        xmlhttp.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                if(this.responseText == "-1") reject();
                resolve(this.responseText);
            }
        };
        xmlhttp.open("GET", "../scripts/generateSocketAuth");
        xmlhttp.send();
    })
}
// Holds the players to be spawned until phaser has loaded.
// var spawnPlayerHolder = players => document.addEventListener("DOMLoaded", function() {
//     console.log('DOM has loaded');
//     for(let player in players) {
//         let matches = players[player].match(/^(\d+)-(\d+)-(\d+)$/);
//         let characterType = parseInt(matches[1]);
//         let posX = parseInt(matches[2]);
//         let posY = parseInt(matches[3]);
//         spawnPlayerHolder(player, characterType, posX, posY);
//     }
// });
function loadPlayers(players) {
    // console.log("loading players");
    // console.log(players);
    for(let player in players) {
        let matches = players[player].match(/^(\d)-(.+)-(.+)$/);
        let characterType = matches[1];
        let posX = parseInt(matches[2]);
        let posY = parseInt(matches[3]);
        try {
            spawnPlayer(player, characterType, posX, posY);
        }
        catch (e) {
            console.log("respawning...");
            // Wait a bit, then try to respawn player
            window.setTimeout(function() {
                spawnPlayer(player, characterType, posX, posY);
            }, 5000);
        }
    }
}
import { spawnPlayer, movePlayer, destroyPlayer } from "../frontend/src/exports.js";
import { addMessageElement, recipients } from "../frontend/src/exports.js";
import { getCharacterFromUsername } from "../frontend/utility.js";
// export var socket;
var token;
var world;
var updateLoop;
var moves = {};
// var slowpoke = [];
// // Set an interval to check the movement queue
// var moveQueue = window.setInterval(function() {
//     // If movement queue has any items
//     for(let [key, value] of Object.entries(moves)) {
//         movePlayer(key, value[0], value[1], value[2]);
//     }
// }, 1000);
generateSocketAuth().then(result => {
    token = result;
    if(window.location.pathname.includes("idioms")) world = "idiom";
    else if(window.location.pathname.includes("hanyu")) world = "pinyin";
    else if (window.location.pathname.includes("blanks")) world = "fill";
    // console.log(token);
    
    // Create a new WebSocket.
    socket = window.location.hostname == "localhost" ? new WebSocket(`ws://${window.location.hostname}:8888?token=${token}&world=${world}`) : new WebSocket(`wss://${window.location.hostname}/wss2/:8888?token=${token}&world=${world}`);

    // Send an update request to the socket every few seconds to account for packet loss
    clearInterval(updateLoop);
    updateLoop = window.setInterval(function() {
        socket.send("/update");
        updateAssignmentNotification();

        // add the players to chat window
        recipients.forEach(player => {
            if(!document.body.contains(document.getElementById("message_"+player))) {
                const newRecipient = document.createElement('option');
                newRecipient.innerHTML = `Say to ${player}`;
                newRecipient.id = "message_"+player;
                newRecipient.value = player;
                document.getElementById("chat-type").append(newRecipient);
            }
        });
    }, 5000); 

    function transmitMessage() {
        socket.send( message.value );
    }

    socket.onmessage = async function(e) {
        console.log(e.data);

        // Spawn the players that are already logged in
        try {
            let players = JSON.parse(e.data);

            // Do the initial load to fetch already logged in players
            if(players.hasOwnProperty("firstload") && players["firstload"] == 0) {
                console.log("initial loading...");

                window.setTimeout(function() {
                    loadPlayers(players);
                }, 3000); 
                delete players["firstload"];

                // add the players to chat window
                for (var player of Object.keys(players)) {
                    console.log("player "+player);
                    if(recipients.indexOf(player) === -1) {
                        recipients.push(player);
                    }
                }
            }
            // console.log(players);

            try {
                let playersToUpdate = {};

                // Check if the players' latest positions are the same as their last recorded position in moves
                for(let [key, value] of Object.entries(players)) {

                    // Save the player's current location if their name did not exist in moves
                    if(!moves.hasOwnProperty(key)) {
                        moves[key] = [value.split("-")];
                        continue;
                    }

                    // Check if the player's last move location is equal to their current location
                    let player = moves[key];
                    let concat = `${player[player.length-1][0]}-${player[player.length-1][1]}-${player[player.length-1][2]}`;
                    // console.log(key, value, concat);

                    // Save the player's last updated location
                    moves[key].push(concat.split("-"));

                    if(value == concat) {
                        // console.log("skipping "+key);
                        continue;
                    }
                    playersToUpdate[key] = players[key];
                }

                if(Object.entries(playersToUpdate).length > 0) 
                    loadPlayers(playersToUpdate);
            } catch (e2) {
                // console.log(e2);
            }
        } catch (e) {
            // console.log("spawnPlayer failed");
            // console.log(e);
        }

        // question handler
        var pattern = /^\[question\] (.+): (.+)/;
        if(pattern.test(e.data)) {
            // [question text, choice1, choice2, choice3, choice4, level lower|upper]
            let mode = e.data.match(pattern)[1];
            if(mode == "adv") {
                console.log("question "+adventureModeCurrentQn);
                if(adventureModeCurrentQn == 1 && document.getElementById('adventureModeNextQuestionBtn').classList.contains("invisible")) {
                    let question = e.data.match(pattern)[2].split(", ");
                    document.getElementById('adventureModeQuestionNo').innerHTML = "Question 1";
                    document.getElementById('adventureModeQuestion').innerHTML = question[0];
                    document.getElementById('adventureModeQuestionNo').innerHTML += ` [${question[5]}]`;
                    for(let i=1; i<=4; i++) {
                        document.getElementById('adventureModeOption'+i).innerHTML = question[i];
                    }
                    return;
                }
                questionQueue = e.data.match(pattern)[2].split(", ");
            }
            else if(/ass-(\d+)/.test(mode)) {
                let max_qns = parseInt(mode.match(/ass-(\d+)/)[1]);
                assignmentModeProgressBar.value = max_qns;
                if(assignmentModeCurrentQn == 1 && document.getElementById('assignmentModeNextQuestion').classList.contains("invisible")) {
                    let question = e.data.match(pattern)[2].split(", ");
                    assignmentModeQuestion.innerHTML = question[0];
                    for(let i=1; i<=4; i++) {
                        document.getElementById('assignmentModeOption'+i).innerHTML = question[i];
                    }
                    return;
                }
                questionQueue = e.data.match(pattern)[2].split(", ");
            }
            else if(mode == "pvp") {
                console.log("pvpmodecurrentqn", pvpModeCurrentQn);
                if(pvpModeCurrentQn == 1 && document.getElementById('pvpModeOption1').disabled !== true) {
                    let question = e.data.match(pattern)[2].split(", ");
                    document.getElementById('pvpModeQuestion').innerHTML = question[0];
                    for(let i=1; i<=4; i++) {
                        document.getElementById('pvpModeOption'+i).innerHTML = question[i];
                    }
                    return;
                }
                questionQueue = e.data.match(pattern)[2].split(", ");
            }
            return;
        }

        // if(/^\[time\] (\d+)/.test(e.data)) {
        //     // receive the timestamp of opponent's answer
        //     // let time = parseInt(e.data.match(/^\[time\] (\d+)/)[1]);

        //     // the client's answer buttons are not disabled i.e. opponent answered first
        //     if(document.getElementById('pvpModeOption1').disabled == false) {
        //         slowpoke.push(true);
        //         console.log("opponent answered first, awaiting your answer");
        //     }
        //     else {
        //         // opponent answered 2nd, display the next qn
        //         slowpoke.push(false);
        //         console.log("you answered first and your opponent just finished answering");
        //         await displayNextPvpQn();
        //     }
        // }

        // if(/^\[slowpoke\] you are(.+)/.test(e.data)) {
        //     // just a very workaround way bc idk what else to do :)
        //     slowpoke.push(true);
        //     console.log("opponent answered first, awaiting your answer");
        //     // }
        //     // else {
        //     //     // opponent answered 2nd, display the next qn
        //     //     slowpoke.push(false);
        //     //     console.log("you answered first and your opponent just finished answering");
        //     //     await displayNextPvpQn();
        //     // }
        // }
        // if(/^\[slowpoke\] your opponent(.+)/.test(e.data)) {
        //     slowpoke.push(false);
        //     console.log("you answered first, awaiting opponent's answer");
        //     // await displayNextPvpQn();
        // }

        if(/^\[result\] (\d) (\d+) (\d) (\d+)/.test(e.data)) {
            // [result] your_correct_qns your_score opponent_correct_qns opponent_score
            var res = e.data.match(/^\[result\] (\d) (\d+) (\d) (\d+)/);
            if(parseInt(res[2]) > parseInt(res[4])) {
                // client won
                document.getElementById('pvpModeComplete').innerHTML = `
                    <div class="alert alert-info text-center" role="alert">
                        Congratulations, you won!<br/><br/>

                        Your Score: ${res[2]}<br/>
                        Correct Questions: ${res[1]}/5<br/><br/>

                        Opponent's Score: ${res[4]}<br/>
                        Correct Questions: ${res[3]}/5
                    </div>
                `;
            }
            else {
                // client lost
                document.getElementById('pvpModeComplete').innerHTML = `
                    <div class="alert alert-info text-center" role="alert">
                        You lost, maybe next time...<br/><br/>

                        Your Score: ${res[2]}<br/>
                        Correct Questions: ${res[1]}/5<br/><br/>

                        Opponent's Score: ${res[4]}<br/>
                        Correct Questions: ${res[3]}/5
                    </div>
                `;
            }
            pvpModeProgress = 0; 	// progress in terms of percentage, starts at 0%
            pvpModeQnCorrect = 0; 	// num of questions correct, starts at 0
            pvpModeQnAttempted = 0; 	// num of questions attempted, starts at 0
            pvpModeCurrentQn = 1; 	// current question number, starts at 1
        }

        if(/^\[pvp\] sent: Your opponent(.+)/.test(e.data)) {
            // forfeit message
            sentModal.hide();
            var errorModal = new bootstrap.Modal(document.getElementById('rejectInvitation-modal'), {
                keyboard: false
            })
            document.getElementById('pvpModeOption1').disabled = false;
            document.getElementById('pvpModeOption2').disabled = false;
            document.getElementById('pvpModeOption3').disabled = false;
            document.getElementById('pvpModeOption4').disabled = false;
            document.getElementById('reject-modal-title').innerHTML = "Your opponent has disconnected.";
            errorModal.show();
        }

        if(/^\[pvp\] received: Your opponent(.+)/.test(e.data)) {
            // forfeit message
            challengeModal.hide();
        }

        if(/^\[pvp\] forfeit: Your opponent(.+)/.test(e.data)) {
            // forfeit message
            pvpModal.hide();
            var errorModal = new bootstrap.Modal(document.getElementById('rejectInvitation-modal'), {
                keyboard: false
            })
            document.getElementById('pvpModeOption1').disabled = false;
            document.getElementById('pvpModeOption2').disabled = false;
            document.getElementById('pvpModeOption3').disabled = false;
            document.getElementById('pvpModeOption4').disabled = false;
            document.getElementById('reject-modal-title').innerHTML = "Your opponent has forfeited.";
            errorModal.show();
        }

        if(/^\[pvp\] forfeit: You have(.+)/.test(e.data)) {
            // forfeit message
            // pvpModal.hide();
            var errorModal = new bootstrap.Modal(document.getElementById('rejectInvitation-modal'), {
                keyboard: false
            })
            document.getElementById('pvpModeOption1').disabled = false;
            document.getElementById('pvpModeOption2').disabled = false;
            document.getElementById('pvpModeOption3').disabled = false;
            document.getElementById('pvpModeOption4').disabled = false;
            document.getElementById('reject-modal-title').innerHTML = "You have forfeited the match.";
            errorModal.show();
        }

        if(/^\[pvp score\] (\d) (\d+) (\d) (\d+)/.test(e.data)) {
            let scores = e.data.match(/^\[pvp score\] (\d) (\d+) (\d) (\d+)/);
            document.getElementById("pvpModeUserScore").innerHTML = "Your score: "+scores[2]+"<br/>Questions Correct: "+scores[1]+"/"+pvpModeCurrentQn; 
            document.getElementById("pvpModeOpponentScore").innerHTML = "Opponent's score: "+scores[4]+"<br/>Questions Correct: "+scores[3]+"/"+pvpModeCurrentQn;
        }

        if(/^\[answer\] (.+)/.test(e.data)) {
            var answer = e.data.match(/^\[answer\] (.+)/)[1].split("!!!I LOVE CHINESEEE!!!");
            // [correct 1|0, correct answer, explanation, mode]
            // console.log("0TESTING BEFORE IF STATEMENT " + answer[0])
            // console.log("1TESTING BEFORE IF STATEMENT " + answer[1])
            // console.log("2TESTING BEFORE IF STATEMENT " + answer[2])
            // console.log("3TESTING BEFORE IF STATEMENT " + answer[3])

            if(answer[3] == "adv") {
                console.log("Updating adventure modal score and explanation") // for testing purpose

                adventureModeQnAttempted += 1
                if(answer[0] == "1") adventureModeQnCorrect += 1
                document.getElementById('adventureModeScore').innerHTML = adventureModeQnCorrect + "/" + adventureModeQnAttempted;
                document.getElementById('adventureModeExplanation').innerHTML = `
                    <div class="alert alert-${answer[0] == "1" ? "success" : "danger"}" role="alert">
                    <h4 class="alert-heading">${answer[0] == "1" ? "Correct!" : "Incorrect!"}</h4>
                    <p>The answer is ${answer[1]}</p>
                    <hr>
                    <p class="mb-0">${answer[2]}</p>
                    </div>
                `;
            }
            else if(answer[3] == "ass") {
                assignmentModeQnAttempted += 1
                if(answer[0] == "1") assignmentModeQnCorrect += 1
                assignmentModeScore.innerHTML = assignmentModeQnCorrect + "/" + assignmentModeQnAttempted;
                assignmentModeExplanation.innerHTML = `
                    <div class="alert alert-${answer[0] == "1" ? "success" : "danger"}" role="alert">
                    <h4 class="alert-heading">${answer[0] == "1" ? "Correct!" : "Incorrect!"}</h4>
                    <p>The answer is ${answer[1]}</p>
                    <hr>
                    <p class="mb-0">${answer[2]}</p>
                    </div>
                `;
            }
            else if(answer[3] == "pvp") {
                if(answer[4] == "first") {
                    // the user was first

                }
                else {
                    // the user was second
                }

                await displayNextPvpQn();
                // console.log(slowpoke);
                // if(slowpoke.length <= pvpModeCurrentQn) {
                    // display the next qn
                    // console.log("you answered second and your opponent was waiting for you");
                    // await displayNextPvpQn();
                // }
            }
        }

        // message will come in the format:
        // [type] senderusername: message
        if(/^\[(.+)\] (.+): (.+)/.test(e.data)) {
            var matches = e.data.match(/^\[(.+)\] (.+): (.+)/);
            var type = matches[1];
            var sender = matches[2];
            var message = matches[3];
            
            if(type == "error") {
                var errorModal = new bootstrap.Modal(document.getElementById('rejectInvitation-modal'), {
                    keyboard: false
                })
                document.getElementById('reject-modal-title').innerHTML = matches[3];
                errorModal.show();
            }
            else if(type == "connect") {
                // [connect] username: characterType
                spawnPlayer(sender, message, 200, 400);
                // Add sender to PM recipient list
                if(recipients.indexOf(sender) === -1) {
                    recipients.push(sender);
                    const newRecipient = document.createElement('option');
                    newRecipient.innerHTML = `Say to ${sender}`;
                    newRecipient.id = "message_"+sender;
                    newRecipient.value = sender;
                    document.getElementById("chat-type").append(newRecipient);
                }
            }
            else if(type == "disconnect") {
                destroyPlayer(sender);
                if(moves.hasOwnProperty(sender)) delete moves[sender];
                // Remove sender from PM recipient list
                if(recipients.indexOf(sender) !== -1) {
                    for(var i = 0; i<recipients.length; i++){ 
                        if (recipients[i] == sender) { 
                            recipients.splice(i, 1); 
                        }
                    }
                    document.getElementById("message_"+sender).remove();
                }
            }
            else if(type == "move") {
                // [move] username: c1 x200 y400 t0
                // Receive a move event meaning at least one player's position has changed. Create a queue of "moves" for each user. 
                let coords = message.match(/^c(\d) x(.+) y(.+) t(.+)$/);
                if(!moves.hasOwnProperty(sender)) {
                    moves[sender] = [];
                }
                else {
                    // To make smoother animations, find the number of Phaser updates() that have passed since last location data
                    let dt = coords[4]-moves[sender][moves[sender].length-1][3];
                    // Only send location data newer than x updates from the previous data
                    if(dt > 5) {
                        // To prevent sending every single movement to Phaser, wait until there are >2 movements in the same player's queue
                        // if(moves[sender].length > 2) {
                            movePlayer(sender, coords[1], coords[2], coords[3], dt);
                            moves[sender] = [[coords[1], coords[2], coords[3], coords[4]]];
                        // }
                    }
                }
                // Add the new location data to moves
                moves[sender].push([coords[1], coords[2], coords[3], coords[4]]);
                console.log(moves);
            }
            else if(/^to (.+)$/.test(type)) {
                // private message sent from the client
                let recipient = type.match(/^to (.+)$/)[1];
                // do something like adding the chat message to chat div
                addMessageElement("To "+recipient, sender, message);
            }
            else if(type == "message") {
                // private message sent to the client
                // do something like adding the chat message to chat div
                addMessageElement("Message", sender, message);
            }
            else if(type == "world") {
                // message is a world message
                addMessageElement("World", sender, message);
            }
            else if(type == "challenge") {
                // someone sent to me the challenge
                // "Player (sender variable) sent you a challenge"
                  document.getElementById('invitationMessageSender').innerHTML = sender + " sent you an pvp invitation";
                  challengeModal.show();
                  
                  document.getElementById('acceptPvpInvitation').value = sender;
                  document.getElementById('rejectPvpInvitation').value = sender;
            }
            else if(type == "challenge sent") {
                // you have sent to the player 
                document.getElementById('invitationMessageReceiver').innerHTML = "The invitation has been sent!";
                sentModal.show();
            }
            else if(type == "challenge accepted") {
                // just head to the pvp page
                sentModal.hide();
                pvpModal.show();

                // get player character's id
                let playerCharacter = await getLoggedInCharacter();
                let opponentCharacter = await getCharacterFromUsername(sender);
                console.log(playerCharacter, opponentCharacter);
                let playerSprite = "";
                let opponentSprite = "";
                switch(playerCharacter) {
                    case "2":
                        playerSprite = "images/huntress.png";
                        break;
                    case "3":
                        playerSprite = "images/heroKnight.png";
                        break;
                    case "4": 
                        playerSprite = "images/wizard.png";
                        break;
                    default:
                        playerSprite = "images/martialHero.png";
                        break;
                }
                switch(opponentCharacter) {
                    case "2":
                        opponentSprite = "images/huntress.png";
                        break;
                    case "3":
                        opponentSprite = "images/heroKnight.png";
                        break;
                    case "4": 
                        opponentSprite = "images/wizard.png";
                        break;
                    default:
                        opponentSprite = "images/martialHero.png";
                        break;
                }
                console.log(playerSprite);
                console.log(opponentSprite);
                // change sprite
                // change score
                // add pvp rank multiplier
                // individual qn overview for teachers?
                // teacher view assignment result
                // handle same result for pvp
                document.getElementById("characterAvatarUserPVP").src = playerSprite;
                document.getElementById("characterAvatarOpponentPVP").src = opponentSprite;
            }
            else if(type == "challenge rejected") {
                if(!message.includes("sadface")) {
                    document.getElementById("reject-modal-title").innerHTML = "You rejected the request"; 
                }
                else {
                    document.getElementById("reject-modal-title").innerHTML = sender+" rejected your request"; 
                    sentModal.hide();
                }
                
                rejectedModal.show();
            }
        }
    }
});