# Yoco Payment Integration Guide

This document provides comprehensive information about the Yoco payment integration implemented for the ORDO marketplace platform.

## Overview

The Yoco payment integration provides secure payment processing for booking transactions between customers and service providers. It includes support for:

- Payment intent creation
- Secure payment processing
- Webhook handling for real-time updates
- Refund processing
- Payment status tracking
- Platform fee management

## Setup Instructions

### 1. Environment Configuration

Add the following environment variables to your `.env` file:

```bash
# Yoco API Configuration
YOCO_API_URL=https://api.yoco.com/v1
YOCO_SECRET_KEY=your_secret_key_here
YOCO_PUBLIC_KEY=your_public_key_here
YOCO_WEBHOOK_SECRET=your_webhook_secret_here
YOCO_WEBHOOK_URL=https://yourdomain.com/api/v1/payments/yoco/webhook

# Application Configuration
APP_PLATFORM_FEE_PERCENTAGE=5.0
```

### 2. Yoco Account Setup

1. Sign up for a Yoco account at [https://developer.yoco.com](https://developer.yoco.com)
2. Create a new application in the Yoco dashboard
3. Copy your API keys (secret and public keys)
4. Set up webhook endpoints for real-time payment updates
5. Configure your webhook secret for security

### 3. Database Setup

The payment integration uses existing database tables:
- `payments` - Main payment records
- `bookings` - Booking information
- `transactions` - Transaction history

No additional migrations are required as the payment models are already configured.

## API Endpoints

### Public Endpoints

#### Get Public Key
```http
GET /api/v1/payments/yoco/public-key
```

Response:
```json
{
    "success": true,
    "data": {
        "public_key": "pk_test_...",
        "currency": "ZAR"
    }
}
```

#### Webhook Handler
```http
POST /api/v1/payments/yoco/webhook
Headers:
  X-Yoco-Signature: webhook_signature
```

### Authenticated Endpoints

#### Create Payment Intent
```http
POST /api/v1/payments/yoco/bookings/{booking}/create-intent
Authorization: Bearer {token}

Body:
{
    "save_payment_method": false,
    "customer_details": {
        "email": "customer@example.com",
        "phone": "+27123456789"
    }
}
```

Response:
```json
{
    "success": true,
    "data": {
        "client_secret": "ch_test_...",
        "payment_id": "payment-uuid",
        "amount": 150.00,
        "currency": "ZAR",
        "public_key": "pk_test_..."
    },
    "message": "Payment intent created successfully"
}
```

#### Confirm Payment
```http
POST /api/v1/payments/yoco/confirm
Authorization: Bearer {token}

Body:
{
    "charge_id": "ch_test_...",
    "payment_id": "payment-uuid"
}
```

#### Get Payment Status
```http
GET /api/v1/payments/yoco/{payment}/status
Authorization: Bearer {token}
```

#### Create Refund
```http
POST /api/v1/payments/yoco/{payment}/refund
Authorization: Bearer {token}

Body:
{
    "amount": 75.00,
    "reason": "Customer requested cancellation"
}
```

## Frontend Integration

### 1. Install Yoco SDK

Add the Yoco SDK to your frontend application:

```html
<script src="https://js.yoco.com/sdk/v1/yoco-sdk-web.js"></script>
```

### 2. Initialize Yoco

```javascript
// Get public key from API
const response = await fetch('/api/v1/payments/yoco/public-key');
const { data } = await response.json();

// Initialize Yoco
const yoco = new window.YocoSDK({
    publicKey: data.public_key
});
```

### 3. Create Payment Intent

```javascript
// Create payment intent for booking
const createPaymentIntent = async (bookingId) => {
    const response = await fetch(`/api/v1/payments/yoco/bookings/${bookingId}/create-intent`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${authToken}`
        },
        body: JSON.stringify({
            customer_details: {
                email: customerEmail,
                phone: customerPhone
            }
        })
    });
    
    return response.json();
};
```

### 4. Process Payment

```javascript
const processPayment = async (clientSecret, paymentId) => {
    try {
        // Charge the card using Yoco SDK
        const result = await yoco.chargeCard({
            amountInCents: amount * 100,
            currency: 'ZAR',
            metadata: {
                payment_id: paymentId
            }
        });

        if (result.error) {
            throw new Error(result.error.message);
        }

        // Confirm payment on backend
        const confirmResponse = await fetch('/api/v1/payments/yoco/confirm', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${authToken}`
            },
            body: JSON.stringify({
                charge_id: result.id,
                payment_id: paymentId
            })
        });

        return confirmResponse.json();
        
    } catch (error) {
        console.error('Payment processing error:', error);
        throw error;
    }
};
```

## Security Considerations

### 1. Webhook Verification

All webhook requests are verified using HMAC signatures:
- Webhook secret is used to generate signature
- Incoming signatures are verified before processing
- Invalid signatures are rejected

### 2. Payment Authorization

- Payment creation requires authentication
- Users can only create payments for their own bookings
- Refunds require vendor or admin privileges

### 3. Data Protection

- Sensitive payment data is not stored locally
- All payment processing goes through Yoco's secure servers
- PCI compliance is handled by Yoco

## Error Handling

### Common Error Scenarios

1. **Invalid API Keys**: Check environment configuration
2. **Webhook Signature Mismatch**: Verify webhook secret
3. **Payment Declined**: Handle gracefully in frontend
4. **Network Issues**: Implement retry logic
5. **Invalid Booking**: Ensure booking exists and is valid

### Error Response Format

```json
{
    "success": false,
    "message": "Human-readable error message",
    "error": "Technical error details (debug mode only)"
}
```

## Platform Fee Management

The system automatically calculates platform fees:

1. **Fee Calculation**: Based on `APP_PLATFORM_FEE_PERCENTAGE`
2. **Fee Deduction**: Automatically deducted from vendor amount
3. **Transparency**: Both amounts are stored in payment record

Example:
- Booking Amount: R150.00
- Platform Fee (5%): R7.50
- Vendor Amount: R142.50

## Testing

### Test Environment

Use Yoco's test API keys for development:
- Test Secret Key: `sk_test_...`
- Test Public Key: `pk_test_...`
- Test amounts are not charged

### Test Cards

Yoco provides test cards for different scenarios:
- `4242 4242 4242 4242` - Successful payment
- `4000 0000 0000 0002` - Declined payment
- `4000 0000 0000 0341` - Requires 3D Secure

## Monitoring and Logging

### Payment Events

All payment events are logged with relevant context:
- Payment creation
- Payment completion
- Payment failures
- Refund processing
- Webhook processing

### Webhook Events

Supported webhook events:
- `charge.succeeded` - Payment completed successfully
- `charge.failed` - Payment failed
- `charge.pending` - Payment is processing
- `refund.succeeded` - Refund completed

## Troubleshooting

### Common Issues

1. **Payments Not Completing**
   - Check webhook configuration
   - Verify webhook secret
   - Review payment status in Yoco dashboard

2. **Refunds Failing**
   - Ensure payment is eligible for refund
   - Check refund amount limits
   - Verify API credentials

3. **Webhook Failures**
   - Check server accessibility
   - Verify SSL certificate
   - Review webhook signature validation

### Debug Mode

Enable debug mode in environment:
```bash
APP_DEBUG=true
```

This provides detailed error messages in API responses.

## Support

For technical support:
- Yoco API Documentation: [https://developer.yoco.com](https://developer.yoco.com)
- Yoco Support: [support@yoco.co.za](mailto:support@yoco.co.za)

For implementation questions, refer to the code comments in:
- `app/Services/YocoPaymentService.php`
- `app/Http/Controllers/YocoPaymentController.php`