# WhatsApp + SMS Activation Guide

**Scope:** what the account owner must do to send real SMS (Twilio) and WhatsApp
(WhatsApp Business Platform / Cloud API) messages from the clinic app, and how to
switch each channel on safely.

**Status:** activates the existing, dormant `TwilioSmsDriver` and
`WhatsAppCloudApiDriver`. No new code needed here — this is the runbook for the
credentials + enablement steps.

---

## 1. How messaging works in this app

- `config/messaging.php` wires two logical channels to driver classes:

  | Channel | Driver | Enabled by |
  |---|---|---|
  | `sms` | `app/Services/Messaging/TwilioSmsDriver.php` | `TWILIO_SMS_ENABLED` |
  | `whatsapp` | `app/Services/Messaging/WhatsAppCloudApiDriver.php` | `WHATSAPP_CLOUD_API_ENABLED` |

- A driver only makes real HTTP calls when **its `*_ENABLED` flag is `true` AND
  its credentials are filled in**. Otherwise `NotificationService` writes a
  `logged` record to `notification_logs` and sends nothing.
- Delivery is gated per user by consent topic (`appointments`, `low_stock`,
  `security`) in `notification_preferences`; opted-out users are recorded as
  `skipped` and never contacted.
- Every attempt is recorded in the `notification_logs` table with a status:
  `sent`, `failed`, `logged`, or `skipped`. This is your primary way to
  troubleshoot.

> **Email has no transport.** There is no email driver or `Mail::` wiring in the
> app; `NotificationTemplate` rows with `channel = email` store subject/body but
> nothing sends them yet.

The exact environment variable names below were read from
`config/messaging.php`, not guessed. Placeholders already exist in `.env.example`.

---

## 2. Twilio SMS (free-text, no template pre-approval needed)

Twilio SMS does **not** require pre-approved message templates — the app sends a
plain `Body` via the standard Messages API. Regulatory/sender registration
requirements for high-volume A2P messaging can apply; see step 3.

1. Create a Twilio account at https://www.twilio.com (trial accounts can only
   message numbers you verified in the console).
2. From the **Twilio Console > Account Info**, copy:
   - **Account SID**
   - **Auth Token**
3. Buy or provision a phone number that is **capable of SMS**:
   Console > Phone Numbers > Buy a Number. Note the number exactly as Twilio
   shows it (E.164 format, e.g. `+15551234567`).
4. Put them in the **real** `.env` (never commit credentials; `.env.example`
   keeps empty placeholders):

   ```dotenv
   TWILIO_SMS_ENABLED=true
   TWILIO_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   TWILIO_AUTH_TOKEN=your_auth_token
   TWILIO_FROM=+15551234567
   ```

5. Clear config cache if present: `php artisan config:clear`.

Practical notes:

- If outgoing SMS are rejected with a 21610 / sender-registration error, the
  number needs A2P 10DLC (US) or the local sender-ID registration for the
  destination country. Twilio's console shows the exact requirement for the
  number you chose.
- Trial accounts: add each real recipient phone to Verified Caller IDs in the
  console, or the request fails with an unverified-number error.

---

## 3. Meta WhatsApp Business Platform

WhatsApp business messaging is a separate platform from personal WhatsApp. The
app talks to Meta's **Cloud API** directly (no Twilio for WhatsApp).

1. **Business verification.** Create a WhatsApp Business account at
   https://business.facebook.com and complete **Meta Business verification**
   (business name, address, legal registration, website). Your account sends to
   live customers only after the business is verified — this review typically
   takes a few days and sometimes weeks.
2. **Create a WhatsApp app.** At https://developers.facebook.com create an app
   and add the **WhatsApp** product to it.
3. **Get a phone number.**
   - In the app's WhatsApp settings, add your business phone number to the
     WhatsApp Business Account (WABA) in **WhatsApp Manager
     (WhatsApp > WhatsApp Manager > Account tools > Phone numbers)**. You can
     use the temporary test number for sandboxing.
   - Note the **Phone number ID** shown in WhatsApp Manager — the app needs this,
     not the phone number itself.
4. **Get a permanent access token.** From WhatsApp Manager > **API Setup &
     System User**, create a **system user** with the `whatsapp_business_messaging`
     and `whatsapp_business_management` permissions and generate a **permanent**
     token. The temporary 24-hour test token works only for sandboxing.
5. Put them in the **real** `.env`:

   ```dotenv
   WHATSAPP_CLOUD_API_ENABLED=true
   WHATSAPP_CLOUD_API_TOKEN=EAAxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   WHATSAPP_CLOUD_API_PHONE_NUMBER_ID=123456789012345
   WHATSAPP_CLOUD_API_VERSION=v19.0
   ```

   `WHATSAPP_CLOUD_API_VERSION` defaults to `v19.0`; only change it if the Graph
   API version you are pinned to differs.

---

## 4. WhatsApp templates — required for proactive messages

**This matters before go-live.** Meta only lets a business send free-form text
within the **24-hour customer-service window** that opens when a customer messages
the business. Appointment reminders, follow-ups, and lab-result notifications are
**business-initiated and outside that window**, so Meta rejects them as plain
text. Proactive WhatsApp messages **must use a pre-approved template**.

### 4.1 Template approval (Meta side)

