<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Error</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .container {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 500px;
            width: 90%;
        }
        .icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            background: #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: white;
        }
        h1 {
            color: #1f2937;
            margin: 0 0 1rem;
            font-size: 2rem;
        }
        p {
            color: #6b7280;
            margin: 0 0 1.5rem;
            line-height: 1.6;
        }
        .error-reason {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 1rem;
            margin: 1rem 0;
            text-align: left;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">⚠</div>
        <h1>Payment Error</h1>
        <p>An error occurred while processing your payment.</p>
        
        @if(isset($reason))
        <div class="error-reason">
            <strong>Error:</strong> {{ $reason }}
        </div>
        @endif
        
        <p>Please try again or contact support if the problem persists.</p>
    </div>
</body>
</html>

