// back button
const backButton = document.getElementById('backButton');
backButton.addEventListener('click', goToMainMenuPage);

function goToMainMenuPage(){
	window.location.href = 'main_menu.html';
}


// login button
const loginButton = document.getElementById('loginButton');
loginButton.addEventListener('click', login);

// username
const username = document.getElementById('username');

// password
const password = document.getElementById('password');

var teacher = document.getElementById('teacher');

// function for login
function login(){
	var userName = username.value;
	var passWord = password.value;
	var teacherInput = teacher.value;

	if(!userName || !passWord){
		alert("username or password is empty!")
		return;
	}

	console.log(`username: ${username}`);
    console.log(`password: ${password}`);
    console.log(`teacher id: ${teacher}`);

	//backend stuff for login
	// AJAX allows you to send variables from JS (frontend) to PHP (backend) without having to load a new page on the user end. 
    // The below structure is standard for AJAX requests
    var xmlhttp = new XMLHttpRequest();
    xmlhttp.onreadystatechange = function() {
        // On success
        if (this.readyState == 4 && this.status == 200) {
            // Do something with the response
            console.log(this.responseText);

            // The login script has the following possible outputs: ints 0, 1 or 2, so we'll translate that over to the frontend once the response code is received.
			// a div to show message
            var resp = document.getElementById("response");
            if(this.responseText.includes(0)){
				window.location.href = 'world_selection.html'; //goes to world selection page upon successful login
				// resp.innerHTML = "Successfully logged in!";
				// In the real code, you'd probably want to redirect the user to the world selection or account page
			}
            if(this.responseText.includes(1)) resp.innerHTML = "Your login details were incorrect.";
            if(this.responseText.includes(2)) resp.innerHTML = "A server error occurred.";
        }
    };
    // We can send GET or POST requests but it's better to send sensitive details like password with POST, so it won't be revealed on user's browsing history. 
    // Example GET URI: https://example.com/target_script?username=test&password=abc123
    // Example POST URI: https://example.com/target_script
    // The target filepath of the script you want to send the variables to is specified here. 
    xmlhttp.open("POST", "../scripts/function_login", true);
    // Request headers required for a POST request
    xmlhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    // Send the variables here. We'll omit the teacher id since it's not needed on the login script
    xmlhttp.send(`username=${username}&password=${password}`);
}

// forget password page
const forgetPassword = document.getElementById('forgetPassword');
forgetPassword.addEventListener('click', forgetPasswordPage);

function forgetPasswordPage(){
	window.location.href = 'forgot_password.html';
}