- Create the template in **WhatsApp Manager > Messaging > Message templates**
  with category **Utility** (reminders fit Utility; marketing content needs the
  Marketing category and stricter compliance apps).
- Submit for review. Typical review turnaround is **~24–72 hours**, but Meta can
  take up to a few days; re-submissions stack the delay. Plan template approval
  **before** flipping `WHATSAPP_CLOUD_API_ENABLED=true`.
- The template **name** you create must match the name the app sends (see below).

### 4.2 How the app sends templates

`WhatsAppCloudApiDriver::sendTemplate()` POSTs `type: template` to
`/v{version}/{phone_number_id}/messages` with:

```
template: { name: <name>, language: { code: <code> }, components: [ body → parameters ] }
```

The app's `NotificationService::sendFromTemplate()` accepts optional
`whatsappTemplateName`, `whatsappTemplateLanguage`, and
`whatsappTemplateParameters`. Parameters are **positional** and map 1:1 to the
`{{1}}`, `{{2}}`, … placeholders in the Meta template body — supply them in the
same order. SMS remains free-text even when template metadata is supplied.

Example call shape (from `NotificationService::sendFromTemplate()`):

```php
$service->sendFromTemplate(
    $user,
    $approvedWhatsAppTemplate,             // NotificationTemplate, channel = whatsapp, is_approved = true
    ['patient_name' => 'Ayesha', 'doctor_name' => 'Dr. Noor', 'appointment_date' => '2026-10-01'],
    whatsappTemplateName: 'appointment_reminder',   // must exist + be approved in WhatsApp Manager
    whatsappTemplateLanguage: 'en',
    whatsappTemplateParameters: ['Ayesha', 'Dr. Noor', '2026-10-01'],  // positionally → {{1}}, {{2}}, {{3}}
);
```

A template that is **not approved** in the app's own review flow is refused
(`only approved notification templates can be sent`) — unapproved copy can never
leave the system. And the WhatsApp template must independently be approved by
**Meta**; a 400 "template not approved" response is recorded as `failed`.

### 4.3 Suggested Meta templates for the seeded app templates

The seeder creates these `notification_templates` rows
(`database/seeders/DatabaseSeeder.php`). A suggested Meta template per WhatsApp
proactive use case is `utility` category:

| App template (channel) | Suggested Meta template name | Meta body placeholders |
|---|---|---|
| `Appointment reminder` (sms) | `appointment_reminder` | `{{1}}` patient name, `{{2}}` doctor name, `{{3}}` date, `{{4}}` time |
| `Lab result ready` (whatsapp) | `lab_result_ready` | `{{1}}` patient name, `{{2}}` test name, `{{3}}` clinic name |
| `Follow-up reminder` (sms) | `follow_up_reminder` | `{{1}}` patient name, `{{2}}` clinic name, `{{3}}` date |

The app's local template body and the Meta template text should match
semantically — the Meta template is what the patient actually receives; the local
row drives approval flow, rendering, and audit.

---

## 5. Flipping channels on safely

1. Add credentials to the **real** `.env` only. `.env.example` keeps empty
   placeholders; never commit real SIDs, tokens, or passwords.
2. Set the enabled flag **after** credentials are present and (for WhatsApp)
   **after** templates are approved: `TWILIO_SMS_ENABLED=true` and/or
   `WHATSAPP_CLOUD_API_ENABLED=true`.
3. `php artisan config:clear` (or `config:cache` in production after the change).
4. Trigger a real test send to a **staff number you control** — e.g.
   `php artisan clinic:send-reminders` for the reminder path, or call
   `NotificationService::sendFromTemplate()` with a `whatsappTemplateName` for
   the template path.
5. Confirm in the database:

   ```sql
   SELECT channel, status, title, created_at
   FROM notification_logs
   ORDER BY id DESC LIMIT 10;
   ```

   - `sent` — provider accepted it; check the actual device.
   - `failed` — provider rejected it (common causes: unverified Twilio recipient,
     Meta template not yet approved, wrong phone number ID/token).
   - `logged` — channel disabled or credentials missing (config reads at request
     time; restart workers after an `.env` change if queues are running).
   - `skipped` — consent opt-out or missing phone, nothing was attempted.

6. Only after a real staff-number test succeeds should the channel be considered
   live for patients. Patients can always opt out via the portal, and opted-out
   deliveries are recorded as `skipped`.

---

## 6. Quick reference — environment variables

| Variable | Required for | Where it goes |
|---|---|---|
| `TWILIO_SMS_ENABLED` | SMS | `.env` → `config/messaging.php` |
| `TWILIO_SID` | SMS | Twilio Console > Account Info |
| `TWILIO_AUTH_TOKEN` | SMS | Twilio Console > Account Info |
| `TWILIO_FROM` | SMS | Twilio Console > Phone Numbers |
| `WHATSAPP_CLOUD_API_ENABLED` | WhatsApp | `.env` → `config/messaging.php` |
| `WHATSAPP_CLOUD_API_TOKEN` | WhatsApp | WhatsApp Manager > API Setup > System User (permanent token) |
| `WHATSAPP_CLOUD_API_PHONE_NUMBER_ID` | WhatsApp | WhatsApp Manager > phone number settings |
| `WHATSAPP_CLOUD_API_VERSION` | WhatsApp | Graph API version, defaults to `v19.0` |