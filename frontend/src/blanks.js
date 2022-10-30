//import { BlanksWorld } from "./scenes/BlanksWorld.js";
import {getLoggedInUsername} from "../utility.js";
import {getLoggedInCharacter} from "../utility.js";

var userName = await getLoggedInUsername();
var characterID = await getLoggedInCharacter();

let config = {
    width: 1200,
    height: 600,
    parent: 'blanksWorld',
    physics: {
        default: 'arcade',
        arcade: {
            debug: false
        }
    },
    scale: {
        // Fit to window
        mode: Phaser.Scale.FIT,
        // Center vertically and horizontally
        autoCenter: Phaser.Scale.CENTER_BOTH
    },
    dom: {
        createContainer: true
    },
    scene: {preload: preload,
        create: create,
        update: update
    }
}

let game = new Phaser.Game(config);

function preload() {
    // Create loading progress bar
    let progressBox = this.add.graphics();
    let progressBar = this.add.graphics();
    progressBox.fillStyle(0x222222, 0.8);
    progressBox.fillRect(400, 275, 400, 50);
    let width = this.cameras.main.width;
    let height = this.cameras.main.height;
    let loadingText = this.make.text({
        x: width / 2,
        y: height / 2 - 50,
        text: 'Loading...',
        style: {
            font: '20px monospace',
            fill: '#7DE5ED'
        }
    });
    loadingText.setOrigin(0.5, 0.5);
    let percentText = this.make.text({
        x: width / 2,
        y: height / 2 - 5,
        text: '0%',
        style: {
            font: '18px monospace',
            fill: '#3C4048'
        }
    });
    percentText.setOrigin(0.5, 0.5);
    let assetText = this.make.text({
        x: width / 2,
        y: height / 2 + 50,
        text: '',
        style: {
            font: '18px monospace',
            fill: '#7DE5ED'
        }
    });
    assetText.setOrigin(0.5, 0.5);

    // Fills the loading progress bar as files are loaded
    this.load.on('progress', value => {
        progressBar.clear();
        progressBar.fillStyle(0x7DE5ED, 1);
        progressBar.fillRect(410, 285, 380 * value, 30);
        percentText.setText(parseInt(value * 100) + '%');
    })

    // Shows which asset is being loaded
    this.load.on('fileprogress', file => {
        assetText.setText('Loading asset: ' + file.key);
    })

    // Destroy progress bar stuff when done loading
    this.load.on('complete', () => {
        progressBar.destroy();
        progressBox.destroy();
        loadingText.destroy();
        percentText.destroy();
        assetText.destroy();
    })

    // Load world assets
    this.load.image("greatWall", "assets/blanksWorld/great-wall.jpg");
    this.load.image("chest", "assets/blanksWorld/chest.png");
    this.load.image("barrel", "assets/blanksWorld/barrel.png");
    this.load.spritesheet("merchant", "assets/blanksWorld/merchant.png", {frameWidth: 32, frameHeight: 32});
    this.load.audio("blanks_music", "assets/blanksWorld/prairie.mp3");
    this.load.audio("hello", "assets/blanksWorld/hello.wav");

    // Load common assets
    this.load.image("scroll", "assets/common/10b-parchmentborder.gif");
    this.load.image("speech", "assets/common/speechBubble.png");
    this.load.spritesheet("sign", "assets/common/wooden-sign.png", {frameWidth: 65, frameHeight: 64});

    // Load characters
    this.load.atlas("huntress", "assets/characters/huntress_spritesheet.png", "assets/characters/huntress.json");
    this.load.atlas("martialIdle", "assets/characters/martial-idle.png", "assets/characters/martial-idle.json");
    this.load.atlas("martialRun", "assets/characters/martial-run.png", "assets/characters/martial-run.json");
    this.load.atlas("wizard", "assets/characters/wizard_spritesheet.png", "assets/characters/wizard.json");
    this.load.atlas("heroKnight", "assets/characters/heroKnight_spritesheet.png", "assets/characters/heroKnight.json");

    // Load HTML
    this.load.html("chat", "src/chat.html");
}

