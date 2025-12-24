<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled</title>
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
            background: #f59e0b;
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
        .payment-info {
            background: #f9fafb;
            border-radius: 8px;
            padding: 1rem;
            margin: 1.5rem 0;
            text-align: left;
        }
        .payment-info strong {
            color: #374151;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">✕</div>
        <h1>Payment Cancelled</h1>
        <p>Your payment was cancelled. No charges were made.</p>
        
        @if(isset($payment))
        <div class="payment-info">
            <p><strong>Payment ID:</strong> {{ $payment->id }}</p>
            <p><strong>Amount:</strong> {{ number_format($payment->amount, 2) }} {{ $payment->currency }}</p>
            <p><strong>Status:</strong> {{ ucfirst($payment->status) }}</p>
        </div>
        @endif
        
        <p>You can safely close this window.</p>
    </div>
</body>
</html>

