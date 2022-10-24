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
import { spawnPlayer } from "../frontend/src/idioms.js";
var token;
var socket;
var world;
generateSocketAuth().then(result => {
    token = result;
    if(window.location.pathname.includes("idioms")) world = "Idioms";
    else if(window.location.pathname.includes("hanyu")) world = "Hanyu";
    else if (window.location.pathname.includes("blanks")) world = "Blanks";
    // console.log(token);
    
    // Create a new WebSocket.
    socket = window.location.hostname == "localhost" ? new WebSocket(`ws://${window.location.hostname}:8888?token=${token}&world=${world}`) : new WebSocket(`wss://${window.location.hostname}/wss2/:8888?token=${token}&world=${world}`);

    function transmitMessage() {
        socket.send( message.value );
    }

    socket.onmessage = function(e) {
        console.log(e.data);

        // Spawn the players that are already logged in
        

        // message will come in the format:
        // [type] senderusername: message
        var matches = e.data.match(/^\[(.+)\] (.+): (.+)/);
        var type = matches[1];
        var sender = matches[2];
        var message = matches[3];
        
        if(type == "error") {
            // error codes (so far) are: 
            // 1: you cannot challenge yourself
            // 2: the recipient was not found
            // 3: the recipient is currently engaged in pvp
            // switch(sender) {
            //     case "1":
            //         console.log("You cannot challenge yourself.");
            //         break;
            //     case "2":
            //         console.log("The player was not found.");
            //         break;
            //     default: 
            //         console.log("An unknown error occurred.");
            //         break;
            // }
        }
        else if(type == "connect") {
            // [connect] username: characterType
            spawnPlayer(matches[2], matches[3]);
        }
        else if(type == "disconnect") {
            // spawnPlayer(matches[1], matches[2], matches[3]);
        }
        else if(/^to (.+)$/.test(type)) {
            // private message sent from the client
            // do something like adding the chat message to chat div
        }
        else if(type == "message") {
            // private message sent to the client
            // do something like adding the chat message to chat div
        }
        else if(type == "world") {
            // message is a world message
            
        }
        else if(type == "challenge") {

        }
        else if(type == "challenge sent") {
            
        }
        else if(type == "challenge accepted") {

        }
        else if(type == "challenge rejected") {

        }
    }
});