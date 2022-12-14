export function getLoggedInUsername() {
    return new Promise(function(resolve) {
        var xmlhttp = new XMLHttpRequest();
        xmlhttp.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                resolve(this.responseText);
            }
        };
        xmlhttp.open("GET", "../scripts/functions_utility?func=Username");
        xmlhttp.send();
    })
}

function getLoggedInAccountId() {
    var xmlhttp = new XMLHttpRequest();
    xmlhttp.onreadystatechange = function() {
        if (this.readyState == 4 && this.status == 200) {
            return this.responseText;
        }
    };
    xmlhttp.open("GET", "../scripts/functions_utility?func=AccountId");
    xmlhttp.send();
}

export function getLoggedInCharacter() {
    return new Promise(function(resolve) {
        var xmlhttp = new XMLHttpRequest();
        xmlhttp.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                // console.log(this.responseText);
                resolve(this.responseText);
            }
        };
        xmlhttp.open("GET", "../scripts/functions_utility?func=Character");
        xmlhttp.send();
    })
}

export function getCharacterFromUsername(uname) {
    return new Promise(function(resolve) {
        var xmlhttp = new XMLHttpRequest();
        xmlhttp.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                resolve(this.responseText);
            }
        };
        xmlhttp.open("GET", "../scripts/functions_utility?func=getCharacterFromUsername&uname="+uname);
        xmlhttp.send();
    })
}

function getLoggedInTeacherId() {
    var xmlhttp = new XMLHttpRequest();
    xmlhttp.onreadystatechange = function() {
        if (this.readyState == 4 && this.status == 200) {
            return this.responseText;
        }
    };
    xmlhttp.open("GET", "../scripts/functions_utility?func=TeacherId");
    xmlhttp.send();
}

function getLoggedInAccountType() {
    var xmlhttp = new XMLHttpRequest();
    xmlhttp.onreadystatechange = function() {
        if (this.readyState == 4 && this.status == 200) {
            return this.responseText;
        }
    };
    xmlhttp.open("GET", "../scripts/functions_utility?func=AccountType");
    xmlhttp.send();
}