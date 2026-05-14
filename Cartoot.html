<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Real Cartoon Automation - Fast</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #87CEEB; /* Sky Blue */
            font-family: 'Comic Sans MS', 'Arial', sans-serif;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100vh;
        }

        /* --- Sky & Ground --- */
        .sky {
            position: absolute;
            top: 0;
            width: 100%;
            height: 70%;
            background: linear-gradient(to bottom, #87CEEB, #E0F7FA);
        }
        
        .ground {
            position: absolute;
            bottom: 0;
            width: 100%;
            height: 30%;
            background: #8BC34A; /* Grass Green */
            border-top: 10px solid #689F38;
            z-index: 1;
        }

        /* --- Story Board --- */
        .story-board {
            position: absolute;
            top: 20px;
            background: rgba(255, 255, 255, 0.9);
            padding: 15px 30px;
            border-radius: 20px;
            font-size: 1.5rem;
            font-weight: bold;
            color: #333;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 100;
            text-align: center;
            min-width: 300px;
            transition: transform 0.3s;
        }

        /* --- Characters (Cartoon Style) --- */
        .character {
            position: absolute;
            bottom: 30%; /* On the ground line */
            width: 100px;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: left 1s linear; /* Movement Speed */
            z-index: 10;
        }

        /* Hair */
        .hair {
            width: 60px;
            height: 30px;
            border-radius: 30px 30px 0 0;
            position: absolute;
            top: -15px;
            z-index: 4;
        }

        /* Head & Face */
        .head {
            width: 60px;
            height: 60px;
            background: #FFCC80; /* Skin tone */
            border-radius: 50%;
            border: 2px solid #333;
            position: relative;
            z-index: 3;
        }

        .eye {
            width: 12px;
            height: 12px;
            background: #000;
            border-radius: 50%;
            position: absolute;
            top: 20px;
            animation: blink 4s infinite;
        }
        .eye.left { left: 12px; }
        .eye.right { right: 12px; }

        .mouth {
            width: 20px;
            height: 10px;
            background: #D84315;
            border-radius: 0 0 10px 10px;
            position: absolute;
            bottom: 12px;
            left: 20px;
            transition: all 0.3s;
        }

        /* Body */
        .body {
            width: 50px;
            height: 70px;
            background: #fff;
            border: 2px solid #333;
            border-radius: 20px 20px 0 0;
            margin-top: -10px;
            position: relative;
            z-index: 2;
        }

        /* Hands */
        .hand {
            width: 15px;
            height: 40px;
            background: #FFCC80;
            border: 2px solid #333;
            position: absolute;
            top: 10px;
            border-radius: 10px;
            z-index: 5;
        }
        .hand.left { left: -12px; transform: rotate(10deg); }
        .hand.right { right: -12px; transform: rotate(-10deg); }

        /* Legs */
        .leg {
            width: 18px;
            height: 40px;
            background: #333; /* Pants */
            position: absolute;
            bottom: -35px;
            border-radius: 5px;
        }
        .leg.left { left: 5px; }
        .leg.right { right: 5px; }

        /* Character Specifics */
        /* Developer */
        #developer .hair { background: #5D4037; height: 25px; top: -10px; } /* Short Hair */
        #developer .body { background: #4CAF50; } /* Green Shirt */
        
        /* Tester */
        #tester .hair { background: #000; height: 40px; top: -20px; } /* Spiky/Long Hair */
        #tester .body { background: #FF9800; } /* Orange Shirt */

        /* QA Lead */
        #qa-lead .hair { background: #795548; height: 30px; top: -15px; width: 65px; border-radius: 10px 10px 0 0; } /* Professional Hair */
        #qa-lead .body { background: #3F51B5; } /* Blue Shirt */
        #qa-lead .head::after {
            content: '👔'; /* Tie */
            position: absolute;
            top: 40px;
            left: 22px;
            font-size: 20px;
        }

        /* --- Props --- */
        /* The App */
        #app-icon {
            width: 40px;
            height: 40px;
            background: #2196F3;
            border: 2px solid #fff;
            border-radius: 8px;
            position: absolute;
            top: 40px; /* Held in hand */
            right: -10px;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            box-shadow: 2px 2px 5px rgba(0,0,0,0.3);
        }

        /* The Bucket */
        #bucket {
            width: 50px;
            height: 45px;
            background: #FFEB3B;
            border: 3px solid #FBC02D;
            border-radius: 0 0 10px 10px;
            position: absolute;
            top: 60px; /* Held in other hand */
            left: -15px;
            z-index: 20;
            overflow: hidden;
        }
        #bucket::before {
            content: ''; /* Handle */
            position: absolute;
            top: -10px;
            left: 5px;
            width: 30px;
            height: 15px;
            border: 3px solid #FBC02D;
            border-bottom: none;
            border-radius: 20px 20px 0 0;
        }

        /* Bugs inside bucket */
        .mini-bug {
            width: 10px;
            height: 10px;
            background: #D32F2F;
            border-radius: 50%;
            position: absolute;
            bottom: 2px;
        }

        /* --- Animations --- */
        @keyframes blink {
            0%, 96%, 100% { height: 12px; }
            98% { height: 1px; }
        }

        /* Walking Animation */
        .walking {
            animation: walkBounce 0.3s infinite; /* Faster walking */
        }
        @keyframes walkBounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        /* Talking Animation */
        .talking .mouth {
            height: 15px;
            border-radius: 50%;
            width: 25px;
            left: 17px;
        }

        /* Dialog Bubble */
        .bubble {
            position: absolute;
            top: -80px;
            background: #fff;
            border: 2px solid #333;
            padding: 10px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: bold;
            opacity: 0;
            transition: opacity 0.3s; /* Faster bubble */
            width: 150px;
            text-align: center;
            z-index: 50;
        }
        .bubble::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            border-width: 10px 10px 0;
            border-style: solid;
            border-color: #333 transparent;
            display: block;
            width: 0;
        }
        .bubble::before {
            content: '';
            position: absolute;
            bottom: -6px;
            left: 50%;
            transform: translateX(-50%);
            border-width: 10px 10px 0;
            border-style: solid;
            border-color: #fff transparent;
            display: block;
            width: 0;
            z-index: 1;
        }
        .bubble.show {
            opacity: 1;
        }

    </style>
