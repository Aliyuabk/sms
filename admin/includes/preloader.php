<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Universal Pre-loader</title>
    <style>
        /* Unique class names to avoid conflicts with existing styles */
        .uni-preloader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 999999;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }
        
        .uni-preloader.hidden {
            opacity: 0;
            visibility: hidden;
        }
        
        .uni-loader {
            width: 64px;
            height: 64px;
            position: relative;
        }
        
        .uni-loader-inner {
            width: 100%;
            height: 100%;
            border: 4px solid transparent;
            border-top-color: #6d9cc1;
            border-radius: 50%;
            animation: uni-spin 1s linear infinite;
        }
        
        .uni-loader-inner:before {
            content: '';
            position: absolute;
            top: 5px;
            left: 5px;
            right: 5px;
            bottom: 5px;
            border: 4px solid transparent;
            border-top-color: #d4f070;
            border-radius: 50%;
            animation: uni-spin 1.5s linear infinite;
        }
        
        .uni-loader-inner:after {
            content: '';
            position: absolute;
            top: 15px;
            left: 15px;
            right: 15px;
            bottom: 15px;
            border: 4px solid transparent;
            border-top-color: #6d9cc1;
            border-radius: 50%;
            animation: uni-spin 0.75s linear infinite;
        }
        
        @keyframes uni-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Optional: Add text under the spinner */
        .uni-loader-text {
            margin-top: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            color: #555;
            text-align: center;
        }
        
        /* Style for demo content */
        .demo-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
        }
        
        .demo-content h1 {
            color: #333;
            margin-bottom: 20px;
        }
        
        .demo-content p {
            margin-bottom: 15px;
            color: #555;
        }
        
        .demo-content code {
            background-color: #f1f1f1;
            padding: 2px 5px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        
        .demo-content pre {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            border-left: 4px solid #3498db;
            margin: 20px 0;
        }
        
        .demo-content button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px;
        }
        
        .demo-content button:hover {
            background-color: #2980b9;
        }
    </style>
     <div id="uni-preloader" class="uni-preloader">
        <div class="uni-loader">
            <div class="uni-loader-inner"></div>
        </div>
    </div>

    <script>
        // Universal Pre-loader JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            const preloader = document.getElementById('uni-preloader');
            let loaderInner = document.querySelector('.uni-loader-inner');
            
            // Hide preloader when page is fully loaded
            window.addEventListener('load', function() {
                setTimeout(function() {
                    preloader.classList.add('hidden');
                }, 500); // Small delay for smooth transition
            });
            
            // Fallback: Hide preloader after 3 seconds max (in case load event doesn't fire)
            setTimeout(function() {
                if (!preloader.classList.contains('hidden')) {
                    preloader.classList.add('hidden');
                }
            }, 5000);
            
            // Demo functionality
            document.getElementById('show-loader').addEventListener('click', function() {
                preloader.classList.remove('hidden');
                setTimeout(function() {
                    preloader.classList.add('hidden');
                }, 4000);
            });
            
            document.getElementById('change-color').addEventListener('click', function() {
                const colors = ['#3498db', '#9b59b6', '#e74c3c', '#f39c12', '#1abc9c'];
                const randomColor = colors[Math.floor(Math.random() * colors.length)];
                loaderInner.style.borderTopColor = randomColor;
            });
            
            document.getElementById('change-bg').addEventListener('click', function() {
                const backgrounds = ['#f8f9fa', '#ffffff', '#f1f8ff', '#f9f7ff', '#fff7f1'];
                const randomBg = backgrounds[Math.floor(Math.random() * backgrounds.length)];
                preloader.style.backgroundColor = randomBg;
            });
        });
    </script>
</body>
</html>