function create() {
    const width = 800;
    const height = 600;
    this.add.image(0, 0, "greatWall").setOrigin(0).setDisplaySize(width, height);
    
    // Add chat box onto game canvas
    this.chatWindow = this.add.dom(1000, 300).createFromCache("chat").setOrigin(0.5);
        
    let chatSetting = "World";  // default chat setting is World

    const chatInput = document.getElementById("inputMessage");
    const chatList = document.getElementById("messages");
    const chatTypeSelect = document.getElementById("chat-type");

    chatTypeSelect.addEventListener('change', () => {
        chatSetting = chatTypeSelect.value;
    });
    
    window.addEventListener('keydown', event => {
        if (event.key === 'y' && document.activeElement !== chatInput) {
            event.preventDefault();
            chatInput.focus();
        }
    });

    // Disable player movement when typing in chat
    chatInput.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
    });
    chatInput.addEventListener('focusout', () => this.input.keyboard.enabled = true);

    // Press enter in chat to trigger event
    chatInput.addEventListener('keydown', event => {
        if (event.key === "Enter") {
            sendMessage();
            chatInput.blur();
        } else if (event.key === 'w' || event.key === 'a' || event.key === 's' || event.key === 'd') {
            chatInput.value += event.key;
        };
    });

    function sendMessage() {
        let message = chatInput.value;
        if (message) {
            chatInput.value = '';

            // Check for slash commands
            if(/^\/(.+)/.test(message)) {
                socket.send(message);
                return;
            }

            if(chatSetting == "World") {
                socket.send(`/world ${message}`);
            }
            else {
                socket.send(`/message ${chatSetting} ${message}`);
            }
            // addMessageElement(message);
        }
    }

    // handling keyboard inputs when modals open/close
    const invitationMessageModal = document.getElementById('invitationMessage-modal');
    invitationMessageModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    invitationMessageModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const sentInvitationModal = document.getElementById('sentInvitation-modal');
    sentInvitationModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    sentInvitationModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const rejectInvitationModal = document.getElementById('rejectInvitation-modal');
    rejectInvitationModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    rejectInvitationModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const pvpModeModal = document.getElementById('pvpMode-modal');
    pvpModeModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    pvpModeModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const pvpModeOption1 = document.getElementById('pvpModeOption1');
    pvpModeOption1.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });

    const pvpModeOption2 = document.getElementById('pvpModeOption2');
    pvpModeOption2.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });

    const pvpModeOption3 = document.getElementById('pvpModeOption3');
    pvpModeOption3.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    
    const pvpModeOption4 = document.getElementById('pvpModeOption4');
    pvpModeOption4.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });

    const viewProfileModal = document.getElementById('viewProfile-modal');
    viewProfileModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    viewProfileModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });




    const customLevelName = document.getElementById('customLevelName');
    customLevelName.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    // enable w/a/s/d for create custom level
    customLevelName.addEventListener('keydown', event => {
        if (event.key === 'w' || event.key === 'a' || event.key === 's' || event.key === 'd') { 
            customLevelName.value += event.key;
        };
    });

    const createCustomLevelModal = document.getElementById('createCustomLevel-modal');
    createCustomLevelModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    createCustomLevelModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    // need to add all custom level modal inputs, question type and difficulty
    const createCustomLevelQuestions = document.querySelectorAll("[name='questionType']");
    for(let i = 0; i<createCustomLevelQuestions.length; i++){
        createCustomLevelQuestions[i].addEventListener('focus', () => {
            this.input.keyboard.enabled = false;
            this.input.mouse.enabled = false;
        });
        createCustomLevelQuestions[i].addEventListener('focusout', () => {
            this.input.keyboard.enabled = true;
            this.input.mouse.enabled = true;
        });
    }

    const createCustomLevelDifficulty = document.querySelectorAll("[name='difficulty']");
    for(let i = 0; i<createCustomLevelDifficulty.length; i++){
        createCustomLevelDifficulty[i].addEventListener('focus', () => {
            this.input.keyboard.enabled = false;
            this.input.mouse.enabled = false;
        });
        createCustomLevelDifficulty[i].addEventListener('focusout', () => {
            this.input.keyboard.enabled = true;
            this.input.mouse.enabled = true;
        });
    }


    const viewCustomLevelModal = document.getElementById('viewCustomLevel-modal');
    viewCustomLevelModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    viewCustomLevelModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const viewAssignmentModal = document.getElementById('viewAssignment-modal');
    viewAssignmentModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    viewAssignmentModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const assignmentModal = document.getElementById('assignmentMode-modal');
    assignmentModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    assignmentModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const startAdventureModal = document.getElementById('startAdventureMode-modal');
    startAdventureModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    startAdventureModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const adventureModal = document.getElementById('adventureMode-modal');
    adventureModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    adventureModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const leaderboardModal = document.getElementById('leaderboard-modal');
    leaderboardModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    leaderboardModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    const logOutModal = document.getElementById('logout-modal');
    logOutModal.addEventListener('focus', () => {
        this.input.keyboard.enabled = false;
        this.input.mouse.enabled = false;
    });
    logOutModal.addEventListener('focusout', () => {
        this.input.keyboard.enabled = true;
        this.input.mouse.enabled = true;
    });

    // Create animations for characters
    this.anims.create({
        key: "huntressIdle",
        frameRate: 8,
        frames: this.anims.generateFrameNames("huntress", {
            prefix: "huntress0",
            start: 1,
            end: 10,
            zeroPad: 2
        }),
        repeat: -1
    });
    this.anims.create({
        key: "huntressRunning",
        frameRate: 8,
        frames: this.anims.generateFrameNames("huntress", {
            prefix: "huntress0",
            start: 11,
            end: 18
        }),
        repeat: -1
    });
    this.anims.create({
        key: "wizardIdle",
        frameRate: 8,
        frames: this.anims.generateFrameNames("wizard", {
            prefix: "wizard00",
            start: 1,
            end: 6
        }),
        repeat: -1
    });
    this.anims.create({
        key: "wizardRunning",
        frameRate: 8,
        frames: this.anims.generateFrameNames("wizard", {
            prefix: "wizard0",
            start: 7,
            end: 14,
            zeroPad: 2
        }),
        repeat: -1
    });
    this.anims.create({
        key: "martialIdle",
        frameRate: 8,
        frames: this.anims.generateFrameNames("martialIdle", {
            prefix: "martial00",
            start: 1,
            end: 4,
        }),
        repeat: -1
    });
    this.anims.create({
        key: "martialRunning",
        frameRate: 8,
        frames: this.anims.generateFrameNames("martialRun", {
            prefix: "martial",
            start: 5,
            end: 12,
            zeroPad: 3
        }),
        repeat: -1
    });
    this.anims.create({
        key: "heroKnightIdle",
        frameRate: 8,
        frames: this.anims.generateFrameNames("heroKnight", {
            prefix: "heroKnight0",
            start: 1,
            end: 11,
            zeroPad: 2
        }),
        repeat: -1
    });
    this.anims.create({
        key: "heroKnightRunning",
        frameRate: 8,
        frames: this.anims.generateFrameNames("heroKnight", {
            prefix: "heroKnight0",
            start: 12,
            end: 19,
        }),
        repeat: -1
    });

    // Create animations for NPC
    this.anims.create({
        key: "npcIdle",
        frameRate: 8,
        frames: this.anims.generateFrameNumbers("merchant", {
            start: 0,
            end: 4
        }),
        repeat: -1
    });

    // Add keyboard support
    this.cursors = this.input.keyboard.addKeys({
        'up': Phaser.Input.Keyboard.KeyCodes.W,
        'down': Phaser.Input.Keyboard.KeyCodes.S,
        'left': Phaser.Input.Keyboard.KeyCodes.A,
        'right': Phaser.Input.Keyboard.KeyCodes.D
    });

    // Increase collision detection
    this.physics.world.setFPS(120);

    // Limit world boundaries so characters cannot run too high up
    this.physics.world.setBounds(0, 160, width, height - 210);

    // Add wooden sign that has the name of the world
    this.sign = this.physics.add.sprite(width * 0.75, height * 0.33, "sign").setScale(2);
    this.sign.body.immovable = true;
    this.sign.body.setSize(this.sign.width, this.sign.height * 0.6, false);
    this.add.text(this.sign.x, this.sign.y * 0.9, "Current world: Fill in the blanks", {
        fill: "white",
        align: "center", 
        fontSize: "14px",
        wordWrap: {width: `${this.sign.displayWidth}`, useAdvancedWrap: true},
    })
    .setOrigin(0.5);

    // Add barrel for NPC to stand on
    this.add.image(width * 0.5, height * 0.4, "barrel").setScale(2);

    // Add chest
    this.add.image(width * 0.4, height * 0.4, "chest").setScale(2);

    // Add player character based on characterID
    switch (characterID) {
        case "1":
            this.player = this.physics.add.sprite(200, 400, "martialIdle").setScale(2);
            this.idleKey = "martialIdle";
            this.runningKey = "martialRunning";
            break;
        case "2":
            this.player = this.physics.add.sprite(200, 400, "huntress").setScale(2.2);
            this.idleKey = "huntressIdle";
            this.runningKey = "huntressRunning";
            break;
        case "3":
            this.player = this.physics.add.sprite(200, 400, "heroKnight").setScale(1.7);
            this.idleKey = "heroKnightIdle";
            this.runningKey = "heroKnightRunning";
            break;
        case "4":
            this.player = this.physics.add.sprite(200, 400, "wizard").setScale(1.3);
            this.idleKey = "wizardIdle";
            this.runningKey = "wizardRunning";
            break;
        default:
            console.log("Something went wrong in player creation in create()");
    }
    this.player.body.syncBounds = true;
    this.player.setBounce(1);
    this.player.setCollideWorldBounds(true);

    // Add username below player character
    this.playerName = this.add.text(this.player.x, this.player.y + this.player.height, userName, {fill: "white", backgroundColor: "black", fontSize: "12px"}).setOrigin(0.5);
    
    // Add NPC
    this.npc = this.physics.add.sprite(width * 0.5, height * 0.29, "merchant").setScale(3);
    this.npc.body.immovable = true;
    this.npc.anims.play("npcIdle", true);

    this.npc.setInteractive();
    this.npc.on("pointerdown", () => this.sound.play("hello"));
    this.npc.on("pointerdown", () => showStartAdventureModal());

    // Add speech bubble for NPC
    this.speech = this.add.image(this.npc.x - this.npc.width / 2, this.npc.y - this.npc.displayHeight/2, "speech");
    this.speech.flipX = true;
    this.tweens.add({
        targets: this.speech,
        y: 115,
        duration: 1000,
        ease: "Linear",
        yoyo: true,
        repeat: -1,
    })

    // Add dialogue for NPC
    this.dialogue = this.add.text(this.speech.x, this.speech.y, "Hey, there! Fancy some practice? Just click on me!", {fill: "white", fontSize: "11px"}).setOrigin(0.5);
    this.dialogue.setVisible(false);

    // Add colliders between characters and world objects
    this.physics.add.collider(this.sign, this.player);
    this.physics.add.collider(this.npc, this.player);

    // Play music
    this.sound.play("blanks_music", {loop: true, volume: 0.3});

    // Spawn handlers
    this.otherPlayers = {};
    spawn = (username, characterType, posX, posY) => {
        if(!this.otherPlayers.hasOwnProperty(username)) {
            console.log("spawning player "+username);
            this.otherPlayers[username] = [];
            switch (characterType) {
                case "1":
                    this.otherPlayers[username]["sprite"] = this.physics.add.sprite(posX, posY, "martialIdle").setScale(2);
                    break;
                case "2":
                    this.otherPlayers[username]["sprite"] = this.physics.add.sprite(posX, posY, "huntress").setScale(2.2);
                    break;
                case "3":
                    this.otherPlayers[username]["sprite"] = this.physics.add.sprite(posX, posY, "heroKnight").setScale(1.7);
                    break;
                case "4":
                    this.otherPlayers[username]["sprite"] = this.physics.add.sprite(posX, posY, "wizard").setScale(1.3);
                    break;
                default:
                    console.log("Something went wrong in player creation in create()");
            }
            let spriteKey = this.otherPlayers[username]["sprite"].texture.key == "martialIdle" ? "martialIdle" : `${this.otherPlayers[username]["sprite"].texture.key}Idle`;
            console.log(spriteKey);
            this.otherPlayers[username]["sprite"].anims.play(spriteKey, true);

            // Add interactive for players
            this.otherPlayers[username]["sprite"].setInteractive();
            this.otherPlayers[username]["sprite"].on("pointerdown", () => showProfileModal(username, characterType));
            
            // Add username below player character
            this.otherPlayers[username]["name"] = this.add.text(this.otherPlayers[username]["sprite"].x, this.otherPlayers[username]["sprite"].y + this.otherPlayers[username]["sprite"].height, username, {fill: "white", backgroundColor: "black", fontSize: "12px"}).setOrigin(0.5);
        }
        else {
            moving(username, characterType, posX, posY);
        }
    }
    moving = (username, characterType, posX, posY) => {
        if(this.otherPlayers.hasOwnProperty(username)) {
            console.log("moving player "+username);
            let player = this.otherPlayers[username];
            
            switch (characterType) {
                case "1":
                    var spriteName = "martial";
                    break;
                case "2":
                    var spriteName = "huntress";
                    break;
                case "3":
                    var spriteName = "heroKnight";
                    break;
                case "4":
                    var spriteName = "wizard";
                    break;
                default:
                    console.log("unknown character type in moving player");
            }

            // Convert to a decimal from 0-1
            // dt = dt > 20 ? 1 : dt/20;

            // Check direction of player moving
            player["sprite"].anims.play(spriteName+"Running", true);
            if (posX > player["sprite"].x) {
                // moving left
                player["sprite"].flipX = false;
                for(let x=player["sprite"].x; x<posX; x++) {
                    let incrementValue = Phaser.Math.Interpolation.SmootherStep(x/posX, player["sprite"].x, posX);
                    console.log(x, incrementValue);
                    player["sprite"].x = incrementValue;
                }
            } else if (posX < player["sprite"].x) {
                // moving right
                player["sprite"].flipX = true;
                for(let x=player["sprite"].x; x>posX; x--) {
                    player["sprite"].x --;
                }   
            }
            if (posY > player["sprite"].y) {
                // moving up
                for(let y=player["sprite"].y; y<posY; y++) {
                    player["sprite"].y ++;
                }
            } else if (posY < player["sprite"].y) {
                // moving down
                for(let y=player["sprite"].y; y>posY; y--) {
                    player["sprite"].y --;
                }
            }
            
            // player["sprite"].setVelocity(0);
            window.setTimeout(function() {
                player["sprite"].anims.play(spriteName+"Idle", true);
            }, 500);

            player["name"].setPosition(player["sprite"].x, player["sprite"].y + player["sprite"].height);
        }
        else {
            spawn(username, characterType, posX, posY);
        }
    }
    destroy = username => {
        if(this.otherPlayers.hasOwnProperty(username)) {
            this.otherPlayers[username]["sprite"].destroy(true);
            this.otherPlayers[username]["sprite"] = null;
            this.otherPlayers[username]["name"].destroy(true);
            this.otherPlayers[username]["name"] = null;
            delete this.otherPlayers[username];
        }
    }
}
var timer = 0;
function update() {
    // Play animations based on keyboard controls
    var move = false;
    if (this.cursors.right.isDown) {
        this.player.setVelocityX(150);
        this.player.flipX = false;
        this.player.anims.play(this.runningKey, true);
        move = true;
    } else if (this.cursors.left.isDown) {
        this.player.setVelocityX(-150);
        this.player.flipX = true;
        this.player.anims.play(this.runningKey, true);
        move = true;
    } else if (this.cursors.up.isDown) {
        this.player.setVelocityY(-150);
        this.player.anims.play(this.runningKey, true);
        move = true;
    } else if (this.cursors.down.isDown) {
        this.player.setVelocityY(150);
        this.player.anims.play(this.runningKey, true);
        move = true;
    } else {
        this.player.setVelocity(0);
        this.player.anims.play(this.idleKey, true);
    }
    if (this.cursors.up.isUp && this.cursors.down.isUp) {
        this.player.setVelocityY(0);
    }
    if (this.cursors.left.isUp && this.cursors.right.isUp) {
        this.player.setVelocityX(0);
    }

    // Set text to follow character
    this.playerName.setPosition(this.player.x, this.player.y + this.player.height);

    // Update player's sprite and position on the socket every x ticks
    timer++;
    if(move && timer % 5 == 0) {
        console.log("PHASER: x"+this.player.x+" y"+this.player.y);
        updateMovement(this.player.x, this.player.y, timer);
    }

    // Display NPC dialogue only when character is close
    if (Phaser.Math.Distance.Between(this.player.x, this.player.y, this.npc.x, this.npc.y) <= 150) {
        this.dialogue.setVisible(true);
        this.speech.setVisible(false);   
    } else {
        this.dialogue.setVisible(false);
        this.speech.setVisible(true);
    }
}