</head>
<body>

<div class="sky"></div>
<div class="ground"></div>

<div class="story-board" id="story-text">Loading...</div>

<!-- Characters -->

<!-- 1. Developer -->
<div id="developer" class="character" style="left: 10%;">
    <div class="hair"></div>
    <div class="head">
        <div class="eye left"></div>
        <div class="eye right"></div>
        <div class="mouth"></div>
    </div>
    <div class="body">
        <div class="hand left"></div>
        <div class="hand right"></div>
        <div class="leg left"></div>
        <div class="leg right"></div>
    </div>
    <div class="bubble" id="dev-bubble">Code Complete!</div>
    <div style="margin-top:10px; font-weight:bold; color:#333;">Developer</div>
</div>

<!-- 2. Tester (Holding App and Bucket initially after receiving) -->
<div id="tester" class="character" style="left: 50%;">
    <div class="hair"></div>
    <div class="head">
        <div class="eye left"></div>
        <div class="eye right"></div>
        <div class="mouth"></div>
    </div>
    <div class="body">
        <!-- App -->
        <div id="app-icon">App</div>
        <!-- Bucket -->
        <div id="bucket">
            <div class="mini-bug" style="left:5px;"></div>
            <div class="mini-bug" style="left:20px;"></div>
            <div class="mini-bug" style="left:35px;"></div>
        </div>
        <!-- Hands -->
        <div class="hand left"></div>
        <div class="hand right"></div>
        <div class="leg left"></div>
        <div class="leg right"></div>
    </div>
    <div class="bubble" id="tester-bubble">Found Bugs!</div>
    <div style="margin-top:10px; font-weight:bold; color:#333;">Tester</div>
</div>

<!-- 3. QA Lead -->
<div id="qa-lead" class="character" style="left: 85%;">
    <div class="hair"></div>
    <div class="head">
        <div class="eye left"></div>
        <div class="eye right"></div>
        <div class="mouth"></div>
    </div>
    <div class="body">
        <div class="hand left"></div>
        <div class="hand right"></div>
        <div class="leg left"></div>
        <div class="leg right"></div>
    </div>
    <div class="bubble" id="qa-bubble">Not Acceptable!</div>
    <div style="margin-top:10px; font-weight:bold; color:#333;">QA Lead</div>
</div>

