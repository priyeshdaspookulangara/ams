# Notification Setup Guide (SMS & WhatsApp via Twilio)

This system uses Twilio for sending SMS and WhatsApp notifications. To enable these features, you need to configure your Twilio account credentials and relevant phone numbers in the `config.php` file.

## 1. Twilio Account

If you don't have one, sign up for a Twilio account at [https://www.twilio.com/try-twilio](https://www.twilio.com/try-twilio).
You will get an **Account SID** and an **Auth Token**. These are crucial for API access.

## 2. Configuration in `config.php`

Open the `config.php` file in the root directory of the project. You will find a section for Notification Gateway Configuration. Update the following constants with your actual Twilio details:

```php
// --- Notification Gateway Configuration ---
// Replace with your actual Twilio credentials and numbers.

// Twilio SMS Configuration
define('TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'); // Your Twilio Account SID
define('TWILIO_AUTH_TOKEN', 'your_auth_token_xxxxxxxxxxxxxxx');   // Your Twilio Auth Token
define('TWILIO_PHONE_NUMBER', '+1234567890'); // Your Twilio phone number (must be SMS capable)

// Twilio WhatsApp Configuration
// This can be your Twilio number if it's WhatsApp-enabled, or the Twilio Sandbox for WhatsApp number.
define('TWILIO_WHATSAPP_SENDER', 'whatsapp:+14155238886'); // Example: Twilio Sandbox number.
                                                        // Replace with your configured WhatsApp sender (e.g., whatsapp:+1234567890)
```

**Important Security Note:** For a production environment, it is highly recommended to store sensitive credentials like `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN` using more secure methods, such as environment variables, rather than directly in `config.php`.

## 3. SMS Setup

*   **`TWILIO_ACCOUNT_SID`**: Find this on your Twilio console dashboard.
*   **`TWILIO_AUTH_TOKEN`**: Also on your Twilio console dashboard.
*   **`TWILIO_PHONE_NUMBER`**: This must be a phone number you have purchased or verified in your Twilio account that is SMS-capable. Enter it in E.164 format (e.g., `+12223334444`).

Once these are correctly set, SMS notifications should function.

## 4. WhatsApp Setup (Using Twilio Sandbox for Development/Testing)

The current integration is primarily designed to work easily with the **Twilio Sandbox for WhatsApp**. This allows you to test WhatsApp messaging without needing a fully provisioned WhatsApp Business number immediately.

**Steps for Twilio WhatsApp Sandbox:**

1.  **Access the Sandbox:** In your Twilio Console, navigate to Messaging > Try it out > Send a WhatsApp message. Here you'll find the Twilio Sandbox number (often `+1 415 523 8886`).
2.  **Opt-In:** To receive messages from the Sandbox on your personal WhatsApp number, you (and any other test recipients) must send a specific join message (e.g., "join <your-sandbox-keyword>") from your WhatsApp account to the Twilio Sandbox number. Follow the instructions on the Twilio Sandbox page.
3.  **Configure `TWILIO_WHATSAPP_SENDER`:** In `config.php`, set `TWILIO_WHATSAPP_SENDER` to the Twilio Sandbox number, prefixed with `whatsapp:`.
    ```php
    define('TWILIO_WHATSAPP_SENDER', 'whatsapp:+14155238886'); // Default Twilio Sandbox
    ```
4.  **Recipient Phone Numbers:** Ensure parent/guardian phone numbers in the system are in E.164 format (e.g., `+1XXXYYYZZZZ` including the country code). The application will automatically prefix `whatsapp:` to these numbers when sending via Twilio.

**Moving to a Production WhatsApp Sender:**

Using your own dedicated phone number for WhatsApp Business API via Twilio involves a more complex setup:
1.  **Facebook Business Manager:** You'll need a verified Facebook Business Manager account.
2.  **WhatsApp Business Account (WABA):** Create or connect a WABA within your Facebook Business Manager.
3.  **Phone Number:** Provision a phone number (new or existing, with conditions) to be used as your WhatsApp Business API sender. This is done through Twilio's console.
4.  **Message Templates:** For business-initiated conversations (like these absence notifications), WhatsApp requires you to use pre-approved Message Templates. Freeform text messages are generally only allowed within a 24-hour "customer care window" after a user messages your business number.
    *   You would need to create templates in the Twilio console (or via API) that match the notification messages (e.g., "Dear {1}, {2} (Roll No: {3}) was absent on {4}. Contact office: {5}.") and get them approved by WhatsApp.
    *   The `send_notification` function would then need to be modified to send template messages instead of freeform text for WhatsApp if outside the 24-hour window. This typically involves sending a `ContentSid` (for the template ID) and `ContentVariables` (for the placeholders) instead of a `Body` parameter. This is an advanced modification not currently implemented.

## 5. Enabling Notifications in the Application

After configuring credentials:
1.  Log in as an Admin.
2.  Go to **Settings**.
3.  Set **Notification Type** to "sms", "whatsapp", or "both".
4.  Customize the notification templates as needed. The placeholders `{parent_name}`, `{student_name}`, `{student_rollnumber}`, `{current_date}`, `{office_number}`, and `{consecutive_days}` will be replaced with actual data.

## Troubleshooting

*   **Check Twilio Logs:** Your Twilio console (Voice & Messaging > Logs > Messages) will show detailed logs of API requests, delivery status, and any errors. This is the first place to look if messages are not being sent/received.
*   **Error Messages in Application:** The "Notification Log" on the attendance page will display success or failure messages from the application's attempt to call the Twilio API.
*   **Valid Phone Numbers:** Ensure recipient phone numbers are correct and in E.164 format.
*   **Twilio Account Balance:** If using a paid Twilio account (beyond trial credits), ensure you have sufficient balance.
*   **WhatsApp Sandbox:** Remember the opt-in requirement and that sandbox sessions can expire.
*   **WhatsApp Production:** For production, ensure your message templates are approved, and you are complying with WhatsApp Business Policy.
```