function showStartAdventureModal(){
    var startAdventureModal = new bootstrap.Modal(document.getElementById('startAdventureMode-modal'), {});
	startAdventureModal.show();
}

function showProfileModal(username, characterType){
    var view_username = document.getElementById('username');
    var view_idioms_acc = document.getElementById('idioms_acc');
    var view_pinyin_acc = document.getElementById('pinyin_acc');
    var view_fill_acc = document.getElementById('fill_acc');
    var view_rank = document.getElementById('rank');
    var view_character = document.getElementById('view_character');
    var character_url;
    
    switch (characterType) {
        case "1":
            character_url = "images/martialHero.png";
            break;
        case "2":
            character_url = "images/huntress.png";
            break;
        case "3":
            character_url = "images/heroKnight.png";
            break;
        case "4":
            character_url = "images/wizard.png";
            break;
        default:
            console.log("Cannot detect characterType");
    }
    console.log(character_url);
    
    var xmlhttp = new XMLHttpRequest();
	xmlhttp.onreadystatechange = function() {
            if (this.readyState === 4 && this.status === 200) {

                console.log(this.responseText);
                console.log(this.responseText.length);
		if (this.responseText.length > 2){
                    
                    // Display Username
                    view_username.innerHTML = username;
                    
                    // Display character image
                    view_character.src = character_url;
                    view_character.width = 150;
                    view_character.height = 180;
                    
                    // Split the string using (,)
                    // profile_Array[0] - idiom_lower_correct // profile_Array[1] - idiom_lower_attempted
                    // profile_Array[2] - idiom_upper_correct // profile_Array[3] - idiom_upper_attempted
                    // profile_Array[4] - fill_lower_correct // profile_Array[5] - fill_lower_attempted
                    // profile_Array[6] - fill_upper_correct // profile_Array[7] - fill_upper_attempted
                    // profile_Array[8] - pinyin_lower_correct // profile_Array[9] - pinyin_lower_attempted
                    // profile_Array[10] - pinyin_upper_correct // profile_Array[11] - pinyin_upper_attempted
                    // profile_Array[12] - rank or "NO RECORD YET"
                    var profile_Array = this.responseText.split(',');
                    
                    // Display Idioms_acc
                    var idiom_total_correct = parseInt(profile_Array[0]) + parseInt(profile_Array[2]);
                    var idiom_total_attempted = parseInt(profile_Array[1]) + parseInt(profile_Array[3]);
                    (idiom_total_attempted > 0 ) ? view_idioms_acc.innerHTML = (Math.round(100*idiom_total_correct/idiom_total_attempted)).toFixed(2) + "%":view_idioms_acc.innerHTML = "0%";
                    
                    // Display fill_acc
                    var fill_total_correct = parseInt(profile_Array[4]) + parseInt(profile_Array[6]);
                    var fill_total_attempted = parseInt(profile_Array[5]) + parseInt(profile_Array[7]);
                    (fill_total_attempted > 0 ) ? view_fill_acc.innerHTML = (Math.round(100*fill_total_correct/fill_total_attempted)).toFixed(2) + "%":view_fill_acc.innerHTML = "0%";
                    
                    // Display Pinyin_acc
                    var pinyin_total_correct = parseInt(profile_Array[8]) + parseInt(profile_Array[10]);
                    var pinyin_total_attempted = parseInt(profile_Array[9]) + parseInt(profile_Array[11]);
                    (pinyin_total_attempted > 0 ) ? view_pinyin_acc.innerHTML = (Math.round(100*pinyin_total_correct/pinyin_total_attempted)).toFixed(2) + "%":view_pinyin_acc.innerHTML = "0%";
                    
                    // Display Rank
                    view_rank.innerHTML = profile_Array[12];
                }
                if (this.responseText.length === 1 && this.responseText === "1"){
                    console.log("Account_id cannot be detected!");
                }
                if (this.responseText.length === 1 && this.responseText === "2"){
                    console.log("A server error occurred</div>");
                }
            }
	};
	xmlhttp.open("POST", "../scripts/student", true);
        // Request headers required for a POST request
        xmlhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xmlhttp.send(`username=${username}&function_name=viewProfile`);
        
    var viewProfileModal = new bootstrap.Modal(document.getElementById('viewProfile-modal'), {});
        viewProfileModal.show();
}

function updateMovement(posX, posY, timer) {
    socket.send(`/move x${posX} y${posY} t${timer}`);
}