<script>
    // Elements
    const dev = document.getElementById('developer');
    const tester = document.getElementById('tester');
    const qa = document.getElementById('qa-lead');
    const storyText = document.getElementById('story-text');
    
    const appIcon = document.getElementById('app-icon');
    const bucket = document.getElementById('bucket');
    
    const devBubble = document.getElementById('dev-bubble');
    const testerBubble = document.getElementById('tester-bubble');
    const qaBubble = document.getElementById('qa-bubble');

    // Helper to wait
    const wait = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    // Helper to show/hide bubble
    function say(charName, text) {
        let bubble;
        if(charName === 'dev') bubble = devBubble;
        if(charName === 'tester') bubble = testerBubble;
        if(charName === 'qa') bubble = qaBubble;
        
        bubble.textContent = text;
        bubble.classList.add('show');
        document.getElementById(charName).classList.add('talking');
        
        // Hide after 1.5 seconds (Faster)
        setTimeout(() => {
            bubble.classList.remove('show');
            document.getElementById(charName).classList.remove('talking');
        }, 1500);
    }

    // Helper to move character
    async function move(char, leftPercent, duration = 800) { // Faster movement (800ms)
        char.classList.add('walking');
        char.style.transition = `left ${duration}ms linear`;
        char.style.left = leftPercent;
        await wait(duration);
        char.classList.remove('walking');
    }

    // Helper to transfer props (App & Bucket)
    function transferProps(fromChar, toChar) {
        // Find the body of the target
        const targetBody = toChar.querySelector('.body');
        
        // Move App
        if(fromChar.contains(appIcon)) {
            targetBody.appendChild(appIcon);
            appIcon.style.position = 'absolute';
            appIcon.style.top = '40px';
            appIcon.style.right = '-10px';
        }
        
        // Move Bucket
        if(fromChar.contains(bucket)) {
            targetBody.appendChild(bucket);
            bucket.style.position = 'absolute';
            bucket.style.top = '60px';
            bucket.style.left = '-15px';
        }
    }

    // --- Main Animation Sequence ---
    async function startStory() {
        
        // --- 1. RESET STATE ---
        // Force reset positions instantly without animation
        dev.style.transition = 'none';
        tester.style.transition = 'none';
        qa.style.transition = 'none';
        
        dev.style.left = '10%';
        tester.style.left = '50%';
        qa.style.left = '85%';

        // Ensure Dev has the App initially
        const devBody = dev.querySelector('.body');
        if(bucket.parentElement !== devBody) {
            devBody.appendChild(appIcon);
            devBody.appendChild(bucket);
            appIcon.style.position = 'absolute';
            appIcon.style.top = '40px';
            appIcon.style.right = '-10px';
            bucket.style.position = 'absolute';
            bucket.style.top = '60px';
            bucket.style.left = '-15px';
        }

        // Reset Bucket Visuals
        bucket.style.background = '#FFEB3B';
        const miniBugs = bucket.querySelectorAll('.mini-bug');
        miniBugs.forEach(bug => {
            bug.style.transition = 'none';
            bug.style.transform = 'scale(1)';
        });

        // Restore transitions after reset
        await wait(50);
        dev.style.transition = 'left 0.8s linear';
        tester.style.transition = 'left 0.8s linear';
        qa.style.transition = 'left 0.8s linear';

        // --- 2. START STORY ---
        
        // Step 1: Developer creates App
        storyText.textContent = "👨‍💻 Developer is coding the App...";
        say('dev', 'App is Ready!');
        await wait(1500); // Reduced wait time

        // Step 2: Dev gives App to Tester
        storyText.textContent = "📲 Developer gives App to Tester";
        await move(dev, '40%', 1000); // Faster move
        
        // Handover
        transferProps(dev, tester);
        await wait(300);
        
        say('tester', 'Okay, testing now.');
        await move(dev, '10%', 1000); // Dev goes back fast
        await wait(800);

        // Step 3: Tester finds Bugs
        storyText.textContent = "🐞 Tester found Bugs and filled the Bucket!";
        tester.querySelector('.bubble').textContent = "Oh! Bugs!";
        say('tester', 'So many Bugs!');
        
        // Visual effect: Bugs appear in bucket
        bucket.style.background = '#FFCDD2'; // Turn bucket slightly reddish
        await wait(1500); // Shorter wait

        // Step 4: Tester goes to QA Lead
        storyText.textContent = "🏃 Tester takes App & Bucket to QA Lead";
        await move(tester, '75%', 1200); // Faster move
        
        say('qa', 'What is this?!');
        await wait(1000);
        
        // Handover: Tester gives App & Bucket to QA Lead
        transferProps(tester, qa);
        say('tester', 'Sir, here is the App & Bugs...');
        
        await wait(1200);
        
        // QA Lead reaction
        say('qa', 'This is wrong! I will go myself.');
        await wait(1500);
        
        // Tester leaves
        await move(tester, '50%', 1000);

        // Step 5: QA Lead goes to Developer
        storyText.textContent = "😡 QA Lead goes to Developer";
        qa.querySelector('.bubble').textContent = "Fix it Now!";
        await move(qa, '20%', 1500);
        
        say('qa', 'Fix this or else...');
        await wait(1200);

        // Step 6: Handover to Dev
        transferProps(qa, dev);
        say('dev', 'Oh! I will fix it right away.');
        await wait(1200);
        
        // QA Lead leaves
        await move(qa, '85%', 1500);

        // Step 7: Fixing
        storyText.textContent = "🔧 Developer fixes all the Bugs!";
        
        // Visual Fix: Bugs disappear from bucket
        miniBugs.forEach(bug => {
            bug.style.transition = 'all 0.5s'; // Faster fix animation
            bug.style.transform = 'scale(0)';
        });
        bucket.style.background = '#FFEB3B'; // Back to yellow
        
        await wait(800);
        say('dev', 'Fixed! All Good ✨');
        
        await wait(1500);
        
        // Loop the story
        storyText.textContent = "🔄 Restarting story...";
        await wait(1000);
        
        startStory(); // Recursive call for infinite loop
    }

    // Start on load
    window.onload = startStory;

</script>
</body>
</html>